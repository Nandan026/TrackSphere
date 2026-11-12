<?php

declare(strict_types=1);

namespace App\Models;

class InvoiceItemModel extends BaseModel
{
    protected $table         = 'invoice_items';
    protected $primaryKey    = 'id';
    protected $updatedField  = '';
    protected $allowedFields = [
        'invoice_id', 'shipment_id', 'description', 'quantity', 'unit_price',
        'tax_percent', 'line_total',
    ];

    // No organization_id: tenant scope is applied through invoices.
    protected ?string $parentTable = 'invoices';
    protected ?string $parentKey   = 'invoice_id';
}
