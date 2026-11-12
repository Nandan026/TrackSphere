<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Services\Shipment\ShipmentStatusService;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Read-only reporting queries (tenant-scoped, date-ranged, optional courier filter).
 *
 * Dates arrive as organization-local calendar days (Y-m-d) and are converted to a
 * UTC [start, end) window because every timestamp is stored in UTC.
 * Shipments are selected by creation date.
 */
class ReportService
{
    public const MAX_RANGE_DAYS = 366;

    private const RTO_STATUSES = ['rto_initiated', 'rto_in_transit', 'rto_delivered'];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    // ------------------------------------------------------------------
    // Date range
    // ------------------------------------------------------------------

    /**
     * Validates user supplied dates; falls back to the last 30 days.
     *
     * @return array{from: string, to: string, start_utc: string, end_utc: string, error: string|null}
     */
    public static function resolveRange(?string $from, ?string $to, string $timezone = 'Asia/Kolkata'): array
    {
        try {
            $tz = new DateTimeZone($timezone);
        } catch (\Throwable $e) {
            $tz = new DateTimeZone('Asia/Kolkata');
        }

        $today = new DateTimeImmutable('today', $tz);
        $error = null;

        $parse = static function (?string $value) use ($tz): ?DateTimeImmutable {
            $value = trim((string) $value);
            if ($value === '') {
                return null;
            }
            $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $tz);

            return $dt !== false && $dt->format('Y-m-d') === $value ? $dt : null;
        };

        $fromDt = $parse($from);
        $toDt   = $parse($to);

        if (trim((string) $from) !== '' && $fromDt === null) {
            $error = 'The "from" date is invalid; using the default range.';
        }
        if (trim((string) $to) !== '' && $toDt === null) {
            $error = 'The "to" date is invalid; using the default range.';
        }

        $toDt   ??= $today;
        $fromDt ??= $toDt->modify('-29 days');

        if ($fromDt > $toDt) {
            $error  = 'The "from" date was after the "to" date; the dates were swapped.';
            [$fromDt, $toDt] = [$toDt, $fromDt];
        }

        if ($fromDt->diff($toDt)->days >= self::MAX_RANGE_DAYS) {
            $error  = 'The range is limited to ' . self::MAX_RANGE_DAYS . ' days; it was shortened.';
            $fromDt = $toDt->modify('-' . (self::MAX_RANGE_DAYS - 1) . ' days');
        }

        $utc = new DateTimeZone('UTC');

