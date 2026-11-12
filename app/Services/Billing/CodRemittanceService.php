<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Services\Report\ReportService;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;

/**
 * COD remittance batches: what a courier owes the organization for cash collected
 * on delivered COD shipments. Amounts are entered manually (the schema keeps no
 * per-shipment link); suggest() helps by summing delivered COD shipments of a
 * courier inside a period.
 *
 * Status flow: pending -> remitted.
 */
class CodRemittanceService
{
    public const STATUSES = ['pending', 'remitted'];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    /**
     * Active couriers visible to the organization (own + platform-wide).
     *
     * @return list<array<string, mixed>>
     */
    public function couriers(int $orgId): array
    {
        return $this->db->table('courier_partners')
            ->select('id, name, code, supports_cod')
            ->where('status', 'active')
            ->groupStart()->where('organization_id', $orgId)->orWhere('organization_id', null)->groupEnd()
            ->orderBy('name')
            ->get()->getResultArray();
    }

    /**
     * @return array<string, int>
     */
    public function statusCounts(int $orgId): array
    {
        $rows = $this->db->table('cod_remittances')
            ->select('status, COUNT(*) AS total')
            ->where('organization_id', $orgId)
            ->groupBy('status')
            ->get()->getResultArray();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Totals of delivered COD shipments of a courier within a period (delivery date, org timezone).
     *
     * @return array{success: bool, message?: string, count?: int, total?: float, shipments?: list<array<string, mixed>>}
     */
    public function suggest(int $orgId, int $courierId, string $from, string $to, string $timezone): array
    {
        $fromDate = $this->validDate($from);
        $toDate   = $this->validDate($to);
        if ($fromDate === null || $toDate === null || $toDate < $fromDate) {
            return ['success' => false, 'message' => 'Enter a valid period.'];
        }
        if ($this->findCourier($orgId, $courierId) === null) {
            return ['success' => false, 'message' => 'Select a courier.'];
        }

        $range = ReportService::resolveRange($fromDate, $toDate, $timezone);

        $builder = fn () => $this->db->table('shipments s')
            ->where('s.organization_id', $orgId)
            ->where('s.deleted_at', null)
            ->where('s.courier_partner_id', $courierId)
            ->where('s.payment_mode', 'cod')
            ->where('s.current_status', 'delivered')
            ->where('s.delivered_at >=', $range['start_utc'])
            ->where('s.delivered_at <', $range['end_utc']);

        $totals = $builder()->select('COUNT(*) AS n, COALESCE(SUM(s.cod_amount), 0) AS total', false)->get()->getRowArray() ?? [];

        $shipments = $builder()
            ->select('s.id, s.tracking_number, s.cod_amount, s.delivered_at')
            ->orderBy('s.delivered_at', 'DESC')
            ->limit(25)
            ->get()->getResultArray();

        return [
            'success'   => true,
            'count'     => (int) ($totals['n'] ?? 0),
            'total'     => round((float) ($totals['total'] ?? 0), 2),
            'shipments' => $shipments,
        ];
    }

    /**
     * Creates a pending remittance batch.
     *
     * @param array<string, mixed> $input courier_partner_id, period_start, period_end,
     *                                    total_cod_amount, deductions, reference_number
     *
     * @return array{success: bool, message: string, errors?: array<string, string>, remittance_id?: int, remittance_number?: string}
     */
    public function create(int $orgId, array $input): array
    {
        $errors = [];

        $courier = $this->findCourier($orgId, (int) ($input['courier_partner_id'] ?? 0));
        if ($courier === null) {
            $errors['courier_partner_id'] = 'Please select a courier.';
        }

        $start = $this->validDate((string) ($input['period_start'] ?? ''));
        $end   = $this->validDate((string) ($input['period_end'] ?? ''));
        if ($start === null) {
            $errors['period_start'] = 'Enter the period start date.';
        }
        if ($end === null) {
            $errors['period_end'] = 'Enter the period end date.';
        }
        if ($start !== null && $end !== null) {
            if ($end < $start) {
                $errors['period_end'] = 'The period end cannot be before the start.';
            } elseif ((new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days > 366) {
                $errors['period_end'] = 'A remittance period cannot exceed one year.';
            }
        }

        $total = $this->number($input['total_cod_amount'] ?? null);
        if ($total === null || $total <= 0 || $total > 1000000000) {
            $errors['total_cod_amount'] = 'Enter the total COD amount collected (greater than zero).';
        }

        $deductions = $this->number(($input['deductions'] ?? '') === '' ? 0 : $input['deductions']);
        if ($deductions === null || $deductions < 0 || $deductions > 1000000000) {
            $errors['deductions'] = 'Deductions must be zero or more.';
        } elseif ($total !== null && $deductions > $total) {
            $errors['deductions'] = 'Deductions cannot exceed the total COD amount.';
        }

        $reference = trim((string) ($input['reference_number'] ?? ''));
        if (mb_strlen($reference) > 100) {
            $errors['reference_number'] = 'The reference is too long (max 100 characters).';
        }

        // Guard against remitting the same period twice for a courier.
        if ($errors === [] && $courier !== null && $start !== null && $end !== null) {
            $overlap = $this->db->table('cod_remittances')
                ->select('remittance_number, period_start, period_end')
                ->where('organization_id', $orgId)
                ->where('courier_partner_id', (int) $courier['id'])
                ->where('period_start <=', $end)
                ->where('period_end >=', $start)
                ->get(1)->getRowArray();
            if ($overlap !== null) {
                $errors['period_start'] = 'This period overlaps remittance ' . $overlap['remittance_number']
                    . ' (' . $overlap['period_start'] . ' to ' . $overlap['period_end'] . ') for the same courier.';
            }
        }

        if ($errors !== []) {
            return ['success' => false, 'message' => 'Please correct the highlighted fields.', 'errors' => $errors];
        }

        $total      = round((float) $total, 2);
        $deductions = round((float) $deductions, 2);
        $net        = round($total - $deductions, 2);

        try {
            $number = $this->nextNumber($orgId);

            $this->db->table('cod_remittances')->insert([
                'organization_id'    => $orgId,
                'courier_partner_id' => (int) $courier['id'],
                'remittance_number'  => $number,
                'period_start'       => $start,
                'period_end'         => $end,
                'total_cod_amount'   => $total,
                'deductions'         => $deductions,
                'net_amount'         => $net,
                'status'             => 'pending',
                'reference_number'   => $reference !== '' ? $reference : null,
                'created_at'         => gmdate('Y-m-d H:i:s'),
            ]);
            $id = (int) $this->db->insertID();
        } catch (\Throwable $e) {
            log_message('error', 'cod remittance create failed: {message}', ['message' => $e->getMessage()]);

            return ['success' => false, 'message' => 'The remittance could not be created. Please try again.'];
        }

        return [
            'success'           => true,
            'message'           => 'Remittance ' . $number . ' created (net ' . format_money($net) . ').',
            'remittance_id'     => $id,
            'remittance_number' => $number,
        ];
    }

    /**
     * pending -> remitted.
     *
     * @return array{success: bool, message: string}
     */
    public function markRemitted(int $orgId, int $id, ?string $reference = null): array
    {
        $row = $this->db->table('cod_remittances')
            ->where('id', $id)
            ->where('organization_id', $orgId)
            ->get()->getRowArray();

        if ($row === null) {
            return ['success' => false, 'message' => 'Remittance not found.'];
        }
        if ($row['status'] !== 'pending') {
            return ['success' => false, 'message' => 'Only pending remittances can be marked as remitted.'];
        }

        $reference = trim((string) $reference);
        if (mb_strlen($reference) > 100) {
            return ['success' => false, 'message' => 'The reference is too long (max 100 characters).'];
        }

        $update = ['status' => 'remitted', 'remitted_at' => gmdate('Y-m-d H:i:s')];
        if ($reference !== '') {
            $update['reference_number'] = $reference;
        }

        $this->db->table('cod_remittances')
            ->where('id', $id)
            ->where('organization_id', $orgId)
            ->where('status', 'pending')
            ->update($update);

        if ($this->db->affectedRows() < 1) {
            return ['success' => false, 'message' => 'The remittance changed meanwhile. Reload and try again.'];
        }

        return ['success' => true, 'message' => 'Remittance ' . $row['remittance_number'] . ' marked as remitted.'];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function nextNumber(int $orgId): string
    {
        $prefix = 'COD-' . gmdate('Ym') . '-';

        $row = $this->db->table('cod_remittances')
            ->select('remittance_number')
            ->where('organization_id', $orgId)
            ->like('remittance_number', $prefix, 'after')
            ->orderBy('remittance_number', 'DESC')
            ->get(1)->getRowArray();

        $next = $row ? ((int) substr($row['remittance_number'], strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findCourier(int $orgId, int $courierId): ?array
    {
        if ($courierId <= 0) {
            return null;
        }

        return $this->db->table('courier_partners')
            ->where('id', $courierId)
            ->where('status', 'active')
            ->groupStart()->where('organization_id', $orgId)->orWhere('organization_id', null)->groupEnd()
            ->get()->getRowArray();
    }

    private function validDate(string $value): ?string
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $dt !== false && $dt->format('Y-m-d') === $value ? $value : null;
    }

    /**
     * @param mixed $value
     */
    private function number($value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}
