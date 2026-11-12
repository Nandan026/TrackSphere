<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Shipment lifecycle orchestration for the web UI:
 * creation (with addresses, items and first event), ready / pickup /
 * courier / generic status steps and cancellation.
 *
 * Every public method is tenant-scoped (organization id is an argument,
 * resolved by controllers from the logged-in user) and returns a plain
 * result array: ['success' => bool, 'message' => string, ...].
 * All status changes go through ShipmentStatusService.
 */
class ShipmentService
{
    public const PICKUP_SLOTS = ['09:00-12:00', '12:00-15:00', '15:00-18:00', '18:00-20:00'];

    public const PAYMENT_MODES = ['prepaid', 'cod'];

    /**
     * Statuses a user may set from the shipment page via "advance".
     * (delivered / delivery_failed / out_for_delivery / cancelled / ready / pickup scheduled
     * have their own dedicated flows.)
     */
    public const ADVANCE_TARGETS = [
        ShipmentStatusService::STATUS_PICKED_UP,
        ShipmentStatusService::STATUS_PICKUP_FAILED,
        ShipmentStatusService::STATUS_IN_TRANSIT,
        ShipmentStatusService::STATUS_REACHED_DESTINATION_HUB,
        ShipmentStatusService::STATUS_RTO_IN_TRANSIT,
        ShipmentStatusService::STATUS_RTO_DELIVERED,
    ];

    private const MAX_ITEMS = 50;

    private BaseConnection $db;
    private ShipmentStatusService $status;
    private TrackingNumberService $tracking;
    private WeightService $weights;

    public function __construct(
        ?BaseConnection $db = null,
        ?ShipmentStatusService $status = null,
        ?TrackingNumberService $tracking = null,
        ?WeightService $weights = null
    ) {
        $this->db       = $db ?? \Config\Database::connect();
        $this->status   = $status ?? new ShipmentStatusService($this->db);
        $this->tracking = $tracking ?? new TrackingNumberService($this->db);
        $this->weights  = $weights ?? new WeightService();
    }

    public function statusService(): ShipmentStatusService
    {
        return $this->status;
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $orgId, int $shipmentId): ?array
    {
        return $this->db->table('shipments')
            ->where('id', $shipmentId)
            ->where('organization_id', $orgId)
            ->where('deleted_at', null)
            ->get()->getRowArray();
    }

    /**
     * Everything the shipment detail page needs.
     *
     * @return array<string, mixed>|null
     */
    public function details(int $orgId, int $shipmentId): ?array
    {
        $shipment = $this->db->table('shipments s')
            ->select('s.*, c.name AS customer_name, c.customer_code, c.phone AS customer_phone, c.email AS customer_email,'
                . ' cp.name AS courier_name, cp.code AS courier_code, cp.supports_cod, cp.supports_reverse,'
                . ' pl.name AS pickup_location_name, pl.location_code AS pickup_location_code,'
                . ' u.first_name AS creator_first, u.last_name AS creator_last')
            ->join('customers c', 'c.id = s.customer_id', 'left')
            ->join('courier_partners cp', 'cp.id = s.courier_partner_id', 'left')
            ->join('pickup_locations pl', 'pl.id = s.pickup_location_id', 'left')
            ->join('users u', 'u.id = s.created_by', 'left')
            ->where('s.id', $shipmentId)
            ->where('s.organization_id', $orgId)
            ->where('s.deleted_at', null)
            ->get()->getRowArray();

        if ($shipment === null) {
            return null;
        }

        $addresses = $this->db->table('addresses')
            ->whereIn('id', [(int) $shipment['sender_address_id'], (int) $shipment['receiver_address_id']])
            ->get()->getResultArray();
        $byId = [];
        foreach ($addresses as $address) {
            $byId[(int) $address['id']] = $address;
        }

        $pickups = $this->db->table('pickup_requests pr')
            ->select('pr.*, pl.name AS pickup_location_name')
            ->join('pickup_locations pl', 'pl.id = pr.pickup_location_id', 'left')
            ->where('pr.shipment_id', $shipmentId)
            ->orderBy('pr.id', 'DESC')
            ->get()->getResultArray();

        $activePickup = null;
        foreach ($pickups as $pickup) {
            if (in_array($pickup['status'], ['requested', 'scheduled'], true)) {
                $activePickup = $pickup;
                break;
            }
        }

        $assignments = $this->db->table('delivery_assignments da')
            ->select('da.*, ag.full_name AS agent_name, ag.agent_code, ag.phone AS agent_phone, ag.vehicle_type, ag.vehicle_number')
            ->join('delivery_agents ag', 'ag.id = da.delivery_agent_id', 'left')
            ->where('da.shipment_id', $shipmentId)
            ->orderBy('da.id', 'DESC')
            ->get()->getResultArray();

        $activeAssignment = null;
        foreach ($assignments as $assignment) {
            if (in_array($assignment['status'], ['assigned', 'accepted'], true)) {
                $activeAssignment = $assignment;
                break;
            }
        }

        return [
            'shipment'         => $shipment,
            'sender'           => $byId[(int) $shipment['sender_address_id']] ?? null,
            'receiver'         => $byId[(int) $shipment['receiver_address_id']] ?? null,
            'items'            => $this->db->table('shipment_items')->where('shipment_id', $shipmentId)->orderBy('id')->get()->getResultArray(),
            'events'           => $this->db->table('shipment_events e')
                ->select('e.*, u.first_name, u.last_name')
                ->join('users u', 'u.id = e.created_by', 'left')
                ->where('e.shipment_id', $shipmentId)
                ->orderBy('e.occurred_at', 'DESC')
                ->orderBy('e.id', 'DESC')
                ->get()->getResultArray(),
            'documents'        => $this->db->table('shipment_documents')->where('shipment_id', $shipmentId)->orderBy('id', 'DESC')->get()->getResultArray(),
            'pickups'          => $pickups,
            'activePickup'     => $activePickup,
            'assignments'      => $assignments,
            'activeAssignment' => $activeAssignment,
            'attempts'         => $this->db->table('delivery_attempts')->where('shipment_id', $shipmentId)->orderBy('attempt_number', 'DESC')->get()->getResultArray(),
            'pod'              => $this->db->table('proof_of_deliveries')->where('shipment_id', $shipmentId)->get()->getRowArray(),
            'ndrCases'         => $this->db->table('ndr_cases')->where('shipment_id', $shipmentId)->where('organization_id', $orgId)->orderBy('id', 'DESC')->get()->getResultArray(),
            'returns'          => $this->db->table('return_requests')->where('shipment_id', $shipmentId)->where('organization_id', $orgId)->orderBy('id', 'DESC')->get()->getResultArray(),
        ];
    }

