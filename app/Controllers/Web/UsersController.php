<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Models\RoleModel;
use App\Models\UserModel;
use App\Services\Auth\AuthService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Organization users. Passwords are set directly in the form (no reset email).
 * Route permission: users.manage
 *
 * Safety rules: you cannot deactivate yourself or change your own role, and
 * only a super admin can see or manage super admin accounts.
 */
class UsersController extends MasterDataController
{
    protected string $module = 'users';

    private const PATH = 'users';

    public function index(): string
    {
        [$q, $status] = $this->listFilters();

        $model = new UserModel();
        $model->forOrg()
            ->withRole()
            ->search($q, ['users.first_name', 'users.last_name', 'users.email', 'users.phone', 'roles.name'])
            ->withStatus($status);

        if (! $this->isSuperAdmin()) {
            $model->groupStart()->where('roles.code !=', 'super_admin')->orWhere('roles.code', null)->groupEnd();
        }

        $model->orderBy('users.id', 'DESC');

        $users = $model->paginate($this->perPage);

        return $this->renderPage('users/index', [
            'title'  => 'Users',
            'users'  => $users,
            'pager'  => $model->pager,
            'q'      => $q,
            'status' => $status,
            'selfId' => $this->selfId(),
        ]);
    }

    public function create(): string
    {
        return $this->renderPage('users/form', [
            'title'  => 'Add User',
            'mode'   => 'create',
            'row'    => [],
            'roles'  => $this->roleOptions(),
            'isSelf' => false,
        ]);
    }

    public function store(): RedirectResponse
    {
        $orgId = $this->orgId();
        $data  = $this->collect();

        $rules = $this->rules(true);
        if (! $this->validateData($data + $this->passwordInput(), $rules)) {
            return $this->validationFailed();
        }

        if ($error = $this->extraErrors($data, null)) {
            return $this->backWithErrors($error);
        }

        $auth = new AuthService();

        try {
            $model = new UserModel();
            $ok    = $model->insert($data + [
                'organization_id'   => $orgId,
                'password_hash'     => $auth->hashPassword($this->input('password')),
                'email_verified_at' => gmdate('Y-m-d H:i:s'),
            ]);

            if (! $ok) {
                throw new \RuntimeException('User could not be saved.');
            }
        } catch (\Throwable $e) {
            log_message('error', 'User create failed: ' . $e->getMessage());

            return $this->backWithErrors('The user could not be saved. Please try again.');
        }

        return $this->redirectTo(self::PATH, 'success', 'User ' . $data['email'] . ' created. Share the password with them securely.');
    }

    public function edit(int $id): string
    {
        $row = $this->managedUser($id);

        return $this->renderPage('users/form', [
            'title'  => 'Edit User',
            'mode'   => 'edit',
            'row'    => $row,
            'roles'  => $this->roleOptions(),
            'isSelf' => $id === $this->selfId(),
        ]);
    }

    public function update(int $id): RedirectResponse
    {
        $row    = $this->managedUser($id);
        $isSelf = $id === $this->selfId();
        $data   = $this->collect();

        if ($isSelf) {
            // Never lock yourself out or change your own privileges from this screen.
            $data['role_id'] = (int) $row['role_id'];
            $data['status']  = 'active';
        }

        $newPassword = $this->input('password');
        if (! $this->validateData($data + $this->passwordInput(), $this->rules($newPassword !== ''))) {
            return $this->validationFailed();
        }

        if ($error = $this->extraErrors($data, $id, $isSelf)) {
            return $this->backWithErrors($error);
        }

        if ($newPassword !== '') {
            $data['password_hash'] = (new AuthService())->hashPassword($newPassword);
        }

        try {
            if (! (new UserModel())->update($id, $data)) {
                throw new \RuntimeException('User could not be updated.');
            }
        } catch (\Throwable $e) {
            log_message('error', 'User update failed: ' . $e->getMessage());

            return $this->backWithErrors('The user could not be updated. Please try again.');
        }

        return $this->redirectTo(self::PATH, 'success', 'User ' . $data['email'] . ' updated' . ($newPassword !== '' ? ' and password changed.' : '.'));
    }

    public function delete(int $id): RedirectResponse
    {
        $this->managedUser($id);

        if ($id === $this->selfId()) {
            return $this->redirectTo(self::PATH, 'error', 'You cannot deactivate your own account.');
        }

        return $this->changeStatus(new UserModel(), $id, 'inactive', self::PATH, 'user');
    }

