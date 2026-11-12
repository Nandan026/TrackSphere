<?php

declare(strict_types=1);

namespace App\Models;

class RoleModel extends BaseModel
{
    protected $table         = 'roles';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $updatedField  = '';
    protected $allowedFields = ['organization_id', 'name', 'code', 'description', 'is_system'];

    /** System roles (organization_id NULL) are visible to every organization. */
    protected bool $allowGlobalRows = true;

    /**
     * @return list<string> permission codes granted to the role
     */
    public function permissionCodes(int $roleId): array
    {
        $rows = $this->db->table('role_permissions rp')
            ->select('p.code')
            ->join('permissions p', 'p.id = rp.permission_id')
            ->where('rp.role_id', $roleId)
            ->get()->getResultArray();

        return array_column($rows, 'code');
    }

    /**
     * Replaces the permissions of a role.
     *
     * @param list<int> $permissionIds
     */
    public function syncPermissions(int $roleId, array $permissionIds): bool
    {
        $this->db->transStart();

        $this->db->table('role_permissions')->where('role_id', $roleId)->delete();

        $now  = gmdate('Y-m-d H:i:s');
        $rows = [];
        foreach (array_unique(array_map('intval', $permissionIds)) as $permissionId) {
            $rows[] = ['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => $now];
        }
        if ($rows !== []) {
            $this->db->table('role_permissions')->insertBatch($rows);
        }

        $this->db->transComplete();

        return $this->db->transStatus();
    }
}
