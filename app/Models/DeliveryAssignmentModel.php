<?php

declare(strict_types=1);

namespace App\Models;

class DeliveryAssignmentModel extends BaseModel
{
    protected $table         = 'delivery_assignments';
    protected $primaryKey    = 'id';
    protected $useTimestamps = false; // table has assigned_at instead of created/updated
    protected $allowedFields = [
        'organization_id', 'shipment_id', 'delivery_agent_id', 'assigned_by', 'assigned_at',
        'accepted_at', 'completed_at', 'status', 'notes',
    ];

    /**
     * @return $this
     */
    public function withRelations()
    {
        $this->select('delivery_assignments.*, s.tracking_number, s.current_status AS shipment_status,'
            . ' da.full_name AS agent_name, da.phone AS agent_phone')
            ->join('shipments s', 's.id = delivery_assignments.shipment_id', 'left')
            ->join('delivery_agents da', 'da.id = delivery_assignments.delivery_agent_id', 'left');

        return $this;
    }
}
