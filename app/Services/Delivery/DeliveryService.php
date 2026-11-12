<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Services\Catalog\StatusCatalogService;
use App\Services\Ndr\NdrService;
use App\Services\Shipment\ShipmentService;
use App\Services\Shipment\ShipmentStatusService;
use CodeIgniter\Database\BaseConnection;

/**
 * Last-mile delivery: agent assignment, out-for-delivery, delivery attempts
 * and proof of delivery.
 *
 * Status changes always go through ShipmentStatusService. Methods that
 * accept $restrictToUserId enforce "an agent may only act on their own
 * assignment" (pass null for dispatchers / managers).
 */
class DeliveryService
{
    public const ACTIVE_ASSIGNMENT = ['assigned', 'accepted'];

    /** @var array<string, string> failure reason code => label */
    public const FAILURE_REASONS = [
        'customer_unavailable' => 'Customer unavailable',
        'phone_unreachable'    => 'Phone unreachable',
        'address_incorrect'    => 'Address incorrect / incomplete',
        'delivery_refused'     => 'Delivery refused',
        'cod_not_ready'        => 'COD amount not ready',
        'area_inaccessible'    => 'Area inaccessible',
        'reschedule_requested' => 'Customer requested reschedule',
        'other'                => 'Other',
    ];

    /** @var array<string, string> */
    public const RECEIVER_RELATIONS = [
        'self'     => 'Self (addressee)',
        'family'   => 'Family member',
        'neighbour' => 'Neighbour',
        'security' => 'Security / reception',
        'colleague' => 'Colleague',
        'other'    => 'Other',
    ];

    /** @var array<string, string> */
    public const VERIFICATION_METHODS = [
        'name'      => 'Receiver name',
        'otp'       => 'OTP verified',
        'signature' => 'Signature',
        'photo'     => 'Photo of parcel',
    ];

    /**
     * Failure reasons: database lookup (category failure_reason) with the
     * built-in constant as fallback. $activeOnly = false also resolves labels
     * of codes that were deactivated after being stored.
     *
     * @return array<string, string>
     */
    public static function failureReasons(bool $activeOnly = true): array
    {
        return (new StatusCatalogService())->lookupOptionsOr('failure_reason', self::FAILURE_REASONS, $activeOnly);
    }

    /**
     * @return array<string, string>
     */
    public static function receiverRelations(bool $activeOnly = true): array
    {
        return (new StatusCatalogService())->lookupOptionsOr('receiver_relation', self::RECEIVER_RELATIONS, $activeOnly);
    }

    /**
     * @return array<string, string>
     */
    public static function verificationMethods(bool $activeOnly = true): array
    {
        return (new StatusCatalogService())->lookupOptionsOr('verification_method', self::VERIFICATION_METHODS, $activeOnly);
    }

    private BaseConnection $db;
    private ShipmentStatusService $status;
    private ShipmentService $shipments;
    private ?NdrService $ndr;

    public function __construct(
        ?BaseConnection $db = null,
        ?ShipmentStatusService $status = null,
        ?ShipmentService $shipments = null,
        ?NdrService $ndr = null
    ) {
        $this->db        = $db ?? \Config\Database::connect();
        $this->status    = $status ?? new ShipmentStatusService($this->db);
        $this->shipments = $shipments ?? new ShipmentService($this->db, $this->status);
        $this->ndr       = $ndr;
    }