    public function activate(int $id): RedirectResponse
    {
        $this->managedUser($id);

        return $this->changeStatus(new UserModel(), $id, 'active', self::PATH, 'user');
    }

    // ------------------------------------------------------------------

    /**
     * Wipes password fields before the request is flashed back to the form.
     *
     * @param array<string, string>|string $errors
     */
    protected function backWithErrors($errors): RedirectResponse
    {
        unset($_POST['password'], $_POST['password_confirm']);

        return parent::backWithErrors($errors);
    }

    private function selfId(): int
    {
        return (int) (current_user()['id'] ?? 0);
    }

    private function isSuperAdmin(): bool
    {
        return (current_user()['role_code'] ?? '') === 'super_admin';
    }

    /**
     * User of this organization the current account may manage (no password hash).
     *
     * @return array<string, mixed>
     */
    private function managedUser(int $id): array
    {
        $row = $this->ownedRow(new UserModel(), $id);

        if (! $this->isSuperAdmin() && ! empty($row['role_id'])) {
            $role = (new RoleModel())->forOrg()->where('roles.id', (int) $row['role_id'])->first();
            if (is_array($role) && $role['code'] === 'super_admin') {
                throw PageNotFoundException::forPageNotFound('The requested record was not found.');
            }
        }

        unset($row['password_hash']);

        return $row;
    }

    /**
     * Roles that can be assigned: the organization's own and shared roles.
     *
     * @return list<array<string, mixed>>
     */
    private function roleOptions(): array
    {
        $model = (new RoleModel())->forOrg()->orderBy('roles.name', 'ASC');

        if (! $this->isSuperAdmin()) {
            $model->where('roles.code !=', 'super_admin');
        }

        return $model->findAll();
    }

    /**
     * @return array<string, mixed>
     */
    private function collect(): array
    {
        $email  = strtolower($this->input('email'));
        $roleId = $this->input('role_id');

        return [
            'first_name' => $this->input('first_name'),
            'last_name'  => $this->inputOrNull('last_name'),
            'email'      => $email,
            'phone'      => $this->inputOrNull('phone'),
            'role_id'    => ctype_digit($roleId) && $roleId !== '0' ? (int) $roleId : null,
            'status'     => $this->inputStatus(),
        ];
    }

    /**
     * @return array{password: string, password_confirm: string}
     */
    private function passwordInput(): array
    {
        return [
            'password'         => $this->input('password'),
            'password_confirm' => $this->input('password_confirm'),
        ];
    }

    /**
     * @return array<string, array{label: string, rules: string}>
     */
    private function rules(bool $passwordRequired): array
    {
        $password = 'min_length[8]|max_length[72]|regex_match[/^(?=.*[A-Za-z])(?=.*\d).+$/]|matches[password_confirm]';

        return [
            'first_name'       => ['label' => 'First name', 'rules' => 'required|max_length[100]'],
            'last_name'        => ['label' => 'Last name', 'rules' => 'permit_empty|max_length[100]'],
            'email'            => ['label' => 'Email', 'rules' => 'required|valid_email|max_length[190]'],
            'phone'            => ['label' => 'Phone', 'rules' => 'permit_empty|max_length[30]|regex_match[/^[0-9+\-\s()]{6,30}$/]'],
            'role_id'          => ['label' => 'Role', 'rules' => 'required|is_natural_no_zero'],
            'status'           => ['label' => 'Status', 'rules' => 'required|in_list[active,inactive]'],
            'password'         => [
                'label' => 'Password',
                'rules' => ($passwordRequired ? 'required|' : 'permit_empty|') . $password,
                'errors' => ['regex_match' => 'The Password must contain at least one letter and one number.'],
            ],
            'password_confirm' => ['label' => 'Confirm password', 'rules' => $passwordRequired ? 'required' : 'permit_empty'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    private function extraErrors(array $data, ?int $ignoreId, bool $isSelf = false): array
    {
        $errors = [];

        // users.email is unique across the whole platform (including removed accounts).
        $emailQuery = (new UserModel())->builder()->where('email', $data['email']);
        if ($ignoreId !== null) {
            $emailQuery->where('id !=', $ignoreId);
        }
        if ($emailQuery->countAllResults() > 0) {
            $errors['email'] = 'A user with this email address already exists.';
        }

        if (! $isSelf) {
            $allowed = array_column($this->roleOptions(), 'id');
            if (! in_array((string) $data['role_id'], array_map('strval', $allowed), true)) {
                $errors['role_id'] = 'The selected role is not available.';
            }
        }

        return $errors;
    }
}
