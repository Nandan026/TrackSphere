<?php

declare(strict_types=1);

namespace App\Models;

class ShipmentItemModel extends BaseModel
{
    protected $table         = 'shipment_items';
    protected $primaryKey    = 'id';
    protected $updatedField  = '';
    protected $allowedFields = [
        'shipment_id', 'item_name', 'sku', 'quantity', 'unit_value', 'total_value', 'hsn_code',
    ];

    // No organization_id: tenant scope is applied through shipments.
    protected ?string $parentTable = 'shipments';
    protected ?string $parentKey   = 'shipment_id';
}
