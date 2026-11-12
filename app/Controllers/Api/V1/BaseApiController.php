<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Shared plumbing for the JSON REST API (v1).
 *
 * Deliberately extends the framework controller (not the web BaseController) so
 * no session is started / cookie issued for token-authenticated API calls.
 *
 * Authentication is done by the 'apiAuth' route filter; permissions are checked
 * here with user_can() because route-filter ordering must not decide security.
 */
abstract class BaseApiController extends Controller
{
    protected $helpers = ['auth', 'response', 'format'];

    /**
     * Tenant of the authenticated API user (never taken from request input).
     */
    protected function orgId(): ?int
    {
        return current_org_id();
    }

    protected function userId(): ?int
    {
        $id = current_user()['id'] ?? null;

        return $id !== null ? (int) $id : null;
    }

    protected function timezone(): string
    {
        return (string) (current_user()['timezone'] ?? 'Asia/Kolkata');
    }

    /**
     * Returns an error response when the user lacks the permission (or has no
     * organization), otherwise null. Usage:
     *
     *   if ($denied = $this->deny('shipments.view')) { return $denied; }
     */
    protected function deny(string $permission): ?ResponseInterface
    {
        if (current_user() === null) {
            return api_error('Authentication required.', [], 401);
        }
        if ($this->orgId() === null) {
            return api_error('This account is not linked to an organization.', [], 403);
        }
        if (! user_can($permission)) {
            return api_error('You do not have permission to perform this action.', [], 403);
        }

        return null;
    }

    /**
     * Request payload as an array: JSON body when sent as JSON, otherwise form fields.
     * Returns null for a malformed JSON body.
     *
     * @return array<string, mixed>|null
     */
    protected function payload(): ?array
    {
        $contentType = strtolower((string) $this->request->getHeaderLine('Content-Type'));

        if (str_contains($contentType, 'application/json')) {
            $raw = trim((string) $this->request->getBody());
            if ($raw === '') {
                return [];
            }

            $decoded = json_decode($raw, true);

            return is_array($decoded) && json_last_error() === JSON_ERROR_NONE ? $decoded : null;
        }

        return (array) $this->request->getPost();
    }

    /**
     * Maps a service result array to the API envelope.
     *
     * @param array<string, mixed> $result      ['success' => bool, 'message' => string, 'errors' => ...]
     * @param array<string, mixed> $successData data returned on success
     */
    protected function fromResult(array $result, array $successData = [], int $successCode = 200): ResponseInterface
    {
        if (! empty($result['success'])) {
            return api_success($successData === [] ? null : $successData, (string) ($result['message'] ?? 'OK'), $successCode);
        }

        $message = (string) ($result['message'] ?? 'Request failed.');
        $errors  = isset($result['errors']) && is_array($result['errors']) ? $result['errors'] : [];

        $code = str_contains(strtolower($message), 'not found') && $errors === [] ? 404 : 422;

        return api_error($message, $errors, $code);
    }

    protected function invalidJson(): ResponseInterface
    {
        return api_error('Malformed JSON request body.', [], 400);
    }
}
