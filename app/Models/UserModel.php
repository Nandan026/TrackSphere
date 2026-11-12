<?php

declare(strict_types=1);

namespace App\Models;

class UserModel extends BaseModel
{
    protected $table          = 'users';
    protected $primaryKey     = 'id';
    protected $useSoftDeletes = true;
    protected $allowedFields  = [
        'organization_id', 'role_id', 'first_name', 'last_name', 'email', 'phone',
        'password_hash', 'status', 'email_verified_at', 'last_login_at',
    ];

    /**
     * @return array<string, mixed>|object|null
     */
    public function findByEmail(string $email)
    {
        return $this->where('email', strtolower(trim($email)))->first();
    }

    /**
     * Users with role name/code joined (does not expose password_hash).
     *
     * @return $this
     */
    public function withRole()
    {
        $this->select('users.id, users.organization_id, users.role_id, users.first_name, users.last_name,'
            . ' users.email, users.phone, users.status, users.last_login_at, users.created_at,'
            . ' roles.name AS role_name, roles.code AS role_code')
            ->join('roles', 'roles.id = users.role_id', 'left');

        return $this;
    }
}
