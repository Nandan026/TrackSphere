<?php

declare(strict_types=1);

namespace App\Services\Return;

use App\Services\Catalog\StatusCatalogService;
use App\Services\Shipment\ShipmentService;
use App\Services\Shipment\ShipmentStatusService;
use App\Services\Shipment\TrackingNumberService;
use CodeIgniter\Database\BaseConnection;

/**
 * Customer return requests raised against delivered shipments.
 * requested -> approved | rejected ; approved -> received
 * Approval can optionally create a reverse-shipment stub (draft) that then
 * follows the normal shipment lifecycle.
 */
class ReturnService
{
    /** @var array<string, string> reason code => label */
    public const REASONS = [
        'damaged_item'      => 'Damaged item',
        'wrong_item'        => 'Wrong item delivered',
        'not_as_described'  => 'Not as described',
        'quality_issue'     => 'Quality issue',
        'size_fit_issue'    => 'Size / fit issue',
        'no_longer_needed'  => 'No longer needed',
        'other'             => 'Other',
    ];

    /**
     * Return reasons: database lookup (category return_reason) with the
     * built-in constant as fallback.
     *
     * @return array<string, string>
     */
    public static function reasons(bool $activeOnly = true): array
    {
        return (new StatusCatalogService())->lookupOptionsOr('return_reason', self::REASONS, $activeOnly);
    }

    /** Statuses that block a second return for the same shipment. */
    private const OPEN_STATUSES = ['requested', 'approved'];

    private BaseConnection $db;
    private ShipmentService $shipments;
    private TrackingNumberService $tracking;

    public function __construct(?BaseConnection $db = null, ?ShipmentService $shipments = null, ?TrackingNumberService $tracking = null)
    {
        $this->db        = $db ?? \Config\Database::connect();
        $this->shipments = $shipments ?? new ShipmentService($this->db);
        $this->tracking  = $tracking ?? new TrackingNumberService($this->db);
    }

