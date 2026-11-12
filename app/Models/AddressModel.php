<?php

declare(strict_types=1);

namespace App\Models;

class AddressModel extends BaseModel
{
    protected $table         = 'addresses';
    protected $primaryKey    = 'id';
    protected $allowedFields = [
        'organization_id', 'customer_id', 'address_type', 'contact_name', 'contact_phone',
        'address_line1', 'address_line2', 'landmark', 'city', 'district', 'state',
        'postal_code', 'country_code', 'latitude', 'longitude', 'is_default',
    ];

    /**
     * @return $this
     */
    public function forCustomer(int $customerId)
    {
        $this->where($this->table . '.customer_id', $customerId);

        return $this;
    }
}