    /**
     * Which action buttons make sense for the shipment's current status.
     *
     * @param array<string, mixed>      $shipment
     * @param array<string, mixed>|null $activePickup
     *
     * @return array<string, mixed>
     */
    public function availableActions(array $shipment, ?array $activePickup): array
    {
        $current = (string) $shipment['current_status'];

        return [
            'ready'          => $current === ShipmentStatusService::STATUS_DRAFT,
            'book_pickup'    => $current === ShipmentStatusService::STATUS_READY_TO_SHIP
                || ($current === ShipmentStatusService::STATUS_PICKUP_SCHEDULED && $activePickup === null),
            'is_reschedule'  => $current === ShipmentStatusService::STATUS_PICKUP_SCHEDULED && $activePickup === null,
            'assign_courier' => in_array($current, [
                ShipmentStatusService::STATUS_DRAFT,
                ShipmentStatusService::STATUS_READY_TO_SHIP,
                ShipmentStatusService::STATUS_PICKUP_SCHEDULED,
            ], true),
            'advance'        => array_values(array_intersect(self::ADVANCE_TARGETS, $this->status->nextStatuses($current))),
            'cancel'         => $this->status->canTransition($current, ShipmentStatusService::STATUS_CANCELLED),
            'to_delivery'    => in_array($current, [
                ShipmentStatusService::STATUS_REACHED_DESTINATION_HUB,
                ShipmentStatusService::STATUS_OUT_FOR_DELIVERY,
            ], true),
            'to_ndr'         => $current === ShipmentStatusService::STATUS_DELIVERY_FAILED,
            'to_return'      => $current === ShipmentStatusService::STATUS_DELIVERED && ($shipment['shipment_type'] ?? 'forward') === 'forward',
        ];
    }

    // ------------------------------------------------------------------
    // Create
    // ------------------------------------------------------------------

