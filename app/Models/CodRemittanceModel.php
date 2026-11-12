<?php

declare(strict_types=1);

namespace App\Models;

class CodRemittanceModel extends BaseModel
{
    protected $table         = 'cod_remittances';
    protected $primaryKey    = 'id';
    protected $updatedField  = '';
    protected $allowedFields = [
        'organization_id', 'courier_partner_id', 'remittance_number', 'period_start',
        'period_end', 'total_cod_amount', 'deductions', 'net_amount', 'status',
        'reference_number', 'remitted_at',
    ];
}
