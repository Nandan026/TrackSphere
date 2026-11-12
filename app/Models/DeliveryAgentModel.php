<?php

declare(strict_types=1);

namespace App\Models;

class DeliveryAgentModel extends BaseModel
{
    protected $table         = 'delivery_agents';
    protected $primaryKey    = 'id';
    protected $allowedFields = [
        'organization_id', 'user_id', 'agent_code', 'full_name', 'phone',
        'vehicle_type', 'vehicle_number', 'status',
    ];

    /**
     * @return $this
     */
    public function activeOnly()
    {
        $this->where($this->table . '.status', 'active');

        return $this;
    }
}
