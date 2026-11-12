<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Global (non-tenant) permission catalogue.
 */
class PermissionModel extends BaseModel
{
    protected $table         = 'permissions';
    protected $primaryKey    = 'id';
    protected $updatedField  = '';
    protected $allowedFields = ['name', 'code', 'module', 'description'];

    /**
     * Permissions are shared by all organizations, so scoping is a no-op.
     *
     * @return $this
     */
    public function forOrg(?int $organizationId = null)
    {
        return $this;
    }

    /**
     * @return array<string, list<array<string, mixed>>> permissions keyed by module
     */
    public function groupedByModule(): array
    {
        $grouped = [];
        foreach ($this->orderBy('module')->orderBy('name')->findAll() as $row) {
            $grouped[$row['module']][] = $row;
        }

        return $grouped;
    }
}