    private function ndr(): NdrService
    {
        return $this->ndr ??= new NdrService($this->db, $this->status, $this->shipments, $this);
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    /**
     * Delivery agent row linked to a login user (null for non-agents).
     *
     * @return array<string, mixed>|null
     */
    public function agentForUser(int $orgId, int $userId): ?array
    {
        return $this->db->table('delivery_agents')
            ->where('organization_id', $orgId)
            ->where('user_id', $userId)
            ->get()->getRowArray();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function activeAgents(int $orgId): array
    {
        return $this->db->table('delivery_agents da')
            ->select('da.*, (SELECT COUNT(*) FROM delivery_assignments x WHERE x.delivery_agent_id = da.id'
                . " AND x.status IN ('assigned','accepted')) AS open_tasks", false)
            ->where('da.organization_id', $orgId)
            ->where('da.status', 'active')
            ->orderBy('da.full_name')
            ->get()->getResultArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activeAssignment(int $orgId, int $shipmentId): ?array
    {
        return $this->db->table('delivery_assignments a')
            ->select('a.*, ag.full_name AS agent_name, ag.agent_code, ag.user_id AS agent_user_id')
            ->join('delivery_agents ag', 'ag.id = a.delivery_agent_id', 'left')
            ->where('a.organization_id', $orgId)
            ->where('a.shipment_id', $shipmentId)
            ->whereIn('a.status', self::ACTIVE_ASSIGNMENT)
            ->orderBy('a.id', 'DESC')
            ->get()->getRowArray();
    }

    // ------------------------------------------------------------------
    // Assignment & dispatch
    // ------------------------------------------------------------------

    /**
     * Assigns (or re-assigns) a delivery agent. Optionally dispatches straight away.
     *
     * @return array{success: bool, message: string}
     */
    public function assign(int $orgId, int $shipmentId, int $agentId, ?int $userId, ?string $notes = null, bool $dispatchNow = false): array
    {
        $shipment = $this->shipments->find($orgId, $shipmentId);
        if ($shipment === null) {
            return $this->fail('Shipment not found.');
        }

        $current = (string) $shipment['current_status'];
        if (! in_array($current, [
            ShipmentStatusService::STATUS_REACHED_DESTINATION_HUB,
            ShipmentStatusService::STATUS_OUT_FOR_DELIVERY,
            ShipmentStatusService::STATUS_DELIVERY_FAILED,
        ], true)) {
            return $this->fail('Agents can only be assigned once the shipment has reached the destination hub.');
        }

        $agent = $this->db->table('delivery_agents')
            ->where('id', $agentId)
            ->where('organization_id', $orgId)
            ->where('status', 'active')
            ->get()->getRowArray();
        if ($agent === null) {
            return $this->fail('Select an active delivery agent.');
        }

        $notes = trim((string) $notes);
        if (mb_strlen($notes) > 500) {
            return $this->fail('Notes cannot exceed 500 characters.');
        }

        $now = gmdate('Y-m-d H:i:s');
        $this->db->transBegin();

        try {
            // Close any previous open assignment (re-assignment).
            $this->db->table('delivery_assignments')
                ->where('shipment_id', $shipmentId)
                ->whereIn('status', self::ACTIVE_ASSIGNMENT)
                ->update(['status' => 'cancelled', 'completed_at' => $now]);

            $this->db->table('delivery_assignments')->insert([
                'organization_id'   => $orgId,
                'shipment_id'       => $shipmentId,
                'delivery_agent_id' => $agentId,
                'assigned_by'       => $userId,
                'assigned_at'       => $now,
                'status'            => 'assigned',
                'notes'             => $notes !== '' ? $notes : null,
            ]);

            $this->shipments->addEvent(
                $shipmentId,
                $current,
                'Delivery agent assigned',
                'Assigned to ' . $agent['full_name'] . ' (' . $agent['agent_code'] . ').',
                $this->receiverCity($shipment),
                $userId,
                'system',
                true
            );

            if ($dispatchNow && $current !== ShipmentStatusService::STATUS_OUT_FOR_DELIVERY) {
                $result = $this->markOutForDelivery($orgId, $shipmentId, $userId, null);
                if (! $result['success']) {
                    $this->db->transRollback();

                    return $result;
                }
            }

            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            log_message('error', 'assign failed: {message}', ['message' => $e->getMessage()]);

            return $this->fail('The agent could not be assigned.');
        }

        return [
            'success' => true,
            'message' => $agent['full_name'] . ' assigned to the shipment' . ($dispatchNow ? ' and dispatched for delivery.' : '.'),
        ];
    }

    /**
     * reached_destination_hub | delivery_failed -> out_for_delivery (needs an assigned agent).
     *
     * @return array{success: bool, message: string}
     */
    public function markOutForDelivery(int $orgId, int $shipmentId, ?int $userId, ?int $restrictToUserId): array
    {
        $shipment = $this->shipments->find($orgId, $shipmentId);
        if ($shipment === null) {
            return $this->fail('Shipment not found.');
        }

        $assignment = $this->activeAssignment($orgId, $shipmentId);
        if ($assignment === null) {
            return $this->fail('Assign a delivery agent before sending the shipment out for delivery.');
        }
        if ($restrictToUserId !== null && (int) ($assignment['agent_user_id'] ?? 0) !== $restrictToUserId) {
            return $this->fail('This shipment is assigned to another delivery agent.');
        }

        $this->db->transBegin();

        try {
            $result = $this->status->transition(
                $shipmentId,
                ShipmentStatusService::STATUS_OUT_FOR_DELIVERY,
                'Out for delivery',
                'Handed to ' . $assignment['agent_name'] . ' for delivery.',
                $userId,
                $this->receiverCity($shipment),
                $orgId,
                $restrictToUserId !== null ? 'agent' : 'system'
            );

            if (! $result['success']) {
                $this->db->transRollback();

                return $this->fail($result['message']);
            }

            $this->db->table('delivery_assignments')->where('id', (int) $assignment['id'])->update([
                'status' => 'accepted', 'accepted_at' => $assignment['accepted_at'] ?: gmdate('Y-m-d H:i:s'),
            ]);

            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            log_message('error', 'markOutForDelivery failed: {message}', ['message' => $e->getMessage()]);

            return $this->fail('The shipment could not be sent out for delivery.');
        }

        return ['success' => true, 'message' => 'Shipment is now out for delivery.'];
    }

    // ------------------------------------------------------------------
    // Attempts & proof of delivery
    // ------------------------------------------------------------------

    /**
     * Records one delivery attempt.
     *
     * - failed    : shipment -> delivery_failed, assignment failed, NDR case (optional)
     * - delivered : attempt saved; the caller should redirect to the POD form
     *               (the shipment becomes delivered when the POD is submitted)
     *
     * @return array{success: bool, message: string, outcome?: string, attempt_id?: int, ndr_case?: string|null}
     */
    public function recordAttempt(
        int $orgId,
        int $shipmentId,
        ?int $userId,
        string $outcome,
        ?string $reason,
        ?string $remarks,
        bool $createNdr = true,
        ?int $restrictToUserId = null,
        ?float $latitude = null,
        ?float $longitude = null
    ): array {
        if (! in_array($outcome, ['delivered', 'failed'], true)) {
            return $this->fail('Choose whether the delivery succeeded or failed.');
        }

        $reason  = trim((string) $reason);
        $remarks = trim((string) $remarks);
        $failureReasons = self::failureReasons();
        if ($outcome === 'failed' && ! array_key_exists($reason, $failureReasons)) {
            return $this->fail('Select a failure reason.');
        }
        if (mb_strlen($remarks) > 500) {
            return $this->fail('Remarks cannot exceed 500 characters.');
        }

        $shipment = $this->shipments->find($orgId, $shipmentId);
        if ($shipment === null) {
            return $this->fail('Shipment not found.');
        }
        if ($shipment['current_status'] !== ShipmentStatusService::STATUS_OUT_FOR_DELIVERY) {
            return $this->fail('Attempts can only be recorded while the shipment is Out for Delivery.');
        }

        $assignment = $this->activeAssignment($orgId, $shipmentId);
        if ($restrictToUserId !== null && ($assignment === null || (int) ($assignment['agent_user_id'] ?? 0) !== $restrictToUserId)) {
            return $this->fail('This shipment is not assigned to you.');
        }

        $now = gmdate('Y-m-d H:i:s');
        $this->db->transBegin();

        try {
            $attemptNumber = $this->nextAttemptNumber($shipmentId);

            $this->db->table('delivery_attempts')->insert([
                'shipment_id'            => $shipmentId,
                'delivery_assignment_id' => $assignment['id'] ?? null,
                'attempt_number'         => $attemptNumber,
                'outcome'                => $outcome,
                'failure_reason'         => $outcome === 'failed' ? $reason : null,
                'remarks'                => $remarks !== '' ? $remarks : null,
                'attempted_at'           => $now,
                'latitude'               => $latitude,
                'longitude'              => $longitude,
                'created_at'             => $now,
            ]);
            $attemptId = (int) $this->db->insertID();

            $ndrNumber = null;

            if ($outcome === 'failed') {
                $label       = $failureReasons[$reason];
                $description = $label . ($remarks !== '' ? ' - ' . $remarks : '') . ' (attempt ' . $attemptNumber . ').';

                $result = $this->status->transition(
                    $shipmentId,
                    ShipmentStatusService::STATUS_DELIVERY_FAILED,
                    'Delivery attempt failed',
                    $description,
                    $userId,
                    $this->receiverCity($shipment),
                    $orgId,
                    $restrictToUserId !== null ? 'agent' : 'system'
                );
                if (! $result['success']) {
                    $this->db->transRollback();

                    return $this->fail($result['message']);
                }

                if ($assignment !== null) {
                    $this->db->table('delivery_assignments')->where('id', (int) $assignment['id'])->update([
                        'status' => 'failed', 'completed_at' => $now,
                    ]);
                }

                if ($createNdr) {
                    $ndrNumber = $this->ndr()->createFromAttempt($orgId, $shipmentId, $attemptId, $reason, $remarks !== '' ? $remarks : null)['case_number'] ?? null;
                }
            }

            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            log_message('error', 'recordAttempt failed: {message}', ['message' => $e->getMessage()]);

            return $this->fail('The delivery attempt could not be saved.');
        }

        if ($outcome === 'delivered') {
            return [
                'success'    => true,
                'message'    => 'Delivery recorded. Capture the proof of delivery to complete the shipment.',
                'outcome'    => 'delivered',
                'attempt_id' => $attemptId,
                'ndr_case'   => null,
            ];
        }

        return [
            'success'    => true,
            'message'    => 'Failed attempt recorded' . ($ndrNumber !== null ? '; NDR case ' . $ndrNumber . ' opened.' : '.'),
            'outcome'    => 'failed',
            'attempt_id' => $attemptId,
            'ndr_case'   => $ndrNumber,
        ];
    }

    /**
     * Saves the proof of delivery and moves the shipment to delivered.
     *
     * @param array<string, mixed> $data received_by, receiver_relation, verification_method,
     *                                   verification_reference, notes, cod_collected
     *
     * @return array{success: bool, message: string, errors?: array<string, string>}
     */
    public function submitPod(int $orgId, int $shipmentId, ?int $userId, array $data, ?int $restrictToUserId = null): array
    {
        $errors = [];

        $receivedBy = trim((string) ($data['received_by'] ?? ''));
        if ($receivedBy === '' || mb_strlen($receivedBy) > 150) {
            $errors['received_by'] = 'Enter the name of the person who received the parcel (max 150 characters).';
        }

        $relation = (string) ($data['receiver_relation'] ?? 'self');
        if (! array_key_exists($relation, self::receiverRelations())) {
            $errors['receiver_relation'] = 'Select the receiver relation.';
        }

        $method = (string) ($data['verification_method'] ?? 'name');
        if (! array_key_exists($method, self::verificationMethods())) {
            $errors['verification_method'] = 'Select a verification method.';
        }

        $reference = trim((string) ($data['verification_reference'] ?? ''));
        if ($method === 'otp' && ! preg_match('/^\d{4,6}$/', $reference)) {
            $errors['verification_reference'] = 'Enter the 4-6 digit OTP shared by the receiver.';
        } elseif (mb_strlen($reference) > 100) {
            $errors['verification_reference'] = 'Verification reference is too long.';
        }

        $notes = trim((string) ($data['notes'] ?? ''));
        if (mb_strlen($notes) > 500) {
            $errors['notes'] = 'Notes cannot exceed 500 characters.';
        }

        $shipment = $this->shipments->find($orgId, $shipmentId);
        if ($shipment === null) {
            return $this->fail('Shipment not found.');
        }

        if ($shipment['payment_mode'] === 'cod' && (float) $shipment['cod_amount'] > 0 && empty($data['cod_collected'])) {
            $errors['cod_collected'] = 'Confirm that the COD amount was collected.';
        }

        if ($errors !== []) {
            return ['success' => false, 'message' => 'Please correct the highlighted fields.', 'errors' => $errors];
        }

        if ($shipment['current_status'] !== ShipmentStatusService::STATUS_OUT_FOR_DELIVERY) {
            return $this->fail('Proof of delivery can only be captured while the shipment is Out for Delivery.');
        }

        if ($this->db->table('proof_of_deliveries')->where('shipment_id', $shipmentId)->countAllResults() > 0) {
            return $this->fail('Proof of delivery has already been recorded and cannot be replaced.');
        }

        $assignment = $this->activeAssignment($orgId, $shipmentId);
        if ($restrictToUserId !== null && ($assignment === null || (int) ($assignment['agent_user_id'] ?? 0) !== $restrictToUserId)) {
            return $this->fail('This shipment is not assigned to you.');
        }

        $now = gmdate('Y-m-d H:i:s');
        $this->db->transBegin();

        try {
            // Reuse the "delivered" attempt recorded just before, otherwise create one.
            $attempt = $this->db->table('delivery_attempts')
                ->where('shipment_id', $shipmentId)
                ->where('outcome', 'delivered')
                ->orderBy('attempt_number', 'DESC')
                ->get()->getRowArray();

            if ($attempt === null) {
                $this->db->table('delivery_attempts')->insert([
                    'shipment_id'            => $shipmentId,
                    'delivery_assignment_id' => $assignment['id'] ?? null,
                    'attempt_number'         => $this->nextAttemptNumber($shipmentId),
                    'outcome'                => 'delivered',
                    'remarks'                => $notes !== '' ? $notes : null,
                    'attempted_at'           => $now,
                    'created_at'             => $now,
                ]);
                $attemptId = (int) $this->db->insertID();
            } else {
                $attemptId = (int) $attempt['id'];
            }

            $result = $this->status->transition(
                $shipmentId,
                ShipmentStatusService::STATUS_DELIVERED,
                'Delivered',
                'Delivered to ' . $receivedBy . ' (' . (self::receiverRelations()[$relation] ?? $relation) . ').'
                    . ($shipment['payment_mode'] === 'cod' ? ' COD collected.' : ''),
                $userId,
                $this->receiverCity($shipment),
                $orgId,
                $restrictToUserId !== null ? 'agent' : 'system'
            );
            if (! $result['success']) {
                $this->db->transRollback();

                return $this->fail($result['message']);
            }

            $this->db->table('proof_of_deliveries')->insert([
                'shipment_id'            => $shipmentId,
                'delivery_attempt_id'    => $attemptId,
                'received_by'            => $receivedBy,
                'receiver_relation'      => $relation,
                'verification_method'    => $method,
                'verification_reference' => $reference !== '' ? $reference : null,
                'notes'                  => $notes !== '' ? $notes : null,
                'delivered_at'           => $now,
                'created_at'             => $now,
            ]);

            if ($assignment !== null) {
                $this->db->table('delivery_assignments')->where('id', (int) $assignment['id'])->update([
                    'status'       => 'completed',
                    'completed_at' => $now,
                    'accepted_at'  => $assignment['accepted_at'] ?: $now,
                ]);
            }

            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            log_message('error', 'submitPod failed: {message}', ['message' => $e->getMessage()]);

            return $this->fail('The proof of delivery could not be saved.');
        }

        return ['success' => true, 'message' => 'Proof of delivery saved. Shipment ' . $shipment['tracking_number'] . ' is delivered.'];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function nextAttemptNumber(int $shipmentId): int
    {
        $row = $this->db->table('delivery_attempts')
            ->selectMax('attempt_number', 'max_attempt')
            ->where('shipment_id', $shipmentId)
            ->get()->getRowArray();

        return ((int) ($row['max_attempt'] ?? 0)) + 1;
    }

    /**
     * @param array<string, mixed> $shipment
     */
    private function receiverCity(array $shipment): ?string
    {
        $city = $this->db->table('addresses')->select('city')->where('id', (int) ($shipment['receiver_address_id'] ?? 0))->get()->getRow('city');

        return $city !== null ? (string) $city : null;
    }

    /**
     * @return array{success: false, message: string}
     */
    private function fail(string $message): array
    {
        return ['success' => false, 'message' => $message];
    }
}