        return [
            'from'      => $fromDt->format('Y-m-d'),
            'to'        => $toDt->format('Y-m-d'),
            'start_utc' => $fromDt->setTime(0, 0)->setTimezone($utc)->format('Y-m-d H:i:s'),
            'end_utc'   => $toDt->modify('+1 day')->setTime(0, 0)->setTimezone($utc)->format('Y-m-d H:i:s'),
            'error'     => $error,
        ];
    }

    // ------------------------------------------------------------------
    // Shipment summary
    // ------------------------------------------------------------------

    /**
     * Shipment counts per status (every known status is present, zero included).
     *
     * @param array{start_utc: string, end_utc: string} $range
     *
     * @return array{total: int, rows: list<array{status: string, label: string, count: int, percent: float, cod_amount: float}>, by_courier: list<array<string, mixed>>}
     */
    public function shipmentSummary(int $orgId, array $range, ?int $courierId = null): array
    {
        $rows = $this->scoped($orgId, $range, $courierId)
            ->select('s.current_status, COUNT(*) AS total, COALESCE(SUM(CASE WHEN s.payment_mode = \'cod\' THEN s.cod_amount ELSE 0 END), 0) AS cod_amount', false)
            ->groupBy('s.current_status')
            ->get()->getResultArray();

        $byStatus = [];
        $total    = 0;
        foreach ($rows as $row) {
            $byStatus[$row['current_status']] = ['count' => (int) $row['total'], 'cod' => (float) $row['cod_amount']];
            $total                           += (int) $row['total'];
        }

        $order = array_keys((new ShipmentStatusService($this->db))->allowedTransitions());
        // Unknown statuses (defensive) go last.
        foreach (array_keys($byStatus) as $code) {
            if (! in_array($code, $order, true)) {
                $order[] = $code;
            }
        }

        $result = [];
        foreach ($order as $code) {
            $count    = $byStatus[$code]['count'] ?? 0;
            $result[] = [
                'status'     => $code,
                'label'      => ucwords(str_replace('_', ' ', $code)),
                'count'      => $count,
                'percent'    => $total > 0 ? round($count / $total * 100, 1) : 0.0,
                'cod_amount' => round($byStatus[$code]['cod'] ?? 0.0, 2),
            ];
        }

        $courierRows = $this->scoped($orgId, $range, $courierId)
            ->select('COALESCE(cp.name, \'Unassigned\') AS courier_name, COUNT(*) AS total,'
                . ' SUM(s.current_status = \'delivered\') AS delivered', false)
            ->join('courier_partners cp', 'cp.id = s.courier_partner_id', 'left')
            ->groupBy('cp.id, cp.name')
            ->orderBy('total', 'DESC')
            ->get()->getResultArray();

        $byCourier = [];
        foreach ($courierRows as $row) {
            $byCourier[] = [
                'courier'   => $row['courier_name'],
                'total'     => (int) $row['total'],
                'delivered' => (int) $row['delivered'],
            ];
        }

        return ['total' => $total, 'rows' => $result, 'by_courier' => $byCourier];
    }

    /**
     * CSV text of the shipment summary (status, shipments, share, COD amount).
     *
     * @param array{start_utc: string, end_utc: string} $range
     */
    public function shipmentSummaryCsv(int $orgId, array $range, ?int $courierId = null): string
    {
        $summary = $this->shipmentSummary($orgId, $range, $courierId);

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['status', 'status_label', 'shipments', 'share_percent', 'cod_amount']);

        $cod = 0.0;
        foreach ($summary['rows'] as $row) {
            fputcsv($handle, [$row['status'], $row['label'], $row['count'], number_format($row['percent'], 1, '.', ''), number_format($row['cod_amount'], 2, '.', '')]);
            $cod += $row['cod_amount'];
        }
        fputcsv($handle, ['total', 'Total', $summary['total'], $summary['total'] > 0 ? '100.0' : '0.0', number_format($cod, 2, '.', '')]);

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    // ------------------------------------------------------------------
    // Delivery performance
    // ------------------------------------------------------------------

    /**
     * Delivery success = delivered / (delivered + failed + RTO) for shipments created in the range.
     * Attempt success = delivered attempts / all attempts made in the range.
     *
     * @param array{start_utc: string, end_utc: string} $range
     *
     * @return array<string, mixed>
     */
    public function deliveryPerformance(int $orgId, array $range, ?int $courierId = null): array
    {
        $row = $this->scoped($orgId, $range, $courierId)
            ->select('SUM(s.current_status = \'delivered\') AS delivered,'
                . ' SUM(s.current_status = \'delivery_failed\') AS failed,'
                . ' SUM(s.current_status IN (\'' . implode("','", self::RTO_STATUSES) . '\')) AS rto,'
                . ' SUM(s.current_status = \'cancelled\') AS cancelled,'
                . ' SUM(s.current_status = \'delivered\' AND s.expected_delivery_at IS NOT NULL AND s.delivered_at <= s.expected_delivery_at) AS on_time,'
                . ' COUNT(*) AS total', false)
            ->get()->getRowArray() ?? [];

        $delivered = (int) ($row['delivered'] ?? 0);
        $failed    = (int) ($row['failed'] ?? 0);
        $rto       = (int) ($row['rto'] ?? 0);
        $onTime    = (int) ($row['on_time'] ?? 0);
        $total     = (int) ($row['total'] ?? 0);
        $cancelled = (int) ($row['cancelled'] ?? 0);

        $attempted = $delivered + $failed + $rto;

        $attemptRow = $this->db->table('delivery_attempts t')
            ->select('COUNT(*) AS total, SUM(t.outcome = \'delivered\') AS ok', false)
            ->join('shipments s', 's.id = t.shipment_id')
            ->where('s.organization_id', $orgId)
            ->where('s.deleted_at', null)
            ->where('t.attempted_at >=', $range['start_utc'])
            ->where('t.attempted_at <', $range['end_utc']);
        if ($courierId !== null) {
            $attemptRow->where('s.courier_partner_id', $courierId);
        }
        $attemptRow = $attemptRow->get()->getRowArray() ?? [];

        $attempts   = (int) ($attemptRow['total'] ?? 0);
        $attemptsOk = (int) ($attemptRow['ok'] ?? 0);

        return [
            'total'              => $total,
            'delivered'          => $delivered,
            'failed'             => $failed,
            'rto'                => $rto,
            'cancelled'          => $cancelled,
            'in_progress'        => max(0, $total - $delivered - $failed - $rto - $cancelled),
            'success_rate'       => $attempted > 0 ? round($delivered / $attempted * 100, 1) : null,
            'on_time_rate'       => $delivered > 0 ? round($onTime / $delivered * 100, 1) : null,
            'attempts'           => $attempts,
            'attempts_delivered' => $attemptsOk,
            'attempts_failed'    => $attempts - $attemptsOk,
            'attempt_rate'       => $attempts > 0 ? round($attemptsOk / $attempts * 100, 1) : null,
        ];
    }

    // ------------------------------------------------------------------
    // COD
    // ------------------------------------------------------------------

    /**
     * COD exposure for COD shipments created in the range, plus remittance position.
     *
     * @param array{start_utc: string, end_utc: string} $range
     *
     * @return array<string, mixed>
     */
    public function codSummary(int $orgId, array $range, ?int $courierId = null): array
    {
        $rto = "'" . implode("','", self::RTO_STATUSES) . "'";

        $row = $this->scoped($orgId, $range, $courierId)
            ->select('COUNT(*) AS shipments,'
                . ' COALESCE(SUM(s.cod_amount), 0) AS total,'
                . ' COALESCE(SUM(CASE WHEN s.current_status = \'delivered\' THEN s.cod_amount ELSE 0 END), 0) AS collected,'
                . ' COALESCE(SUM(CASE WHEN s.current_status IN (' . $rto . ') THEN s.cod_amount ELSE 0 END), 0) AS returned,'
                . ' COALESCE(SUM(CASE WHEN s.current_status NOT IN (\'delivered\', \'cancelled\', ' . $rto . ') THEN s.cod_amount ELSE 0 END), 0) AS pending,'
                . ' SUM(s.current_status = \'delivered\') AS collected_count', false)
            ->where('s.payment_mode', 'cod')
            ->where('s.current_status !=', 'cancelled')
            ->get()->getRowArray() ?? [];

        $byCourierRows = $this->scoped($orgId, $range, $courierId)
            ->select('COALESCE(cp.name, \'Unassigned\') AS courier_name, COUNT(*) AS shipments,'
                . ' COALESCE(SUM(s.cod_amount), 0) AS total,'
                . ' COALESCE(SUM(CASE WHEN s.current_status = \'delivered\' THEN s.cod_amount ELSE 0 END), 0) AS collected', false)
            ->join('courier_partners cp', 'cp.id = s.courier_partner_id', 'left')
            ->where('s.payment_mode', 'cod')
            ->where('s.current_status !=', 'cancelled')
            ->groupBy('cp.id, cp.name')
            ->orderBy('collected', 'DESC')
            ->get()->getResultArray();

        $byCourier = [];
        foreach ($byCourierRows as $r) {
            $byCourier[] = [
                'courier'   => $r['courier_name'],
                'shipments' => (int) $r['shipments'],
                'total'     => round((float) $r['total'], 2),
                'collected' => round((float) $r['collected'], 2),
            ];
        }

        $remitted = $this->db->table('cod_remittances')
            ->select('COUNT(*) AS n, COALESCE(SUM(net_amount), 0) AS net', false)
            ->where('organization_id', $orgId)
            ->where('status', 'remitted')
            ->where('remitted_at >=', $range['start_utc'])
            ->where('remitted_at <', $range['end_utc']);
        $awaiting = $this->db->table('cod_remittances')
            ->select('COUNT(*) AS n, COALESCE(SUM(net_amount), 0) AS net', false)
            ->where('organization_id', $orgId)
            ->where('status', 'pending');
        if ($courierId !== null) {
            $remitted->where('courier_partner_id', $courierId);
            $awaiting->where('courier_partner_id', $courierId);
        }
        $remitted = $remitted->get()->getRowArray() ?? [];
        $awaiting = $awaiting->get()->getRowArray() ?? [];

        return [
            'shipments'          => (int) ($row['shipments'] ?? 0),
            'total'              => round((float) ($row['total'] ?? 0), 2),
            'collected'          => round((float) ($row['collected'] ?? 0), 2),
            'collected_count'    => (int) ($row['collected_count'] ?? 0),
            'pending'            => round((float) ($row['pending'] ?? 0), 2),
            'returned'           => round((float) ($row['returned'] ?? 0), 2),
            'by_courier'         => $byCourier,
            'remitted_count'     => (int) ($remitted['n'] ?? 0),
            'remitted_net'       => round((float) ($remitted['net'] ?? 0), 2),
            'awaiting_count'     => (int) ($awaiting['n'] ?? 0),
            'awaiting_net'       => round((float) ($awaiting['net'] ?? 0), 2),
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Base shipments query: this organization, not deleted, created inside the range.
     *
     * @param array{start_utc: string, end_utc: string} $range
     *
     * @return \CodeIgniter\Database\BaseBuilder
     */
    private function scoped(int $orgId, array $range, ?int $courierId)
    {
        $builder = $this->db->table('shipments s')
            ->where('s.organization_id', $orgId)
            ->where('s.deleted_at', null)
            ->where('s.created_at >=', $range['start_utc'])
            ->where('s.created_at <', $range['end_utc']);

        if ($courierId !== null && $courierId > 0) {
            $builder->where('s.courier_partner_id', $courierId);
        }

        return $builder;
    }
}
