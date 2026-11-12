<?php

declare(strict_types=1);

namespace App\Models;

class WarehouseModel extends BaseModel
{
    protected $table         = 'warehouses';
    protected $primaryKey    = 'id';
    protected $allowedFields = ['organization_id', 'address_id', 'name', 'warehouse_code', 'status'];

    /**
     * @return $this
     */
    public function withAddress()
    {
        $this->select('warehouses.*, a.address_line1, a.address_line2, a.city, a.state, a.postal_code,'
            . ' a.contact_name, a.contact_phone')
            ->join('addresses a', 'a.id = warehouses.address_id', 'left');

        return $this;
    }
}
