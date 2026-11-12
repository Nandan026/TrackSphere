<?php

declare(strict_types=1);

namespace App\Models;

class CourierPartnerModel extends BaseModel
{
    protected $table         = 'courier_partners';
    protected $primaryKey    = 'id';
    protected $allowedFields = [
        'organization_id', 'name', 'code', 'integration_type', 'api_base_url',
        'supports_cod', 'supports_reverse', 'status', 'configuration',
    ];

    /** Platform-wide couriers (organization_id NULL) are available to all organizations. */
    protected bool $allowGlobalRows = true;

    /**
     * @return $this
     */
    public function activeOnly()
    {
        $this->where($this->table . '.status', 'active');

        return $this;
    }
}