    /**
     * Creates a draft shipment (addresses, items, weights and the first
     * timeline event) in a single transaction.
     *
     * @param array<string, mixed> $input Raw POST data.
     *
     * @return array{success: bool, message: string, errors?: array<string, string>, shipment_id?: int, tracking_number?: string}
     */
    public function createShipment(array $input, int $orgId, ?int $userId): array
    {
        $errors = [];

        // ---- Customer / pickup location -------------------------------
        $customer = $this->db->table('customers')
            ->where('id', (int) ($input['customer_id'] ?? 0))
            ->where('organization_id', $orgId)
            ->where('status', 'active')
            ->where('deleted_at', null)
            ->get()->getRowArray();
        if ($customer === null) {
            $errors['customer_id'] = 'Please select an active customer.';
        }

        $pickup = $this->db->table('pickup_locations pl')
            ->select('pl.*, a.city AS city, a.postal_code AS postal_code')
            ->join('addresses a', 'a.id = pl.address_id', 'left')
            ->where('pl.id', (int) ($input['pickup_location_id'] ?? 0))
            ->where('pl.organization_id', $orgId)
            ->where('pl.status', 'active')
            ->get()->getRowArray();
        if ($pickup === null) {
            $errors['pickup_location_id'] = 'Please select an active pickup location.';
        }

        // ---- Addresses --------------------------------------------------
        $senderMode = (string) ($input['sender_mode'] ?? 'pickup');
        $senderId   = null;
        $senderNew  = null;
        if ($senderMode === 'existing') {
            $senderId = $this->ownedAddressId($orgId, (int) ($input['sender_address_id'] ?? 0));
            if ($senderId === null) {
                $errors['sender_address_id'] = 'Please select a saved sender address.';
            }
        } elseif ($senderMode === 'new') {
            $senderNew = $this->validateAddress((array) ($input['sender_new'] ?? []), 'Sender', 'sender_new', $errors);
        } else {
            $senderId = $pickup !== null ? (int) $pickup['address_id'] : null; // sender = pickup location address
        }

        $receiverMode = (string) ($input['receiver_mode'] ?? 'existing');
        $receiverId   = null;
        $receiverNew  = null;
        if ($receiverMode === 'new') {
            $receiverNew = $this->validateAddress((array) ($input['receiver_new'] ?? []), 'Receiver', 'receiver_new', $errors);
        } else {
            $receiverId = $this->ownedAddressId($orgId, (int) ($input['receiver_address_id'] ?? 0));
            if ($receiverId === null) {
                $errors['receiver_address_id'] = 'Please select a saved receiver address or enter a new one.';
            }
        }

        // ---- Package / weight --------------------------------------------
        $length = $this->number($input['length_cm'] ?? null);
        $width  = $this->number($input['width_cm'] ?? null);
        $height = $this->number($input['height_cm'] ?? null);
        $actual = $this->number($input['actual_weight_kg'] ?? null);

        foreach (['length_cm' => [$length, 'Length'], 'width_cm' => [$width, 'Width'], 'height_cm' => [$height, 'Height']] as $field => [$value, $label]) {
            if ($value === null || $value <= 0 || $value > 500) {
                $errors[$field] = $label . ' must be between 0.01 and 500 cm.';
            }
        }
        if ($actual === null || $actual <= 0 || $actual > 1000) {
            $errors['actual_weight_kg'] = 'Actual weight must be between 0.001 and 1000 kg.';
        }

        $packages = (int) ($input['total_packages'] ?? 1);
        if ($packages < 1 || $packages > 100) {
            $errors['total_packages'] = 'Number of packages must be between 1 and 100.';
        }

        // ---- Payment -----------------------------------------------------
        $payment = (string) ($input['payment_mode'] ?? 'prepaid');
        if (! in_array($payment, self::PAYMENT_MODES, true)) {
            $errors['payment_mode'] = 'Choose Prepaid or COD.';
            $payment = 'prepaid';
        }

        $codAmount = 0.0;
        if ($payment === 'cod') {
            $cod = $this->number($input['cod_amount'] ?? null);
            if ($cod === null || $cod <= 0 || $cod > 10000000) {
                $errors['cod_amount'] = 'Enter the COD amount to collect (greater than zero).';
            } else {
                $codAmount = round($cod, 2);
            }
        }

        // ---- Courier -------------------------------------------------------
        $courier   = null;
        $courierId = (int) ($input['courier_partner_id'] ?? 0);
        if ($courierId > 0) {
            $courier = $this->findCourier($orgId, $courierId);
            if ($courier === null) {
                $errors['courier_partner_id'] = 'The selected courier is not available.';
            } elseif ($payment === 'cod' && (int) $courier['supports_cod'] !== 1) {
                $errors['courier_partner_id'] = $courier['name'] . ' does not support cash on delivery.';
            }
        }

        // ---- Items ------------------------------------------------------------
        [$items, $itemsTotal] = $this->validateItems((array) ($input['items'] ?? []), $errors);

        $declared = $this->number($input['declared_value'] ?? null);
        if ($declared !== null && ($declared < 0 || $declared > 100000000)) {
            $errors['declared_value'] = 'Declared value is out of range.';
        }
        $declared = $declared !== null && $declared >= 0 ? round($declared, 2) : round($itemsTotal, 2);

        $instructions = trim((string) ($input['special_instructions'] ?? ''));
        if (mb_strlen($instructions) > 500) {
            $errors['special_instructions'] = 'Special instructions cannot exceed 500 characters.';
        }

        if ($errors !== []) {
            return ['success' => false, 'message' => 'Please correct the highlighted fields.', 'errors' => $errors];
        }

        // ---- Compute weights + charges ----------------------------------------
        $volumetric = $this->weights->volumetricWeight((float) $length, (float) $width, (float) $height);
        $chargeable = $this->weights->chargeableWeight((float) $actual, $volumetric);

        $slaDays = 5;
        if ($courier !== null && ! empty($courier['configuration'])) {
            $config = json_decode((string) $courier['configuration'], true);
            if (is_array($config) && isset($config['sla_days']) && (int) $config['sla_days'] > 0) {
                $slaDays = (int) $config['sla_days'];
            }
        }

        $now = gmdate('Y-m-d H:i:s');

        $this->db->transBegin();

        try {
            if ($senderNew !== null) {
                $senderNew['customer_id']  = (int) $customer['id'];
                $senderNew['address_type'] = 'pickup';
                $senderId                  = $this->insertAddress($orgId, $senderNew);
            }
            if ($receiverNew !== null) {
                $receiverNew['customer_id']  = null;
                $receiverNew['address_type'] = 'delivery';
                $receiverId                  = $this->insertAddress($orgId, $receiverNew);
            }

            $destinationPostal = (string) ($receiverNew['postal_code']
                ?? $this->db->table('addresses')->select('postal_code')->where('id', $receiverId)->get()->getRow('postal_code'));

            $charge = $courier !== null
                ? $this->estimateCharge($orgId, (int) $courier['id'], $chargeable, $payment === 'cod', (string) $pickup['postal_code'], $destinationPostal)
                : 0.0;

            $trackingNumber = $this->tracking->generate();

            $this->db->table('shipments')->insert([
                'organization_id'      => $orgId,
                'customer_id'          => (int) $customer['id'],
                'pickup_location_id'   => (int) $pickup['id'],
                'sender_address_id'    => $senderId,
                'receiver_address_id'  => $receiverId,
                'courier_partner_id'   => $courier['id'] ?? null,
                'tracking_number'      => $trackingNumber,
                'shipment_type'        => 'forward',
                'payment_mode'         => $payment,
                'current_status'       => ShipmentStatusService::STATUS_DRAFT,
                'total_packages'       => $packages,
                'actual_weight_kg'     => round((float) $actual, 3),
                'volumetric_weight_kg' => $volumetric,
                'chargeable_weight_kg' => $chargeable,
                'declared_value'       => $declared,
                'shipping_charge'      => $charge,
                'cod_amount'           => $codAmount,
                'currency'             => 'INR',
                'special_instructions' => $instructions !== '' ? $instructions : null,
                'expected_delivery_at' => gmdate('Y-m-d H:i:s', strtotime('+' . $slaDays . ' days')),
                'created_by'           => $userId,
                'created_at'           => $now,
                'updated_at'           => $now,
            ]);
            $shipmentId = (int) $this->db->insertID();

            foreach ($items as $item) {
                $this->db->table('shipment_items')->insert($item + ['shipment_id' => $shipmentId, 'created_at' => $now]);
            }

            $this->addEvent(
                $shipmentId,
                ShipmentStatusService::STATUS_DRAFT,
                'Shipment created',
                sprintf('Draft created. Package %s x %s x %s cm, chargeable weight %s kg.', $this->plain($length), $this->plain($width), $this->plain($height), $this->plain($chargeable)),
                (string) ($pickup['city'] ?? ''),
                $userId,
                'system',
                false
            );

            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            log_message('error', 'createShipment failed: {message}', ['message' => $e->getMessage()]);

            return ['success' => false, 'message' => 'The shipment could not be created. Please try again.'];
        }

        return [
            'success'         => true,
            'message'         => 'Shipment ' . $trackingNumber . ' created as draft.',
            'shipment_id'     => $shipmentId,
            'tracking_number' => $trackingNumber,
        ];
    }

