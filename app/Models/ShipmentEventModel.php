<?php

declare(strict_types=1);

namespace App\Models;

class ShipmentEventModel extends BaseModel
{
    protected $table         = 'shipment_events';
    protected $primaryKey    = 'id';
    protected $updatedField  = '';
    protected $allowedFields = [
        'shipment_id', 'status_code', 'title', 'description', 'event_location',
        'event_source', 'source_reference', 'visible_to_customer', 'occurred_at', 'created_by',
    ];

    // No organization_id: tenant scope is applied through shipments.
    protected ?string $parentTable = 'shipments';
    protected ?string $parentKey   = 'shipment_id';

    /**
     * Timeline for a shipment, newest first. Pass $customerVisibleOnly for public tracking.
     *
     * @return list<array<string, mixed>>
     */
    public function timeline(int $shipmentId, bool $customerVisibleOnly = false): array
    {
        $builder = $this->where('shipment_events.shipment_id', $shipmentId);
        if ($customerVisibleOnly) {
            $builder->where('shipment_events.visible_to_customer', 1);
        }

        return $builder->orderBy('shipment_events.occurred_at', 'DESC')
            ->orderBy('shipment_events.id', 'DESC')
            ->findAll();
    }
}
