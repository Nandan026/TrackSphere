<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Filters\ApiAuthFilter;
use App\Services\Auth\AuthService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * API authentication.
 *
 *   POST /api/v1/auth/login   { "email": "...", "password": "...", "device_name": "optional" }
 *        -> plain bearer token (shown ONCE; only its SHA-256 hash is stored in api_tokens)
 *   POST /api/v1/auth/logout  (Bearer) revokes the token used for the request
 *   GET  /api/v1/auth/me      (Bearer) current user + permissions
 */
class AuthController extends BaseApiController
{
    /** Token lifetime in days. */
    private const TOKEN_TTL_DAYS = 30;

    private const MAX_ATTEMPTS_PER_MINUTE = 10;

    public function login(): ResponseInterface
    {
        $input = $this->payload();
        if ($input === null) {
            return $this->invalidJson();
        }

        $email    = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $device   = trim((string) ($input['device_name'] ?? ''));

        $errors = [];
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'A valid email is required.';
        }
        if ($password === '') {
            $errors['password'] = 'Password is required.';
        }
        if (mb_strlen($device) > 60) {
            $errors['device_name'] = 'Device name is too long (max 60 characters).';
        }
        if ($errors !== []) {
            return api_error('Validation failed.', $errors, 422);
        }

        // Throttle per IP + email to slow down password guessing.
        $throttleKey = 'api_login_' . md5($this->request->getIPAddress() . '|' . $email);
        if (! service('throttler')->check($throttleKey, self::MAX_ATTEMPTS_PER_MINUTE, MINUTE)) {
            return api_error('Too many login attempts. Please try again in a minute.', [], 429)->setHeader('Retry-After', '60');
        }

        $db   = \Config\Database::connect();
        $auth = new AuthService($db);

        $row = $db->table('users')
            ->select('id, password_hash, status')
            ->where('email', $email)
            ->where('deleted_at', null)
            ->get()->getRowArray();

        $invalid = static fn (): ResponseInterface => api_error('Invalid email or password.', [], 401);

        if ($row === null) {
            $auth->hashPassword($password); // comparable timing to limit user enumeration

            return $invalid();
        }
        if (! $auth->verifyPassword($password, (string) $row['password_hash'])) {
            return $invalid();
        }
        if ($row['status'] !== 'active') {
            return api_error('Your account is not active. Contact your administrator.', [], 403);
        }

        $user = $auth->loadUser((int) $row['id']);
        if ($user === null) {
            return api_error('Your organization or role is not active.', [], 403);
        }
        if ($user['organization_id'] === null) {
            return api_error('API access requires an organization account.', [], 403);
        }

        $plain     = 'ts_' . bin2hex(random_bytes(32));
        $now       = gmdate('Y-m-d H:i:s');
        $expiresAt = gmdate('Y-m-d H:i:s', strtotime('+' . self::TOKEN_TTL_DAYS . ' days'));

        $db->table('api_tokens')->insert([
            'user_id'    => $user['id'],
            'name'       => $device !== '' ? $device : 'API login',
            'token_hash' => ApiAuthFilter::hashToken($plain),
            'abilities'  => null,
            'expires_at' => $expiresAt,
            'created_at' => $now,
        ]);

        $db->table('users')->where('id', $user['id'])->update(['last_login_at' => $now]);

        return api_success([
            'token'      => $plain,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt,
            'user'       => $this->userPayload($user),
        ], 'Login successful. Store this token securely; it will not be shown again.');
    }

    public function logout(): ResponseInterface
    {
        $token = ApiAuthFilter::token();
        if ($token === null) {
            return api_error('Authentication required.', [], 401);
        }

        \Config\Database::connect()->table('api_tokens')
            ->where('id', $token['id'])
            ->update(['revoked_at' => gmdate('Y-m-d H:i:s')]);

        return api_success(null, 'Token revoked.');
    }

    public function me(): ResponseInterface
    {
        $user = current_user();
        if ($user === null) {
            return api_error('Authentication required.', [], 401);
        }

        return api_success($this->userPayload($user) + ['permissions' => array_values((array) ($user['permissions'] ?? []))]);
    }

    /**
     * @param array<string, mixed> $user
     *
     * @return array<string, mixed>
     */
    private function userPayload(array $user): array
    {
        return [
            'id'           => (int) $user['id'],
            'name'         => $user['full_name'],
            'email'        => $user['email'],
            'role'         => $user['role_code'],
            'role_name'    => $user['role_name'],
            'organization' => [
                'id'       => (int) $user['organization_id'],
                'name'     => $user['organization_name'],
                'code'     => $user['organization_code'],
                'timezone' => $user['timezone'],
            ],
        ];
    }
}