    /**
     * Estimates freight from the matching active rate card:
     * (base + per_kg * chargeable weight) * (1 + fuel%) + COD charge.
     * Returns 0.0 when no rate card applies.
     */
    public function estimateCharge(int $orgId, int $courierId, float $chargeableKg, bool $isCod, string $originPostal, string $destinationPostal): float
    {
        $today = gmdate('Y-m-d');

        $cards = $this->db->table('rate_cards')
            ->where('organization_id', $orgId)
            ->where('courier_partner_id', $courierId)
            ->where('status', 'active')
            ->where('effective_from <=', $today)
            ->groupStart()->where('effective_to', null)->orWhere('effective_to >=', $today)->groupEnd()
            ->where('min_weight_kg <=', $chargeableKg)
            ->groupStart()->where('max_weight_kg', null)->orWhere('max_weight_kg >=', $chargeableKg)->groupEnd()
            ->get()->getResultArray();

        $best      = null;
        $bestScore = -1;
        foreach ($cards as $card) {
            $originPrefix = (string) ($card['origin_postal_prefix'] ?? '');
            $destPrefix   = (string) ($card['destination_postal_prefix'] ?? '');

            if ($originPrefix !== '' && ! str_starts_with($originPostal, $originPrefix)) {
                continue;
            }
            if ($destPrefix !== '' && ! str_starts_with($destinationPostal, $destPrefix)) {
                continue;
            }

            $score = strlen($originPrefix) + strlen($destPrefix); // most specific card wins
            if ($score > $bestScore) {
                $best      = $card;
                $bestScore = $score;
            }
        }

        if ($best === null) {
            return 0.0;
        }

        $charge = ((float) $best['base_charge'] + (float) $best['per_kg_charge'] * $chargeableKg)
            * (1 + (float) $best['fuel_surcharge_percent'] / 100);
        if ($isCod) {
            $charge += (float) $best['cod_charge'];
        }

        return round($charge, 2);
    }

    // ------------------------------------------------------------------
    // Lifecycle actions
    // ------------------------------------------------------------------

    /**
     * draft -> ready_to_ship
     *
     * @return array{success: bool, message: string}
     */
    public function markReady(int $orgId, int $shipmentId, ?int $userId): array
    {
        $shipment = $this->find($orgId, $shipmentId);
        if ($shipment === null) {
            return $this->fail('Shipment not found.');
        }

        $result = $this->status->transition(
            $shipmentId,
            ShipmentStatusService::STATUS_READY_TO_SHIP,
            'Ready to ship',
            'Shipment packed and ready for pickup.',
            $userId,
            $this->senderCity($shipment),
            $orgId
        );

        return $result['success']
            ? ['success' => true, 'message' => 'Shipment marked ready to ship.']
            : $this->fail($result['message']);
    }

