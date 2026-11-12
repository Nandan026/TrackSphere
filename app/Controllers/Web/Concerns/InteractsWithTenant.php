<?php

declare(strict_types=1);

namespace App\Controllers\Web\Concerns;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Small helpers shared by the operations controllers: tenant resolution and
 * "service result -> flash + redirect" handling.
 *
 * The organization is ALWAYS taken from the authenticated user, never from
 * request input.
 */
trait InteractsWithTenant
{
    /**
     * @throws PageNotFoundException when the user has no organization (platform-level user)
     */
    protected function orgId(): int
    {
        $orgId = current_org_id();

        if ($orgId === null) {
            throw PageNotFoundException::forPageNotFound('No organization is associated with this account.');
        }

        return $orgId;
    }

    protected function userId(): ?int
    {
        $id = current_user()['id'] ?? null;

        return $id !== null ? (int) $id : null;
    }

    protected function userTimezone(): string
    {
        return (string) (current_user()['timezone'] ?? 'Asia/Kolkata');
    }

    /**
     * Flashes a service result and redirects. On failure, redirects to
     * $failUrl (default: same as success) and keeps the submitted input.
     *
     * @param array{success: bool, message: string, errors?: array<string, string>} $result
     */
    protected function respond(array $result, string $successUrl, ?string $failUrl = null): RedirectResponse
    {
        if ($result['success']) {
            $this->flash('success', $result['message']);

            return redirect()->to(site_url($successUrl));
        }

        $redirect = redirect()->to(site_url($failUrl ?? $successUrl))->withInput();

        if (! empty($result['errors'])) {
            return $redirect->with('errors', $result['errors']);
        }

        return $redirect->with('error', $result['message']);
    }

    /**
     * Cleans a free-text value from the query string / POST.
     */
    protected function cleanInput(?string $value, int $max = 100): string
    {
        return mb_substr(trim((string) $value), 0, $max);
    }
}
