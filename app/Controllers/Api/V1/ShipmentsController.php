<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Models\ShipmentModel;
use App\Services\Delivery\DeliveryService;
use App\Services\Return\ReturnService;
use App\Services\Shipment\ShipmentService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Shipments API (Bearer token). All reads/writes are scoped to the token user's organization.
 *
 *   GET  /api/v1/shipments                          list (?page, ?per_page<=100, ?status, ?q)   shipments.view
 *   POST /api/v1/shipments                          create a draft shipment                      shipments.create
 *   GET  /api/v1/shipments/{id}                     detail with events                           shipments.view
 *   POST /api/v1/shipments/{id}/book-pickup         book / re-book a pickup                      shipments.update
 *   POST /api/v1/shipments/{id}/assign-courier      assign or change the courier                 shipments.update
 *   POST /api/v1/shipments/{id}/delivery-attempts   record a delivery attempt                    delivery.update_status
 *   POST /api/v1/shipments/{id}/proof-of-delivery   capture POD (marks delivered)                delivery.update_status
 *   POST /api/v1/shipments/{id}/return-requests     request a return for a delivered shipment    returns.manage
 *
 * Business rules live in ShipmentService / DeliveryService / ReturnService.
 */
class ShipmentsController extends BaseApiController
{
    private const MAX_PER_PAGE = 100;

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    public function index(): ResponseInterface
    {
        if ($denied = $this->deny('shipments.view')) {
            return $denied;
        }

        $orgId   = (int) $this->orgId();
        $service = new ShipmentService();

        $perPage = (int) ($this->request->getGet('per_page') ?? 20);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage > 0 ? $perPage : 20));
        $page    = max(1, (int) $this->request->getGet('page'));

        $status = trim((string) $this->request->getGet('status'));
        if ($status !== '' && ! in_array($status, $service->statusService()->statuses(), true)) {
            return api_error('Validation failed.', ['status' => 'Unknown shipment status.'], 422);
        }
        $q = mb_substr(trim((string) $this->request->getGet('q')), 0, 60);

        $model = new ShipmentModel();
        $model->forOrg($orgId)
            ->withRelations()
            ->withCurrentStatus($status)
            ->search($q, [
                'shipments.tracking_number',
                'shipments.courier_tracking_number',
                'c.name',
                'ra.contact_name',
                'ra.city',
            ])
            ->orderBy('shipments.created_at', 'DESC')
            ->orderBy('shipments.id', 'DESC');

        $rows  = $model->paginate($perPage, 'default', $page);
        $total = (int) $model->pager->getTotal();

        return api_success(
            array_map(fn (array $row): array => $this->listItem($row), $rows),
            'OK',
            200,
            [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'total_pages' => (int) max(1, ceil($total / $perPage)),
            ]
        );
    }

    public function show(int $id): ResponseInterface
    {
        if ($denied = $this->deny('shipments.view')) {
            return $denied;
        }

        $service = new ShipmentService();
        $details = $service->details((int) $this->orgId(), $id);
        if ($details === null) {
            return api_error('Shipment not found.', [], 404);
        }

        $s = $details['shipment'];

        $data = $this->shipmentCore($s) + [
            'customer' => ['id' => (int) $s['customer_id'], 'name' => $s['customer_name'], 'code' => $s['customer_code']],
            'courier'  => $s['courier_partner_id'] ? ['id' => (int) $s['courier_partner_id'], 'name' => $s['courier_name'], 'code' => $s['courier_code']] : null,
            'sender'   => $this->address($details['sender']),
            'receiver' => $this->address($details['receiver']),
            'items'    => array_map(static fn (array $i): array => [
                'name'       => $i['item_name'],
                'sku'        => $i['sku'],
                'quantity'   => (int) $i['quantity'],
                'unit_value' => (float) $i['unit_value'],
                'hsn_code'   => $i['hsn_code'],
            ], $details['items']),
            'events' => array_map(fn (array $e): array => [
                'status'      => $e['status_code'],
                'title'       => $e['title'],
                'description' => $e['description'],
                'location'    => $e['event_location'],
                'source'      => $e['event_source'],
                'occurred_at' => $this->iso($e['occurred_at']),
            ], $details['events']),
            'delivery_attempts' => array_map(fn (array $a): array => [
                'attempt_number'  => (int) $a['attempt_number'],
                'outcome'         => $a['outcome'],
                'failure_reason'  => $a['failure_reason'],
                'remarks'         => $a['remarks'],
                'attempted_at'    => $this->iso($a['attempted_at']),
            ], $details['attempts']),
            'proof_of_delivery' => $details['pod'] === null ? null : [
                'received_by'         => $details['pod']['received_by'],
                'receiver_relation'   => $details['pod']['receiver_relation'],
                'verification_method' => $details['pod']['verification_method'],
                'notes'               => $details['pod']['notes'],
                'delivered_at'        => $this->iso($details['pod']['delivered_at']),
            ],
            'pickups' => array_map(fn (array $p): array => [
                'status'            => $p['status'],
                'requested_date'    => $p['requested_date'],
                'time_slot'         => $p['time_slot'],
                'courier_reference' => $p['courier_reference'],
                'pickup_location'   => $p['pickup_location_name'] ?? null,
            ], $details['pickups']),
            'assignment' => $details['activeAssignment'] === null ? null : [
                'status'   => $details['activeAssignment']['status'],
                'agent'    => $details['activeAssignment']['agent_name'],
                'phone'    => $details['activeAssignment']['agent_phone'],
                'assigned' => $this->iso($details['activeAssignment']['assigned_at']),
            ],
            'returns' => array_map(fn (array $r): array => [
                'return_number' => $r['return_number'],
                'status'        => $r['status'],
                'reason_code'   => $r['reason_code'],
                'requested_at'  => $this->iso($r['requested_at']),
            ], $details['returns']),
        ];

        return api_success($data);
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    public function create(): ResponseInterface
    {
        if ($denied = $this->deny('shipments.create')) {
            return $denied;
        }

        $input = $this->payload();
        if ($input === null) {
            return $this->invalidJson();
        }

        $result = (new ShipmentService())->createShipment($input, (int) $this->orgId(), $this->userId());

        return $this->fromResult($result, [
            'shipment_id'     => $result['shipment_id'] ?? null,
            'tracking_number' => $result['tracking_number'] ?? null,
        ], 201);
    }

    public function bookPickup(int $id): ResponseInterface
    {
        if ($denied = $this->deny('shipments.update')) {
            return $denied;
        }

        $input = $this->payload();
        if ($input === null) {
            return $this->invalidJson();
        }

        $result = (new ShipmentService())->bookPickup(
            (int) $this->orgId(),
            $id,
            $this->userId(),
            [
                'pickup_location_id' => $input['pickup_location_id'] ?? null,
                'requested_date'     => $input['requested_date'] ?? null,
                'time_slot'          => $input['time_slot'] ?? null,
                'courier_partner_id' => $input['courier_partner_id'] ?? null,
                'remarks'            => $input['remarks'] ?? null,
            ],
            $this->timezone()
        );

        return $this->fromResult($result, ['shipment_id' => $id]);
    }

    public function assignCourier(int $id): ResponseInterface
    {
        if ($denied = $this->deny('shipments.update')) {
            return $denied;
        }

        $input = $this->payload();
        if ($input === null) {
            return $this->invalidJson();
        }

        $result = (new ShipmentService())->assignCourier(
            (int) $this->orgId(),
            $id,
            $this->userId(),
            (int) ($input['courier_partner_id'] ?? 0)
        );

        return $this->fromResult($result, ['shipment_id' => $id]);
    }

    public function deliveryAttempts(int $id): ResponseInterface
    {
        if ($denied = $this->deny('delivery.update_status')) {
            return $denied;
        }

        $input = $this->payload();
        if ($input === null) {
            return $this->invalidJson();
        }

        $lat = $input['latitude'] ?? null;
        $lng = $input['longitude'] ?? null;

        $result = (new DeliveryService())->recordAttempt(
            (int) $this->orgId(),
            $id,
            $this->userId(),
            (string) ($input['outcome'] ?? ''),
            isset($input['failure_reason']) ? (string) $input['failure_reason'] : null,
            isset($input['remarks']) ? (string) $input['remarks'] : null,
            filter_var($input['create_ndr'] ?? true, FILTER_VALIDATE_BOOLEAN),
            user_can('delivery.assign') ? null : $this->userId(), // agents may only act on their own assignment
            is_numeric($lat) ? (float) $lat : null,
            is_numeric($lng) ? (float) $lng : null
        );

        return $this->fromResult($result, [
            'shipment_id' => $id,
            'outcome'     => $result['outcome'] ?? null,
            'attempt_id'  => $result['attempt_id'] ?? null,
            'ndr_case'    => $result['ndr_case'] ?? null,
        ], 201);
    }

    public function proofOfDelivery(int $id): ResponseInterface
    {
        if ($denied = $this->deny('delivery.update_status')) {
            return $denied;
        }

        $input = $this->payload();
        if ($input === null) {
            return $this->invalidJson();
        }

        $result = (new DeliveryService())->submitPod(
            (int) $this->orgId(),
            $id,
            $this->userId(),
            [
                'received_by'            => $input['received_by'] ?? null,
                'receiver_relation'      => $input['receiver_relation'] ?? 'self',
                'verification_method'    => $input['verification_method'] ?? 'name',
                'verification_reference' => $input['verification_reference'] ?? null,
                'notes'                  => $input['notes'] ?? null,
                'cod_collected'          => filter_var($input['cod_collected'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ],
            user_can('delivery.assign') ? null : $this->userId()
        );

        return $this->fromResult($result, ['shipment_id' => $id, 'status' => 'delivered'], 201);
    }

    public function returnRequests(int $id): ResponseInterface
    {
        if ($denied = $this->deny('returns.manage')) {
            return $denied;
        }

        $input = $this->payload();
        if ($input === null) {
            return $this->invalidJson();
        }

        $result = (new ReturnService())->create(
            (int) $this->orgId(),
            $id,
            $this->userId(),
            (string) ($input['reason_code'] ?? ''),
            isset($input['reason_details']) ? (string) $input['reason_details'] : null
        );

        return $this->fromResult($result, [
            'return_id'     => $result['return_id'] ?? null,
            'return_number' => $result['return_number'] ?? null,
            'shipment_id'   => $id,
        ], 201);
    }

    // ------------------------------------------------------------------
    // Presenters
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function listItem(array $row): array
    {
        return $this->shipmentCore($row) + [
            'customer' => ['id' => (int) $row['customer_id'], 'name' => $row['customer_name'] ?? null],
            'courier'  => $row['courier_partner_id'] ? ['id' => (int) $row['courier_partner_id'], 'name' => $row['courier_name'] ?? null] : null,
            'receiver_city' => $row['receiver_city'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $s
     *
     * @return array<string, mixed>
     */
    private function shipmentCore(array $s): array
    {
        return [
            'id'                      => (int) $s['id'],
            'tracking_number'         => $s['tracking_number'],
            'courier_tracking_number' => $s['courier_tracking_number'],
            'status'                  => $s['current_status'],
            'shipment_type'           => $s['shipment_type'],
            'payment_mode'            => $s['payment_mode'],
            'cod_amount'              => (float) $s['cod_amount'],
            'shipping_charge'         => (float) $s['shipping_charge'],
            'declared_value'          => (float) $s['declared_value'],
            'currency'                => $s['currency'],
            'total_packages'          => (int) $s['total_packages'],
            'actual_weight_kg'        => (float) $s['actual_weight_kg'],
            'chargeable_weight_kg'    => (float) $s['chargeable_weight_kg'],
            'expected_delivery_at'    => $this->iso($s['expected_delivery_at'] ?? null),
            'delivered_at'            => $this->iso($s['delivered_at'] ?? null),
            'created_at'              => $this->iso($s['created_at'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed>|null $a
     *
     * @return array<string, mixed>|null
     */
    private function address(?array $a): ?array
    {
        if ($a === null) {
            return null;
        }

        return [
            'contact_name'  => $a['contact_name'],
            'contact_phone' => $a['contact_phone'],
            'address_line1' => $a['address_line1'],
            'address_line2' => $a['address_line2'],
            'landmark'      => $a['landmark'],
            'city'          => $a['city'],
            'state'         => $a['state'],
            'postal_code'   => $a['postal_code'],
            'country_code'  => $a['country_code'],
        ];
    }

    /**
     * UTC datetime string -> ISO 8601 (…Z).
     */
    private function iso(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $t = strtotime($value . ' UTC');

        return $t === false ? $value : gmdate('Y-m-d\TH:i:s\Z', $t);
    }
}
