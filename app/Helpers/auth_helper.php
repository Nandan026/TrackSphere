<?php

declare(strict_types=1);

/**
 * Auth helper: thin accessors over the session user (web) or the
 * API-authenticated user (Bearer token, set by ApiAuthFilter).
 */

if (! function_exists('current_user')) {
    /**
     * Returns the authenticated user array (with role and permissions) or null.
     *
     * Shape: id, organization_id, role_id, role_code, role_name, first_name,
     * last_name, full_name, email, phone, status, permissions[].
     *
     * @return array<string, mixed>|null
     */
    function current_user(): ?array
    {
        $apiUser = \App\Filters\ApiAuthFilter::user();
        if ($apiUser !== null) {
            return $apiUser;
        }

        $user = session()->get(\App\Services\Auth\AuthService::SESSION_KEY);

        return is_array($user) && ! empty($user['id']) ? $user : null;
    }
}

if (! function_exists('current_org_id')) {
    /**
     * Organization id of the authenticated user (tenant scope), or null.
     */
    function current_org_id(): ?int
    {
        $user = current_user();

        if ($user === null || empty($user['organization_id'])) {
            return null;
        }

        return (int) $user['organization_id'];
    }
}

if (! function_exists('user_can')) {
    /**
     * Checks whether the current user holds a permission code.
     * Supports the super admin role ("super_admin" role code or "*" permission)
     * and module wildcards such as "shipments.*".
     */
    function user_can(string $permission): bool
    {
        $user = current_user();
        if ($user === null) {
            return false;
        }

        if (($user['role_code'] ?? '') === 'super_admin') {
            return true;
        }

        $permissions = (array) ($user['permissions'] ?? []);

        if (in_array('*', $permissions, true) || in_array($permission, $permissions, true)) {
            return true;
        }

        $module = strstr($permission, '.', true);

        return $module !== false && in_array($module . '.*', $permissions, true);
    }
}

if (! function_exists('auth_check')) {
    /**
     * True when a user is logged in.
     */
    function auth_check(): bool
    {
        return current_user() !== null;
    }
}
