<?php

declare(strict_types=1);

namespace App\Models;

class PickupLocationModel extends BaseModel
{
    protected $table         = 'pickup_locations';
    protected $primaryKey    = 'id';
    protected $allowedFields = [
        'organization_id', 'address_id', 'name', 'location_code', 'contact_email',
        'operating_hours', 'is_default', 'status',
    ];

    /**
     * Locations joined with their address for listings.
     *
     * @return $this
     */
    public function withAddress()
    {
        $this->select('pickup_locations.*, a.address_line1, a.address_line2, a.city, a.state, a.postal_code,'
            . ' a.contact_name, a.contact_phone')
            ->join('addresses a', 'a.id = pickup_locations.address_id', 'left');

        return $this;
    }
}
