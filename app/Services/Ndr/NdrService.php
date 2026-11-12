<?php

declare(strict_types=1);

namespace App\Services\Ndr;

use App\Services\Catalog\StatusCatalogService;
use App\Services\Delivery\DeliveryService;
use App\Services\Shipment\ShipmentService;
use App\Services\Shipment\ShipmentStatusService;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Non-delivery report (NDR) cases: opened from a failed delivery attempt and
 * resolved by either scheduling a re-attempt or initiating return-to-origin.
 */
class NdrService
{
    /** @var array<string, string> customer response code => label */
    public const CUSTOMER_RESPONSES = [
        'reattempt'      => 'Wants a re-attempt',
        'change_address' => 'Provided a new address / landmark',
        'no_response'    => 'No response from customer',
        'cancel_order'   => 'Cancelled the order',
        'refused'        => 'Refused the shipment',
    ];

    /**
     * Customer responses: database lookup (category ndr_customer_response)
     * with the built-in constant as fallback.
     *
     * @return array<string, string>
     */
    public static function customerResponses(bool $activeOnly = true): array
    {
        return (new StatusCatalogService())->lookupOptionsOr('ndr_customer_response', self::CUSTOMER_RESPONSES, $activeOnly);
    }

    private BaseConnection $db;
    private ShipmentStatusService $status;
    private ShipmentService $shipments;
    private ?DeliveryService $delivery;

    public function __construct(
        ?BaseConnection $db = null,
        ?ShipmentStatusService $status = null,
        ?ShipmentService $shipments = null,
        ?DeliveryService $delivery = null
    ) {
        $this->db        = $db ?? \Config\Database::connect();
        $this->status    = $status ?? new ShipmentStatusService($this->db);
        $this->shipments = $shipments ?? new ShipmentService($this->db, $this->status);
        $this->delivery  = $delivery;
    }

    private function delivery(): DeliveryService
    {
        return $this->delivery ??= new DeliveryService($this->db, $this->status, $this->shipments, $this);
    }

