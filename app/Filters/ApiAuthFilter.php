<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\Auth\AuthService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Bearer token authentication against api_tokens.token_hash (SHA-256 of the raw token).
 * On success the authenticated user is available via ApiAuthFilter::user() / current_user().
 */
class ApiAuthFilter implements FilterInterface
{
    /** @var array<string, mixed>|null */
    private static ?array $user = null;

    /** @var array<string, mixed>|null */
    private static ?array $token = null;

    /**
     * @return array<string, mixed>|null
     */
    public static function user(): ?array
    {
        return self::$user;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function token(): ?array
    {
        return self::$token;
    }

    public static function reset(): void
    {
        self::$user  = null;
        self::$token = null;
    }

    public static function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    public function before(RequestInterface $request, $arguments = null)
    {
        self::reset();

        $header = trim((string) $request->getHeaderLine('Authorization'));
        if (! preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            return $this->unauthorized('Missing or malformed Authorization header. Use: Bearer <token>.');
        }

        $db  = \Config\Database::connect();
        $now = gmdate('Y-m-d H:i:s');

        $token = $db->table('api_tokens')
            ->where('token_hash', self::hashToken($m[1]))
            ->where('revoked_at', null)
            ->groupStart()
                ->where('expires_at', null)
                ->orWhere('expires_at >', $now)
            ->groupEnd()
            ->get()->getRowArray();

        if ($token === null) {
            return $this->unauthorized('Invalid, expired or revoked API token.');
        }

        $user = (new AuthService($db))->loadUser((int) $token['user_id']);
        if ($user === null) {
            return $this->unauthorized('The user for this token is inactive.');
        }

        // Best-effort usage tracking; never block the request on failure.
        try {
            $db->table('api_tokens')->where('id', $token['id'])->update(['last_used_at' => $now]);
        } catch (\Throwable $e) {
            log_message('warning', 'api_tokens.last_used_at update failed: ' . $e->getMessage());
        }

        $token['abilities'] = ! empty($token['abilities']) ? json_decode((string) $token['abilities'], true) : null;

        self::$user  = $user;
        self::$token = $token;

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        self::reset();

        return null;
    }

    private function unauthorized(string $message): ResponseInterface
    {
        return api_error($message, [], 401)->setHeader('WWW-Authenticate', 'Bearer');
    }
}
