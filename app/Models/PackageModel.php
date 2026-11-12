<?php

declare(strict_types=1);

namespace App\Models;

class PackageModel extends BaseModel
{
    protected $table         = 'packages';
    protected $primaryKey    = 'id';
    protected $allowedFields = [
        'organization_id', 'package_name', 'package_code', 'length_cm', 'width_cm',
        'height_cm', 'default_weight_kg', 'status',
    ];
}
