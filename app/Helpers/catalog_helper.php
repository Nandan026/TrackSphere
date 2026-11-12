<?php

declare(strict_types=1);

/**
 * Helpers over the database-backed catalog (lookup_values / status_definitions).
 * All of them fall back gracefully when the catalog is empty or unavailable.
 */

use App\Services\Catalog\StatusCatalogService;

if (! function_exists('lookup_options')) {
    /**
     * Active code => label pairs of a lookup category (e.g. 'vehicle_type').
     * Returns $fallback when the category has no rows in the database.
     *
     * @param array<string, string> $fallback
     *
     * @return array<string, string>
     */
    function lookup_options(string $category, array $fallback = []): array
    {
        return (new StatusCatalogService())->lookupOptionsOr($category, $fallback);
    }
}

if (! function_exists('lookup_label')) {
    /**
     * Label for a stored lookup code (inactive codes still resolve).
     */
    function lookup_label(string $category, ?string $code, ?string $fallback = null): string
    {
        $label = (new StatusCatalogService())->lookupLabel($category, $code);
        if ($label !== null) {
            return $label;
        }

        return $fallback ?? ($code !== null && $code !== '' ? ucwords(str_replace('_', ' ', $code)) : '—');
    }
}

if (! function_exists('lookup_meaning')) {
    function lookup_meaning(string $category, ?string $code): ?string
    {
        return (new StatusCatalogService())->lookupMeaning($category, $code);
    }
}

if (! function_exists('status_meaning')) {
    /**
     * Plain-English meaning of a status code in a module, or null.
     */
    function status_meaning(string $module, ?string $code): ?string
    {
        return (new StatusCatalogService())->meaning($module, $code);
    }
}
