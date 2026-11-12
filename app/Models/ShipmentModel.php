<?php

declare(strict_types=1);

namespace App\Models;

class ShipmentModel extends BaseModel
{
    protected $table          = 'shipments';
    protected $primaryKey     = 'id';
    protected $useSoftDeletes = true;

    /**
     * current_status is intentionally NOT mass-assignable here: status changes
     * must go through ShipmentStatusService so events stay consistent.
     */
    protected $allowedFields = [
        'organization_id', 'customer_id', 'pickup_location_id', 'sender_address_id',
        'receiver_address_id', 'courier_partner_id', 'quote_id', 'tracking_number',
        'courier_tracking_number', 'shipment_type', 'payment_mode', 'total_packages',
        'actual_weight_kg', 'volumetric_weight_kg', 'chargeable_weight_kg', 'declared_value',
        'shipping_charge', 'cod_amount', 'currency', 'special_instructions',
        'expected_delivery_at', 'shipped_at', 'delivered_at', 'cancelled_at', 'created_by',
    ];

    /**
     * Initial status is only settable at creation via this helper.
     *
     * @param array<string, mixed> $data
     *
     * @return int|false inserted id
     */
    public function createDraft(array $data, string $status = 'draft')
    {
        $id = $this->insert($data, true);
        if ($id === false) {
            return false;
        }

        if ($status !== 'draft') {
            $this->builder()->where('id', $id)->update(['current_status' => $status]);
        }

        return $id;
    }

    /**
     * @return array<string, mixed>|object|null
     */
    public function findByTracking(string $trackingNumber, ?int $organizationId = null)
    {
        $this->where('shipments.tracking_number', strtoupper(trim($trackingNumber)));

        if ($organizationId !== null) {
            $this->where('shipments.organization_id', $organizationId);
        }

        return $this->first();
    }

    /**
     * Listing query with customer, courier and receiver city joined.
     *
     * @return $this
     */
    public function withRelations()
    {
        $this->select('shipments.*, c.name AS customer_name, c.customer_code, cp.name AS courier_name,'
            . ' ra.city AS receiver_city, ra.state AS receiver_state, ra.postal_code AS receiver_postal_code,'
            . ' sa.city AS sender_city, sa.postal_code AS sender_postal_code')
            ->join('customers c', 'c.id = shipments.customer_id', 'left')
            ->join('courier_partners cp', 'cp.id = shipments.courier_partner_id', 'left')
            ->join('addresses ra', 'ra.id = shipments.receiver_address_id', 'left')
            ->join('addresses sa', 'sa.id = shipments.sender_address_id', 'left');

        return $this;
    }

    /**
     * @return $this
     */
    public function withCurrentStatus(?string $status)
    {
        if ($status !== null && $status !== '') {
            $this->where('shipments.current_status', $status);
        }

        return $this;
    }

    /**
     * Counts of shipments per status for an organization.
     *
     * @return array<string, int>
     */
    public function statusCounts(?int $organizationId = null): array
    {
        $organizationId ??= function_exists('current_org_id') ? current_org_id() : null;
        if ($organizationId === null) {
            return [];
        }

        $rows = $this->builder()
            ->select('current_status, COUNT(*) AS total')
            ->where('organization_id', $organizationId)
            ->where('deleted_at', null)
            ->groupBy('current_status')
            ->get()->getResultArray();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['current_status']] = (int) $row['total'];
        }

        return $counts;
    }
}
