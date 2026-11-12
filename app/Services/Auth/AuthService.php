<?php

declare(strict_types=1);

namespace App\Services\Auth;

use CodeIgniter\Database\BaseConnection;

/**
 * Session authentication. The tenant (organization) is always taken from
 * the authenticated user record, never from request input.
 */
class AuthService
{
    public const SESSION_KEY = 'auth_user';

    private BaseConnection $db;
    private RbacService $rbac;

    public function __construct(?BaseConnection $db = null, ?RbacService $rbac = null)
    {
        $this->db   = $db ?? \Config\Database::connect();
        $this->rbac = $rbac ?? new RbacService($this->db);
    }

    /**
     * Attempts a login.
     *
     * @return array{success: bool, message: string, user: array<string, mixed>|null}
     */
    public function login(string $email, string $password): array
    {
        $email = strtolower(trim($email));
        $fail  = ['success' => false, 'message' => 'Invalid email or password.', 'user' => null];

        if ($email === '' || $password === '') {
            return $fail;
        }

        $row = $this->db->table('users')
            ->select('id, password_hash, status')
            ->where('email', $email)
            ->where('deleted_at', null)
            ->get()->getRowArray();

        if ($row === null) {
            // Burn comparable time to limit user enumeration via timing.
            $this->hashPassword($password);

            return $fail;
        }

        if (! $this->verifyPassword($password, (string) $row['password_hash'])) {
            return $fail;
        }

        if ($row['status'] !== 'active') {
            return ['success' => false, 'message' => 'Your account is not active. Contact your administrator.', 'user' => null];
        }

        $user = $this->loadUser((int) $row['id']);
        if ($user === null) {
            return ['success' => false, 'message' => 'Your organization or role is not active.', 'user' => null];
        }

        if ($this->needsRehash((string) $row['password_hash'])) {
            $this->db->table('users')->where('id', $user['id'])
                ->update(['password_hash' => $this->hashPassword($password)]);
        }

        $this->db->table('users')->where('id', $user['id'])
            ->update(['last_login_at' => gmdate('Y-m-d H:i:s')]);

        $session = session();
        if (session_status() === PHP_SESSION_ACTIVE) {
            $session->regenerate(true); // prevent session fixation
        }
        $session->set(self::SESSION_KEY, $user);

        return ['success' => true, 'message' => 'Login successful.', 'user' => $user];
    }

    public function logout(): void
    {
        $session = session();
        $session->remove(self::SESSION_KEY);
        if (session_status() === PHP_SESSION_ACTIVE) {
            $session->destroy();
        }
    }

    /**
     * Reloads the session user (role + permissions) from the database.
     * Logs out and returns null if the user can no longer sign in.
     *
     * @return array<string, mixed>|null
     */
    public function attempt(): ?array
    {
        $current = session()->get(self::SESSION_KEY);
        if (! is_array($current) || empty($current['id'])) {
            return null;
        }

        $user = $this->loadUser((int) $current['id']);
        if ($user === null) {
            $this->logout();

            return null;
        }

        session()->set(self::SESSION_KEY, $user);

        return $user;
    }

    /**
     * Loads an active user with role, organization scope and permissions.
     *
     * @return array<string, mixed>|null
     */
    public function loadUser(int $userId): ?array
    {
        $row = $this->db->table('users u')
            ->select('u.id, u.organization_id, u.role_id, u.first_name, u.last_name, u.email, u.phone, u.status,'
                . ' r.name AS role_name, r.code AS role_code,'
                . ' o.name AS organization_name, o.code AS organization_code, o.timezone AS timezone,'
                . ' o.status AS organization_status')
            ->join('roles r', 'r.id = u.role_id', 'left')
            ->join('organizations o', 'o.id = u.organization_id AND o.deleted_at IS NULL', 'left')
            ->where('u.id', $userId)
            ->where('u.status', 'active')
            ->where('u.deleted_at', null)
            ->get()->getRowArray();

        if ($row === null || empty($row['role_id'])) {
            return null;
        }

        // Org users require an active organization (platform users may have none).
        if ($row['organization_id'] !== null && ($row['organization_status'] ?? '') !== 'active') {
            return null;
        }

        $this->rbac->clearCache($userId);

        return [
            'id'                => (int) $row['id'],
            'organization_id'   => $row['organization_id'] !== null ? (int) $row['organization_id'] : null,
            'organization_name' => $row['organization_name'],
            'organization_code' => $row['organization_code'],
            'timezone'          => $row['timezone'] ?: 'Asia/Kolkata',
            'role_id'           => (int) $row['role_id'],
            'role_code'         => $row['role_code'],
            'role_name'         => $row['role_name'],
            'first_name'        => $row['first_name'],
            'last_name'         => $row['last_name'],
            'full_name'         => trim($row['first_name'] . ' ' . ($row['last_name'] ?? '')),
            'email'             => $row['email'],
            'phone'             => $row['phone'],
            'status'            => $row['status'],
            'permissions'       => $this->rbac->getUserPermissions($userId),
        ];
    }

    public function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public function verifyPassword(string $password, string $hash): bool
    {
        return $hash !== '' && password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT);
    }
}