    /**
     * Shipments that can still have a return raised against them.
     *
     * @return list<array<string, mixed>>
     */
    public function returnableShipments(int $orgId, int $limit = 200): array
    {
        return $this->db->table('shipments s')
            ->select('s.id, s.tracking_number, s.delivered_at, s.declared_value, c.name AS customer_name, ra.contact_name AS receiver_name, ra.city AS receiver_city')
            ->join('customers c', 'c.id = s.customer_id', 'left')
            ->join('addresses ra', 'ra.id = s.receiver_address_id', 'left')
            ->where('s.organization_id', $orgId)
            ->where('s.deleted_at', null)
            ->where('s.current_status', ShipmentStatusService::STATUS_DELIVERED)
            ->where('s.shipment_type', 'forward')
            ->where('NOT EXISTS (SELECT 1 FROM return_requests r WHERE r.shipment_id = s.id'
                . " AND r.status IN ('requested','approved'))", null, false)
            ->orderBy('s.delivered_at', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();
    }

    /**
     * @return array{success: bool, message: string, errors?: array<string, string>, return_id?: int, return_number?: string}
     */
    public function create(int $orgId, int $shipmentId, ?int $userId, string $reasonCode, ?string $details): array
    {
        $errors  = [];
        $reasons = self::reasons();
        if (! array_key_exists($reasonCode, $reasons)) {
            $errors['reason_code'] = 'Select a return reason.';
        }
        $details = trim((string) $details);
        if (mb_strlen($details) > 500) {
            $errors['reason_details'] = 'Details cannot exceed 500 characters.';
        }
        if ($reasonCode === 'other' && $details === '') {
            $errors['reason_details'] = 'Please describe the reason for the return.';
        }

        $shipment = $this->shipments->find($orgId, $shipmentId);
        if ($shipment === null) {
            $errors['shipment_id'] = 'Select a delivered shipment.';
        } elseif ($shipment['current_status'] !== ShipmentStatusService::STATUS_DELIVERED || $shipment['shipment_type'] !== 'forward') {
            $errors['shipment_id'] = 'Returns can only be requested for delivered forward shipments.';
        } elseif ($this->db->table('return_requests')->where('shipment_id', $shipmentId)->whereIn('status', self::OPEN_STATUSES)->countAllResults() > 0) {
            $errors['shipment_id'] = 'An open return request already exists for this shipment.';
        }

        if ($errors !== []) {
            return ['success' => false, 'message' => 'Please correct the highlighted fields.', 'errors' => $errors];
        }

        $number = $this->nextReturnNumber($orgId);
        $now    = gmdate('Y-m-d H:i:s');

        $this->db->transBegin();

        try {
            $this->db->table('return_requests')->insert([
                'organization_id' => $orgId,
                'shipment_id'     => $shipmentId,
                'return_number'   => $number,
                'reason_code'     => $reasonCode,
                'reason_details'  => $details !== '' ? $details : null,
                'status'          => 'requested',
                'requested_by'    => $userId,
                'requested_at'    => $now,
            ]);
            $returnId = (int) $this->db->insertID();

            $this->shipments->addEvent(
                $shipmentId,
                (string) $shipment['current_status'],
                'Return requested',
                'Return ' . $number . ' requested: ' . $reasons[$reasonCode] . '.',
                null,
                $userId,
                'system',
                false
            );

            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            log_message('error', 'return create failed: {message}', ['message' => $e->getMessage()]);

            return ['success' => false, 'message' => 'The return request could not be created.'];
        }

        return [
            'success'       => true,
            'message'       => 'Return request ' . $number . ' created.',
            'return_id'     => $returnId,
            'return_number' => $number,
        ];
    }

    /**
     * Approves a request, optionally creating a reverse-shipment stub.
     *
     * @return array{success: bool, message: string}
     */
    public function approve(int $orgId, int $returnId, ?int $userId, bool $createReverse = false): array
    {
        $return = $this->find($orgId, $returnId);
        if ($return === null) {
            return $this->fail('Return request not found.');
        }
        if ($return['status'] !== 'requested') {
            return $this->fail('Only requested returns can be approved.');
        }

        $original = $this->shipments->find($orgId, (int) $return['shipment_id']);
        if ($original === null) {
            return $this->fail('The original shipment no longer exists.');
        }

        $now = gmdate('Y-m-d H:i:s');
        $this->db->transBegin();

        try {
            $update = ['status' => 'approved', 'approved_by' => $userId, 'approved_at' => $now];
            $note   = '';

            if ($createReverse) {
                $reverse = $this->createReverseShipment($orgId, $original, $return, $userId);
                $update['reverse_shipment_id'] = $reverse['id'];
                $note = ' Reverse shipment ' . $reverse['tracking_number'] . ' created as draft.' . $reverse['warning'];
            }

            $this->db->table('return_requests')->where('id', $returnId)->update($update);

            $this->shipments->addEvent(
                (int) $original['id'],
                (string) $original['current_status'],
                'Return approved',
                'Return ' . $return['return_number'] . ' approved.' . $note,
                null,
                $userId,
                'system',
                false
            );

            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            log_message('error', 'return approve failed: {message}', ['message' => $e->getMessage()]);

            return $this->fail('The return could not be approved.');
        }

        return ['success' => true, 'message' => 'Return ' . $return['return_number'] . ' approved.' . $note];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function reject(int $orgId, int $returnId, ?int $userId, ?string $note = null): array
    {
        $return = $this->find($orgId, $returnId);
        if ($return === null) {
            return $this->fail('Return request not found.');
        }
        if ($return['status'] !== 'requested') {
            return $this->fail('Only requested returns can be rejected.');
        }

        $note    = trim((string) $note);
        $details = (string) ($return['reason_details'] ?? '');
        if ($note !== '') {
            $details = mb_substr(trim($details . ' | Ops note: ' . $note), 0, 500);
        }

        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('return_requests')->where('id', $returnId)->update([
            'status'         => 'rejected',
            'approved_by'    => $userId,
            'approved_at'    => $now,
            'reason_details' => $details !== '' ? $details : null,
        ]);

        $this->shipments->addEvent((int) $return['shipment_id'], ShipmentStatusService::STATUS_DELIVERED, 'Return rejected', 'Return ' . $return['return_number'] . ' was rejected.', null, $userId, 'system', false);

        return ['success' => true, 'message' => 'Return ' . $return['return_number'] . ' rejected.'];
    }

    /**
     * approved -> received (goods are back at the warehouse).
     *
     * @return array{success: bool, message: string}
     */
    public function markReceived(int $orgId, int $returnId, ?int $userId): array
    {
        $return = $this->find($orgId, $returnId);
        if ($return === null) {
            return $this->fail('Return request not found.');
        }
        if ($return['status'] !== 'approved') {
            return $this->fail('Only approved returns can be marked received.');
        }

        $this->db->table('return_requests')->where('id', $returnId)->update([
            'status' => 'received', 'received_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $this->shipments->addEvent((int) $return['shipment_id'], ShipmentStatusService::STATUS_DELIVERED, 'Return received', 'Returned goods for ' . $return['return_number'] . ' received at the warehouse.', null, $userId, 'system', false);

        return ['success' => true, 'message' => 'Return ' . $return['return_number'] . ' marked as received.'];
    }

    // ------------------------------------------------------------------

    /**
     * Draft reverse shipment: addresses swapped, items copied, prepaid, no COD.
     *
     * @param array<string, mixed> $original
     * @param array<string, mixed> $return
     *
     * @return array{id: int, tracking_number: string, warning: string}
     */
    private function createReverseShipment(int $orgId, array $original, array $return, ?int $userId): array
    {
        $now      = gmdate('Y-m-d H:i:s');
        $tracking = $this->tracking->generate();
        $warning  = '';

        $courierId = null;
        if (! empty($original['courier_partner_id'])) {
            $courier = $this->db->table('courier_partners')->where('id', (int) $original['courier_partner_id'])->get()->getRowArray();
            if ($courier !== null && (int) $courier['supports_reverse'] === 1 && $courier['status'] === 'active') {
                $courierId = (int) $courier['id'];
            } else {
                $warning = ' The original courier does not support reverse pickups - assign one that does.';
            }
        }

        $this->db->table('shipments')->insert([
            'organization_id'      => $orgId,
            'customer_id'          => (int) $original['customer_id'],
            'pickup_location_id'   => null,
            'sender_address_id'    => (int) $original['receiver_address_id'], // customer returns the parcel...
            'receiver_address_id'  => (int) $original['sender_address_id'],   // ...back to the sender
            'courier_partner_id'   => $courierId,
            'tracking_number'      => $tracking,
            'shipment_type'        => 'reverse',
            'payment_mode'         => 'prepaid',
            'current_status'       => ShipmentStatusService::STATUS_DRAFT,
            'total_packages'       => (int) $original['total_packages'],
            'actual_weight_kg'     => $original['actual_weight_kg'],
            'volumetric_weight_kg' => $original['volumetric_weight_kg'],
            'chargeable_weight_kg' => $original['chargeable_weight_kg'],
            'declared_value'       => $original['declared_value'],
            'shipping_charge'      => 0,
            'cod_amount'           => 0,
            'currency'             => $original['currency'],
            'special_instructions' => 'Reverse pickup for return ' . $return['return_number'] . ' (original ' . $original['tracking_number'] . ').',
            'expected_delivery_at' => gmdate('Y-m-d H:i:s', strtotime('+7 days')),
            'created_by'           => $userId,
            'created_at'           => $now,
            'updated_at'           => $now,
        ]);
        $reverseId = (int) $this->db->insertID();

        $items = $this->db->table('shipment_items')->where('shipment_id', (int) $original['id'])->get()->getResultArray();
        foreach ($items as $item) {
            unset($item['id']);
            $item['shipment_id'] = $reverseId;
            $item['created_at']  = $now;
            $this->db->table('shipment_items')->insert($item);
        }

        $this->shipments->addEvent(
            $reverseId,
            ShipmentStatusService::STATUS_DRAFT,
            'Reverse shipment created',
            'Created for return ' . $return['return_number'] . ' from shipment ' . $original['tracking_number'] . '.',
            null,
            $userId,
            'system',
            false
        );

        return ['id' => $reverseId, 'tracking_number' => $tracking, 'warning' => $warning];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(int $orgId, int $returnId): ?array
    {
        return $this->db->table('return_requests')
            ->where('id', $returnId)
            ->where('organization_id', $orgId)
            ->get()->getRowArray();
    }

    private function nextReturnNumber(int $orgId): string
    {
        do {
            $number = 'RET-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $exists = $this->db->table('return_requests')
                ->where('organization_id', $orgId)
                ->where('return_number', $number)
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