    /**
     * Opens an NDR case for a failed attempt (one open case per shipment).
     *
     * @return array{case_id: int, case_number: string, created: bool}
     */
    public function createFromAttempt(int $orgId, int $shipmentId, ?int $attemptId, string $reasonCode, ?string $remarks = null): array
    {
        $existing = $this->db->table('ndr_cases')
            ->where('organization_id', $orgId)
            ->where('shipment_id', $shipmentId)
            ->where('status', 'open')
            ->get()->getRowArray();

        if ($existing !== null) {
            return ['case_id' => (int) $existing['id'], 'case_number' => (string) $existing['case_number'], 'created' => false];
        }

        $number = $this->nextCaseNumber($orgId);
        $now    = gmdate('Y-m-d H:i:s');

        $this->db->table('ndr_cases')->insert([
            'organization_id'     => $orgId,
            'shipment_id'         => $shipmentId,
            'delivery_attempt_id' => $attemptId,
            'case_number'         => $number,
            'reason_code'         => $reasonCode,
            'status'              => 'open',
            'remarks'             => $remarks !== null && $remarks !== '' ? mb_substr($remarks, 0, 500) : null,
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);

        return ['case_id' => (int) $this->db->insertID(), 'case_number' => $number, 'created' => true];
    }

    /**
     * Resolves an open case.
     *
     * action = reattempt : delivery_failed -> out_for_delivery, optional agent assignment, next attempt date
     * action = rto       : delivery_failed -> rto_initiated
     *
     * @param array<string, mixed> $data action, customer_response, next_attempt_at (local "Y-m-d\TH:i"),
     *                                   delivery_agent_id, remarks
     *
     * @return array{success: bool, message: string}
     */
    public function resolve(int $orgId, int $ndrId, ?int $userId, array $data, string $timezone = 'Asia/Kolkata'): array
    {
        $case = $this->db->table('ndr_cases')
            ->where('id', $ndrId)
            ->where('organization_id', $orgId)
            ->get()->getRowArray();
        if ($case === null) {
            return $this->fail('NDR case not found.');
        }
        if ($case['status'] !== 'open') {
            return $this->fail('This NDR case has already been resolved.');
        }

        $action = (string) ($data['action'] ?? '');
        if (! in_array($action, ['reattempt', 'rto'], true)) {
            return $this->fail('Choose a resolution: re-attempt delivery or return to origin.');
        }

        $response = (string) ($data['customer_response'] ?? ($action === 'reattempt' ? 'reattempt' : 'no_response'));
        if (! array_key_exists($response, self::customerResponses())) {
            return $this->fail('Select the customer response.');
        }

        $remarks = trim((string) ($data['remarks'] ?? ''));
        if (mb_strlen($remarks) > 500) {
            return $this->fail('Remarks cannot exceed 500 characters.');
        }

        $shipment = $this->shipments->find($orgId, (int) $case['shipment_id']);
        if ($shipment === null) {
            return $this->fail('The shipment for this case no longer exists.');
        }
        if ($shipment['current_status'] !== ShipmentStatusService::STATUS_DELIVERY_FAILED) {
            return $this->fail('The shipment is "' . $this->status->defaultTitle((string) $shipment['current_status']) . '", not Delivery Failed, so this case cannot be actioned.');
        }

        $nextAttemptUtc = null;
        $nextAttemptLabel = '';
        if ($action === 'reattempt') {
            $local = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', (string) ($data['next_attempt_at'] ?? ''), new DateTimeZone($timezone));
            if ($local === false) {
                return $this->fail('Enter the date and time of the next delivery attempt.');
            }
            $utc = $local->setTimezone(new DateTimeZone('UTC'));
            if ($utc->getTimestamp() < time() - 300) {
                return $this->fail('The next attempt must be in the future.');
            }
            if ($utc->getTimestamp() > time() + 30 * 86400) {
                return $this->fail('The next attempt must be within 30 days.');
            }
            $nextAttemptUtc   = $utc->format('Y-m-d H:i:s');
            $nextAttemptLabel = $local->format('d M Y, h:i A');
        }

        $now = gmdate('Y-m-d H:i:s');
        $this->db->transBegin();

        try {
            if ($action === 'reattempt') {
                $result = $this->status->transition(
                    (int) $case['shipment_id'],
                    ShipmentStatusService::STATUS_OUT_FOR_DELIVERY,
                    'Re-attempt scheduled',
                    'NDR ' . $case['case_number'] . ': re-attempt planned for ' . $nextAttemptLabel . '.',
                    $userId,
                    null,
                    $orgId
                );
                if (! $result['success']) {
                    $this->db->transRollback();

                    return $this->fail($result['message']);
                }

                $agentId = (int) ($data['delivery_agent_id'] ?? 0);
                if ($agentId > 0) {
                    $assigned = $this->delivery()->assign($orgId, (int) $case['shipment_id'], $agentId, $userId, 'Re-attempt for NDR ' . $case['case_number']);
                    if (! $assigned['success']) {
                        $this->db->transRollback();

                        return $assigned;
                    }
                }
            } else {
                $result = $this->status->transition(
                    (int) $case['shipment_id'],
                    ShipmentStatusService::STATUS_RTO_INITIATED,
                    'RTO initiated',
                    'NDR ' . $case['case_number'] . ': return to origin initiated' . ($remarks !== '' ? ' - ' . $remarks : '.'),
                    $userId,
                    null,
                    $orgId
                );
                if (! $result['success']) {
                    $this->db->transRollback();

                    return $this->fail($result['message']);
                }
            }

            $this->db->table('ndr_cases')->where('id', $ndrId)->update([
                'customer_response' => $response,
                'resolution'        => $action === 'reattempt' ? 'reattempt' : 'rto_initiated',
                'next_attempt_at'   => $nextAttemptUtc,
                'status'            => 'resolved',
                'remarks'           => $remarks !== '' ? $remarks : $case['remarks'],
                'resolved_by'       => $userId,
                'resolved_at'       => $now,
                'updated_at'        => $now,
            ]);

            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            log_message('error', 'NDR resolve failed: {message}', ['message' => $e->getMessage()]);

            return $this->fail('The NDR case could not be resolved.');
        }

        return [
            'success' => true,
            'message' => $action === 'reattempt'
                ? 'Re-attempt scheduled for ' . $nextAttemptLabel . '. The shipment is back Out for Delivery.'
                : 'Return to origin initiated for case ' . $case['case_number'] . '.',
        ];
    }

    private function nextCaseNumber(int $orgId): string
    {
        do {
            $number = 'NDR-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $exists = $this->db->table('ndr_cases')
                ->where('organization_id', $orgId)
                ->where('case_number', $number)
                ->countAllResults() > 0;
        } while ($exists);

        return $number;
    }

    /**
     * @return array{success: false, message: string}
     */
    private function fail(string $message): array
    {
        return ['success' => false, 'message' => $message];
    }
}
