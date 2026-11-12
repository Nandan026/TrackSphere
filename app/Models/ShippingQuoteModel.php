<?php

declare(strict_types=1);

namespace App\Models;

class ShippingQuoteModel extends BaseModel
{
    protected $table         = 'shipping_quotes';
    protected $primaryKey    = 'id';
    protected $updatedField  = '';
    protected $allowedFields = [
        'organization_id', 'customer_id', 'courier_partner_id', 'origin_postal_code',
        'destination_postal_code', 'actual_weight_kg', 'volumetric_weight_kg',
        'chargeable_weight_kg', 'freight_amount', 'cod_amount', 'tax_amount',
        'total_amount', 'currency', 'expires_at',
    ];
}
