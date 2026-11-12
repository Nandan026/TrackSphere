<?php

declare(strict_types=1);

namespace App\Services\Auth;

use CodeIgniter\Database\BaseConnection;

/**
 * Role based access control: resolves permission codes for a user via
 * users.role_id -> role_permissions -> permissions.
 */
class RbacService
{
    public const SUPER_ADMIN_ROLE = 'super_admin';

    /** @var array<int, list<string>> request-level cache */
    private array $cache = [];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    /**
     * All permission codes granted to the user (empty for inactive/unknown users).
     * Super admin role returns ['*'].
     *
     * @return list<string>
     */
    public function getUserPermissions(int $userId): array
    {
        if (isset($this->cache[$userId])) {
            return $this->cache[$userId];
        }

        $user = $this->db->table('users u')
            ->select('u.role_id, r.code AS role_code')
            ->join('roles r', 'r.id = u.role_id', 'left')
            ->where('u.id', $userId)
            ->where('u.status', 'active')
            ->where('u.deleted_at', null)
            ->get()->getRowArray();

        if ($user === null || empty($user['role_id'])) {
            return $this->cache[$userId] = [];
        }

        if (($user['role_code'] ?? '') === self::SUPER_ADMIN_ROLE) {
            return $this->cache[$userId] = ['*'];
        }

        $rows = $this->db->table('role_permissions rp')
            ->select('p.code')
            ->join('permissions p', 'p.id = rp.permission_id')
            ->where('rp.role_id', (int) $user['role_id'])
            ->get()->getResultArray();

        return $this->cache[$userId] = array_values(array_unique(array_column($rows, 'code')));
    }

    public function hasPermission(int $userId, string $code): bool
    {
        $permissions = $this->getUserPermissions($userId);

        if (in_array('*', $permissions, true) || in_array($code, $permissions, true)) {
            return true;
        }

        $module = strstr($code, '.', true);

        return $module !== false && in_array($module . '.*', $permissions, true);
    }

    public function clearCache(?int $userId = null): void
    {
        if ($userId === null) {
            $this->cache = [];
        } else {
            unset($this->cache[$userId]);
        }
    }
}