    /**
     * Books a pickup (ready_to_ship -> pickup_scheduled), or re-books one when the
     * shipment is already pickup_scheduled but its previous request was cancelled.
     *
     * @param array<string, mixed> $data pickup_location_id, requested_date, time_slot, courier_partner_id, remarks
     *
     * @return array{success: bool, message: string}
     */
    public function bookPickup(int $orgId, int $shipmentId, ?int $userId, array $data, string $timezone = 'Asia/Kolkata'): array
    {
        $shipment = $this->find($orgId, $shipmentId);
        if ($shipment === null) {
            return $this->fail('Shipment not found.');
        }

        $current      = (string) $shipment['current_status'];
        $hasActive    = $this->db->table('pickup_requests')->where('shipment_id', $shipmentId)->whereIn('status', ['requested', 'scheduled'])->countAllResults() > 0;
        $isReschedule = $current === ShipmentStatusService::STATUS_PICKUP_SCHEDULED && ! $hasActive;

        if ($current !== ShipmentStatusService::STATUS_READY_TO_SHIP && ! $isReschedule) {
            return $this->fail($hasActive
                ? 'This shipment already has an active pickup request. Cancel it first to reschedule.'
                : 'A pickup can only be booked for shipments that are Ready to Ship.');
        }

        // Pickup location
        $locationId = (int) ($data['pickup_location_id'] ?? $shipment['pickup_location_id'] ?? 0);
        $location   = $this->db->table('pickup_locations pl')
            ->select('pl.*, a.city')
            ->join('addresses a', 'a.id = pl.address_id', 'left')
            ->where('pl.id', $locationId)
            ->where('pl.organization_id', $orgId)
            ->where('pl.status', 'active')
            ->get()->getRowArray();
        if ($location === null) {
            return $this->fail('Select an active pickup location.');
        }

        // Date + slot
        $date = (string) ($data['requested_date'] ?? '');
        $dt   = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($dt === false || $dt->format('Y-m-d') !== $date) {
            return $this->fail('Enter a valid pickup date.');
        }
        $today = (new DateTimeImmutable('today', new DateTimeZone($timezone)))->format('Y-m-d');
        if ($date < $today) {
            return $this->fail('The pickup date cannot be in the past.');
        }
        if ($date > gmdate('Y-m-d', strtotime($today . ' +60 days'))) {
            return $this->fail('Pickups can be booked up to 60 days ahead.');
        }

        $slot = (string) ($data['time_slot'] ?? '');
        if (! in_array($slot, self::PICKUP_SLOTS, true)) {
            return $this->fail('Select a pickup time slot.');
        }

        $remarks = trim((string) ($data['remarks'] ?? ''));
        if (mb_strlen($remarks) > 500) {
            return $this->fail('Remarks cannot exceed 500 characters.');
        }

        // Courier (required before pickup)
        $courierId = (int) ($data['courier_partner_id'] ?? 0) ?: (int) ($shipment['courier_partner_id'] ?? 0);
        $courier   = $courierId > 0 ? $this->findCourier($orgId, $courierId) : null;
        if ($courier === null) {
            return $this->fail('Assign an active courier to the shipment before booking a pickup.');
        }
        $courierCheck = $this->courierCompatibility($shipment, $courier);
        if ($courierCheck !== null) {
            return $this->fail($courierCheck);
        }

        $now       = gmdate('Y-m-d H:i:s');
        $reference = 'PKP-' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $courier['code'])) . '-' . random_int(10000, 99999);

        $this->db->transBegin();

        try {
            $update = [
                'courier_partner_id' => (int) $courier['id'],
                'pickup_location_id' => (int) $location['id'],
                'updated_at'         => $now,
            ];
            if (empty($shipment['courier_tracking_number'])) {
                $update['courier_tracking_number'] = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $courier['code'])) . random_int(10000000, 99999999);
            }
            if ((int) $shipment['courier_partner_id'] !== (int) $courier['id']) {
                $update['shipping_charge'] = $this->recalculateCharge($orgId, $shipment, $courier);
            }
            $this->db->table('shipments')->where('id', $shipmentId)->update($update);

            $this->db->table('pickup_requests')->insert([
                'organization_id'    => $orgId,
                'shipment_id'        => $shipmentId,
                'pickup_location_id' => (int) $location['id'],
                'requested_date'     => $date,
                'time_slot'          => $slot,
                'status'             => 'scheduled',
                'courier_reference'  => $reference,
                'remarks'            => $remarks !== '' ? $remarks : null,
                'requested_by'       => $userId,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);

            $description = sprintf('Pickup booked with %s for %s (%s). Reference %s.', $courier['name'], $dt->format('d M Y'), $slot, $reference);

            if ($isReschedule) {
                $this->addEvent($shipmentId, $current, 'Pickup rescheduled', $description, (string) ($location['city'] ?? ''), $userId, 'system', true);
            } else {
                $result = $this->status->transition(
                    $shipmentId,
                    ShipmentStatusService::STATUS_PICKUP_SCHEDULED,
                    'Pickup scheduled',
                    $description,
                    $userId,
                    (string) ($location['city'] ?? ''),
                    $orgId
                );
                if (! $result['success']) {
                    $this->db->transRollback();

                    return $this->fail($result['message']);
                }
            }

            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            log_message('error', 'bookPickup failed: {message}', ['message' => $e->getMessage()]);

            return $this->fail('The pickup could not be booked. Please try again.');
        }

        return ['success' => true, 'message' => 'Pickup scheduled for ' . $dt->format('d M Y') . ' (' . $slot . '). Reference ' . $reference . '.'];
    }

    /**
     * Assigns / changes the courier while the shipment has not been picked up.
     *
     * @return array{success: bool, message: string}
     */
    public function assignCourier(int $orgId, int $shipmentId, ?int $userId, int $courierId): array
    {
        $shipment = $this->find($orgId, $shipmentId);
        if ($shipment === null) {
            return $this->fail('Shipment not found.');
        }

        $current = (string) $shipment['current_status'];
        if (! in_array($current, [
            ShipmentStatusService::STATUS_DRAFT,
            ShipmentStatusService::STATUS_READY_TO_SHIP,
            ShipmentStatusService::STATUS_PICKUP_SCHEDULED,
        ], true)) {
            return $this->fail('The courier can only be changed before the shipment is picked up.');
        }

        $courier = $this->findCourier($orgId, $courierId);
        if ($courier === null) {
            return $this->fail('Select an active courier partner.');
        }

        $problem = $this->courierCompatibility($shipment, $courier);
        if ($problem !== null) {
            return $this->fail($problem);
        }

        if ((int) $shipment['courier_partner_id'] === $courierId) {
            return $this->fail($courier['name'] . ' is already assigned to this shipment.');
        }

        $this->db->transBegin();

        try {
            $update = [
                'courier_partner_id' => $courierId,
                'shipping_charge'    => $this->recalculateCharge($orgId, $shipment, $courier),
                'updated_at'         => gmdate('Y-m-d H:i:s'),
            ];
            if ($current !== ShipmentStatusService::STATUS_DRAFT && empty($shipment['courier_tracking_number'])) {
                $update['courier_tracking_number'] = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $courier['code'])) . random_int(10000000, 99999999);
            }
            $this->db->table('shipments')->where('id', $shipmentId)->update($update);

            $this->addEvent(
                $shipmentId,
                $current,
                'Courier assigned',
                'Courier set to ' . $courier['name'] . '.',
                $this->senderCity($shipment),
                $userId,
                'system',
                $current !== ShipmentStatusService::STATUS_DRAFT
            );

            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            log_message('error', 'assignCourier failed: {message}', ['message' => $e->getMessage()]);

            return $this->fail('The courier could not be assigned.');
        }

        return ['success' => true, 'message' => $courier['name'] . ' assigned to the shipment.'];
    }

    /**
     * Moves the shipment to the next status chosen from the UI. Only transitions
     * allowed by ShipmentStatusService AND listed in ADVANCE_TARGETS are accepted.
     *
     * @return array{success: bool, message: string}
     */
    public function advance(int $orgId, int $shipmentId, ?int $userId, string $toStatus, ?string $location = null, ?string $remarks = null): array
    {
        if (! in_array($toStatus, self::ADVANCE_TARGETS, true)) {
            return $this->fail($this->dedicatedFlowHint($toStatus));
        }

        $shipment = $this->find($orgId, $shipmentId);
        if ($shipment === null) {
            return $this->fail('Shipment not found.');
        }

        $location = $location !== null ? trim($location) : '';
        $remarks  = $remarks !== null ? trim($remarks) : '';
        if (mb_strlen($location) > 180 || mb_strlen($remarks) > 500) {
            return $this->fail('Location or remarks are too long.');
        }

        $titles = [
            'picked_up'               => ['Picked up', 'Shipment collected from the sender.', 'courier'],
            'pickup_failed'           => ['Pickup failed', 'The courier could not collect the shipment.', 'courier'],
            'in_transit'              => ['In transit', 'Shipment is on its way to the destination city.', 'courier'],
            'reached_destination_hub' => ['Reached destination hub', 'Shipment arrived at the destination hub.', 'courier'],
            'rto_in_transit'          => ['RTO in transit', 'Shipment is returning to the origin.', 'courier'],
            'rto_delivered'           => ['RTO delivered', 'Shipment returned to the origin and received by the sender.', 'courier'],
        ];
        [$title, $defaultDescription, $source] = $titles[$toStatus];

        $this->db->transBegin();

        try {
            $result = $this->status->transition(
                $shipmentId,
                $toStatus,
                $title,
                $remarks !== '' ? $remarks : $defaultDescription,
                $userId,
                $location !== '' ? $location : null,
                $orgId,
                $source
            );

            if (! $result['success']) {
                $this->db->transRollback();

                return $this->fail($result['message']);
            }

            // Keep the pickup request in step with the shipment.
            if ($toStatus === ShipmentStatusService::STATUS_PICKED_UP) {
                $this->closeActivePickup($shipmentId, 'completed', true);
            } elseif ($toStatus === ShipmentStatusService::STATUS_PICKUP_FAILED) {
                $this->closeActivePickup($shipmentId, 'failed', false);
            }

            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            log_message('error', 'advance failed: {message}', ['message' => $e->getMessage()]);

            return $this->fail('The status could not be updated.');
        }

        return ['success' => true, 'message' => 'Shipment moved to ' . $this->status->defaultTitle($toStatus) . '.'];
    }

    /**
     * Cancels a shipment (draft / ready_to_ship only).
     *
     * @return array{success: bool, message: string}
     */
    public function cancel(int $orgId, int $shipmentId, ?int $userId, ?string $reason = null): array
    {
        $shipment = $this->find($orgId, $shipmentId);
        if ($shipment === null) {
            return $this->fail('Shipment not found.');
        }

        $reason = trim((string) $reason);
        if (mb_strlen($reason) > 400) {
            return $this->fail('The cancellation reason cannot exceed 400 characters.');
        }

        $result = $this->status->transition(
            $shipmentId,
            ShipmentStatusService::STATUS_CANCELLED,
            'Shipment cancelled',
            $reason !== '' ? 'Cancelled: ' . $reason : 'Shipment cancelled before pickup.',
            $userId,
            $this->senderCity($shipment),
            $orgId,
            'system',
            false
        );

        return $result['success']
            ? ['success' => true, 'message' => 'Shipment cancelled.']
            : $this->fail($result['message']);
    }

    // ------------------------------------------------------------------
    // Pickup request management (used by PickupsController)
    // ------------------------------------------------------------------

    /**
     * Marks a pickup request completed and moves the shipment to picked_up.
     *
     * @return array{success: bool, message: string}
     */
    public function completePickup(int $orgId, int $pickupId, ?int $userId): array
    {
        $pickup = $this->findPickup($orgId, $pickupId);
        if ($pickup === null) {
            return $this->fail('Pickup request not found.');
        }
        if (! in_array($pickup['status'], ['requested', 'scheduled'], true)) {
            return $this->fail('Only open pickup requests can be completed.');
        }

        $this->db->transBegin();

        try {
            $result = $this->status->transition(
                (int) $pickup['shipment_id'],
                ShipmentStatusService::STATUS_PICKED_UP,
                'Picked up',
                'Shipment collected from the sender' . ($pickup['courier_reference'] ? ' (pickup ref ' . $pickup['courier_reference'] . ').' : '.'),
                $userId,
                null,
                $orgId,
                'courier'
            );

            if (! $result['success']) {
                $this->db->transRollback();

                return $this->fail($result['message']);
            }

            $now = gmdate('Y-m-d H:i:s');
            $this->db->table('pickup_requests')->where('id', $pickupId)->update([
                'status' => 'completed', 'completed_at' => $now, 'updated_at' => $now,
            ]);

            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            log_message('error', 'completePickup failed: {message}', ['message' => $e->getMessage()]);

            return $this->fail('The pickup could not be completed.');
        }

        return ['success' => true, 'message' => 'Pickup completed. Shipment ' . $pickup['tracking_number'] . ' is now Picked Up.'];
    }

    /**
     * Cancels an open pickup request. The shipment stays pickup_scheduled and can be re-booked.
     *
     * @return array{success: bool, message: string}
     */
    public function cancelPickup(int $orgId, int $pickupId, ?int $userId): array
    {
        $pickup = $this->findPickup($orgId, $pickupId);
        if ($pickup === null) {
            return $this->fail('Pickup request not found.');
        }
        if (! in_array($pickup['status'], ['requested', 'scheduled'], true)) {
            return $this->fail('Only open pickup requests can be cancelled.');
        }

        $this->db->transBegin();

        try {
            $this->db->table('pickup_requests')->where('id', $pickupId)->update([
                'status' => 'cancelled', 'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);

            $this->addEvent(
                (int) $pickup['shipment_id'],
                (string) $pickup['shipment_status'],
                'Pickup cancelled',
                'Pickup request ' . ($pickup['courier_reference'] ?: '#' . $pickupId) . ' was cancelled. Re-book a pickup to continue.',
                null,
                $userId,
                'system',
                false
            );

            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            log_message('error', 'cancelPickup failed: {message}', ['message' => $e->getMessage()]);

            return $this->fail('The pickup could not be cancelled.');
        }

        return ['success' => true, 'message' => 'Pickup cancelled. Re-book it from the shipment page when ready.'];
    }

    // ------------------------------------------------------------------
    // Shared helpers
    // ------------------------------------------------------------------

    /**
     * Appends a timeline event without changing the status.
     */
    public function addEvent(
        int $shipmentId,
        string $statusCode,
        string $title,
        ?string $description = null,
        ?string $location = null,
        ?int $userId = null,
        string $source = 'system',
        bool $visibleToCustomer = true
    ): int {
        $now = gmdate('Y-m-d H:i:s');

        $this->db->table('shipment_events')->insert([
            'shipment_id'         => $shipmentId,
            'status_code'         => $statusCode,
            'title'               => mb_substr($title, 0, 150),
            'description'         => $description !== null && $description !== '' ? mb_substr($description, 0, 500) : null,
            'event_location'      => $location !== null && $location !== '' ? mb_substr($location, 0, 180) : null,
            'event_source'        => $source,
            'visible_to_customer' => $visibleToCustomer ? 1 : 0,
            'occurred_at'         => $now,
            'created_by'          => $userId,
            'created_at'          => $now,
        ]);

        return (int) $this->db->insertID();
    }

    /**
     * Active couriers visible to the organization (own + platform-wide).
     *
     * @return list<array<string, mixed>>
     */
    public function activeCouriers(int $orgId): array
    {
        return $this->db->table('courier_partners')
            ->where('status', 'active')
            ->groupStart()->where('organization_id', $orgId)->orWhere('organization_id', null)->groupEnd()
            ->orderBy('name')
            ->get()->getResultArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findCourier(int $orgId, int $courierId): ?array
    {
        return $this->db->table('courier_partners')
            ->where('id', $courierId)
            ->where('status', 'active')
            ->groupStart()->where('organization_id', $orgId)->orWhere('organization_id', null)->groupEnd()
            ->get()->getRowArray();
    }

    /**
     * @param array<string, mixed> $shipment
     * @param array<string, mixed> $courier
     */
    private function courierCompatibility(array $shipment, array $courier): ?string
    {
        if (($shipment['payment_mode'] ?? '') === 'cod' && (int) $courier['supports_cod'] !== 1) {
            return $courier['name'] . ' does not support cash on delivery shipments.';
        }
        if (($shipment['shipment_type'] ?? 'forward') === 'reverse' && (int) $courier['supports_reverse'] !== 1) {
            return $courier['name'] . ' does not support reverse pickups.';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $shipment
     * @param array<string, mixed> $courier
     */
    private function recalculateCharge(int $orgId, array $shipment, array $courier): float
    {
        $origin = (string) $this->db->table('addresses')->select('postal_code')->where('id', (int) $shipment['sender_address_id'])->get()->getRow('postal_code');
        $dest   = (string) $this->db->table('addresses')->select('postal_code')->where('id', (int) $shipment['receiver_address_id'])->get()->getRow('postal_code');

        return $this->estimateCharge(
            $orgId,
            (int) $courier['id'],
            (float) $shipment['chargeable_weight_kg'],
            ($shipment['payment_mode'] ?? '') === 'cod',
            $origin,
            $dest
        );
    }

    private function closeActivePickup(int $shipmentId, string $newStatus, bool $setCompletedAt): void
    {
        $now    = gmdate('Y-m-d H:i:s');
        $update = ['status' => $newStatus, 'updated_at' => $now];
        if ($setCompletedAt) {
            $update['completed_at'] = $now;
        }

        $this->db->table('pickup_requests')
            ->where('shipment_id', $shipmentId)
            ->whereIn('status', ['requested', 'scheduled'])
            ->update($update);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findPickup(int $orgId, int $pickupId): ?array
    {
        return $this->db->table('pickup_requests pr')
            ->select('pr.*, s.tracking_number, s.current_status AS shipment_status')
            ->join('shipments s', 's.id = pr.shipment_id')
            ->where('pr.id', $pickupId)
            ->where('pr.organization_id', $orgId)
            ->where('s.organization_id', $orgId)
            ->get()->getRowArray();
    }

    /**
     * @param array<string, mixed> $shipment
     */
    private function senderCity(array $shipment): ?string
    {
        $city = $this->db->table('addresses')->select('city')->where('id', (int) ($shipment['sender_address_id'] ?? 0))->get()->getRow('city');

        return $city !== null ? (string) $city : null;
    }

    private function dedicatedFlowHint(string $toStatus): string
    {
        return match ($toStatus) {
            ShipmentStatusService::STATUS_READY_TO_SHIP     => 'Use "Mark ready to ship" for this step.',
            ShipmentStatusService::STATUS_PICKUP_SCHEDULED => 'Use "Book pickup" to schedule a pickup.',
            ShipmentStatusService::STATUS_OUT_FOR_DELIVERY => 'Assign an agent and dispatch the shipment from the Delivery Desk.',
            ShipmentStatusService::STATUS_DELIVERED,
            ShipmentStatusService::STATUS_DELIVERY_FAILED  => 'Delivery outcomes are recorded from the Delivery Desk (attempt / proof of delivery).',
            ShipmentStatusService::STATUS_RTO_INITIATED    => 'RTO is started by resolving the NDR case.',
            ShipmentStatusService::STATUS_CANCELLED        => 'Use the "Cancel shipment" action.',
            default                                         => 'That status cannot be set from here.',
        };
    }

    private function ownedAddressId(int $orgId, int $addressId): ?int
    {
        if ($addressId <= 0) {
            return null;
        }

        $found = $this->db->table('addresses')->select('id')->where('id', $addressId)->where('organization_id', $orgId)->get()->getRowArray();

        return $found !== null ? (int) $found['id'] : null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $errors
     *
     * @return array<string, mixed>|null
     */
    private function validateAddress(array $row, string $label, string $key, array &$errors): ?array
    {
        $clean = [
            'contact_name'  => trim((string) ($row['contact_name'] ?? '')),
            'contact_phone' => trim((string) ($row['contact_phone'] ?? '')),
            'address_line1' => trim((string) ($row['address_line1'] ?? '')),
            'address_line2' => trim((string) ($row['address_line2'] ?? '')),
            'landmark'      => trim((string) ($row['landmark'] ?? '')),
            'city'          => trim((string) ($row['city'] ?? '')),
            'state'         => trim((string) ($row['state'] ?? '')),
            'postal_code'   => trim((string) ($row['postal_code'] ?? '')),
        ];

        $before = count($errors);

        if ($clean['contact_name'] === '' || mb_strlen($clean['contact_name']) > 150) {
            $errors[$key . '.contact_name'] = $label . ' contact name is required (max 150 characters).';
        }
        if (! preg_match('/^[0-9+\-\s()]{7,20}$/', $clean['contact_phone'])) {
            $errors[$key . '.contact_phone'] = $label . ' phone number is invalid.';
        }
        if ($clean['address_line1'] === '' || mb_strlen($clean['address_line1']) > 255) {
            $errors[$key . '.address_line1'] = $label . ' address line 1 is required (max 255 characters).';
        }
        if (mb_strlen($clean['address_line2']) > 255 || mb_strlen($clean['landmark']) > 150) {
            $errors[$key . '.address_line2'] = $label . ' address line 2 / landmark is too long.';
        }
        if ($clean['city'] === '' || mb_strlen($clean['city']) > 100) {
            $errors[$key . '.city'] = $label . ' city is required.';
        }
        if ($clean['state'] === '' || mb_strlen($clean['state']) > 100) {
            $errors[$key . '.state'] = $label . ' state is required.';
        }
        if (! preg_match('/^[1-9][0-9]{5}$/', $clean['postal_code'])) {
            $errors[$key . '.postal_code'] = $label . ' postal code must be a 6-digit PIN code.';
        }

        return count($errors) === $before ? $clean : null;
    }

    /**
     * @param array<string, mixed> $address
     */
    private function insertAddress(int $orgId, array $address): int
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->db->table('addresses')->insert([
            'organization_id' => $orgId,
            'customer_id'     => $address['customer_id'] ?? null,
            'address_type'    => $address['address_type'] ?? 'other',
            'contact_name'    => $address['contact_name'],
            'contact_phone'   => $address['contact_phone'],
            'address_line1'   => $address['address_line1'],
            'address_line2'   => $address['address_line2'] !== '' ? $address['address_line2'] : null,
            'landmark'        => $address['landmark'] !== '' ? $address['landmark'] : null,
            'city'            => $address['city'],
            'state'           => $address['state'],
            'postal_code'     => $address['postal_code'],
            'country_code'    => 'IN',
            'is_default'      => 0,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        return (int) $this->db->insertID();
    }

    /**
     * @param array<int|string, mixed> $rows
     * @param array<string, string>    $errors
     *
     * @return array{0: list<array<string, mixed>>, 1: float} items + total value
     */
    private function validateItems(array $rows, array &$errors): array
    {
        $items = [];
        $total = 0.0;

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['item_name'] ?? ''));
            $sku  = trim((string) ($row['sku'] ?? ''));
            $hsn  = trim((string) ($row['hsn_code'] ?? ''));
            $qty  = $row['quantity'] ?? '';
            $unit = $row['unit_value'] ?? '';

            if ($name === '' && $sku === '' && $hsn === '' && ($unit === '' || $unit === null)) {
                continue; // blank row
            }

            $position = count($items) + 1;
            $before   = count($errors);

            if ($name === '' || mb_strlen($name) > 180) {
                $errors['items.' . $index . '.item_name'] = 'Item ' . $position . ': name is required (max 180 characters).';
            }
            if (mb_strlen($sku) > 80 || mb_strlen($hsn) > 20) {
                $errors['items.' . $index . '.sku'] = 'Item ' . $position . ': SKU / HSN code is too long.';
            }
            if (! ctype_digit((string) $qty) || (int) $qty < 1 || (int) $qty > 100000) {
                $errors['items.' . $index . '.quantity'] = 'Item ' . $position . ': quantity must be a whole number of at least 1.';
            }
            $unitValue = $this->number($unit);
            if ($unitValue === null || $unitValue < 0 || $unitValue > 10000000) {
                $errors['items.' . $index . '.unit_value'] = 'Item ' . $position . ': unit value must be zero or more.';
            }

            if (count($errors) === $before) {
                $lineTotal = round((int) $qty * (float) $unitValue, 2);
                $total    += $lineTotal;
                $items[]   = [
                    'item_name'   => $name,
                    'sku'         => $sku !== '' ? $sku : null,
                    'quantity'    => (int) $qty,
                    'unit_value'  => round((float) $unitValue, 2),
                    'total_value' => $lineTotal,
                    'hsn_code'    => $hsn !== '' ? $hsn : null,
                ];
            }
        }

        if ($items === [] && ! preg_grep('/^items\./', array_keys($errors))) {
            $errors['items'] = 'Add at least one item to the shipment.';
        }
        if (count($items) > self::MAX_ITEMS) {
            $errors['items'] = 'A shipment can contain at most ' . self::MAX_ITEMS . ' item lines.';
        }

        return [$items, $total];
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

    /**
     * Number without trailing zeros, for human-readable event text.
     */
    private function plain(?float $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
    }

    /**
     * @return array{success: false, message: string}
     */
    private function fail(string $message): array
    {
        return ['success' => false, 'message' => $message];
    }
}
