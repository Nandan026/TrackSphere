<?php

declare(strict_types=1);

namespace App\Models;

class PickupRequestModel extends BaseModel
{
    protected $table         = 'pickup_requests';
    protected $primaryKey    = 'id';
    protected $allowedFields = [
        'organization_id', 'shipment_id', 'pickup_location_id', 'requested_date', 'time_slot',
        'status', 'courier_reference', 'remarks', 'requested_by', 'completed_at',
    ];

    /**
     * @return $this
     */
    public function withRelations()
    {
        $this->select('pickup_requests.*, s.tracking_number, pl.name AS pickup_location_name')
            ->join('shipments s', 's.id = pickup_requests.shipment_id', 'left')
            ->join('pickup_locations pl', 'pl.id = pickup_requests.pickup_location_id', 'left');

        return $this;
    }
}
