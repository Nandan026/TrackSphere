<?php

declare(strict_types=1);

namespace App\Models;

class CustomerModel extends BaseModel
{
    protected $table          = 'customers';
    protected $primaryKey     = 'id';
    protected $useSoftDeletes = true;
    protected $allowedFields  = [
        'organization_id', 'customer_code', 'customer_type', 'name', 'contact_person',
        'email', 'phone', 'gstin', 'status',
    ];

    /**
     * Generates the next customer code for an organization (e.g. CUST-0001).
     */
    public function nextCode(int $organizationId, string $prefix = 'CUST-'): string
    {
        $count = $this->withDeleted()->where('organization_id', $organizationId)->countAllResults();

        do {
            $count++;
            $code   = $prefix . str_pad((string) $count, 4, '0', STR_PAD_LEFT);
            $exists = $this->builder()
                ->where('organization_id', $organizationId)
                ->where('customer_code', $code)
                ->countAllResults() > 0;
        } while ($exists);

        return $code;
    }
}
