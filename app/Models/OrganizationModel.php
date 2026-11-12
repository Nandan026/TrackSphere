<?php

declare(strict_types=1);

namespace App\Models;

class OrganizationModel extends BaseModel
{
    protected $table          = 'organizations';
    protected $primaryKey     = 'id';
    protected $useSoftDeletes = true;
    protected $allowedFields  = [
        'name', 'code', 'legal_name', 'email', 'phone', 'gstin', 'status', 'timezone',
    ];

    /**
     * The organization table is its own tenant root.
     *
     * @return $this
     */
    public function forOrg(?int $organizationId = null)
    {
        $organizationId ??= function_exists('current_org_id') ? current_org_id() : null;

        if ($organizationId === null) {
            $this->where('1 = 0', null, false);

            return $this;
        }

        $this->where($this->table . '.id', $organizationId);

        return $this;
    }

    /**
     * @return array<string, mixed>|object|null
     */
    public function findByCode(string $code)
    {
        return $this->where('code', $code)->first();
    }
}
