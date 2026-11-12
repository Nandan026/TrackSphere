<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\LookupValueModel;
use App\Models\StatusDefinitionModel;

/**
 * Read access to the platform-wide status glossary (status_definitions) and
 * dropdown values (lookup_values), cached in memory for the request.
 *
 * Every method degrades gracefully: when the tables are missing, empty or the
 * database is unreachable, lookups return null / [] so callers can fall back
 * to their built-in constants.
 */
class StatusCatalogService
{
    /** Guide / controller module key => status_definitions.module. */
    private const MODULE_ALIASES = [
        'rates'     => 'rate_cards',
        'billing'   => 'invoices',
        'cod'       => 'cod_remittances',
        'tracking'  => 'shipments',
        'dashboard' => 'shipments',
    ];

    /**
     * Master-data modules that have no statuses of their own and share the
     * common active / inactive "record" definitions.
     */
    private const RECORD_MODULES = [
        'customers', 'users', 'couriers', 'agents', 'packages',
        'warehouses', 'pickup_locations', 'rate_cards',
    ];

    /** Heading used when several status groups are shown in one guide. */
    private const GROUP_TITLES = [
        'shipments'       => 'Shipment status',
        'pickups'         => 'Pickup status',
        'delivery'        => 'Delivery assignment status',
        'ndr'             => 'NDR case status',
        'returns'         => 'Return status',
        'invoices'        => 'Invoice status',
        'cod_remittances' => 'COD remittance status',
        'record'          => 'Record status',
    ];

    /**
     * Which status groups each module guide shows. Each entry is
     * [status module, optional list of codes to keep (null = all)].
     */
    private const GUIDE_MAP = [
        'dashboard' => [['shipments', null]],
        'tracking'  => [['shipments', null]],
        'shipments' => [['shipments', null]],
        'pickups'   => [
            ['pickups', null],
            ['shipments', ['ready_to_ship', 'pickup_scheduled', 'pickup_failed', 'picked_up']],
        ],
        'delivery'  => [
            ['delivery', null],
            ['shipments', ['reached_destination_hub', 'out_for_delivery', 'delivered', 'delivery_failed']],
        ],
        'ndr'       => [
            ['ndr', null],
            ['shipments', ['delivery_failed', 'rto_initiated', 'rto_in_transit', 'rto_delivered']],
        ],
        'returns'   => [
            ['returns', null],
            ['shipments', ['rto_initiated', 'rto_in_transit', 'rto_delivered']],
        ],
        'invoices'  => [['invoices', null]],
        'billing'   => [['invoices', null], ['cod_remittances', null]],
        'cod_remittances' => [['cod_remittances', null]],
        'customers'        => [['record', null]],
        'users'            => [['record', null]],
        'couriers'         => [['record', null]],
        'agents'           => [['record', null]],
        'packages'         => [['record', null]],
        'warehouses'       => [['record', null]],
        'pickup_locations' => [['record', null]],
        'rates'            => [['record', null]],
        'rate_cards'       => [['record', null]],
    ];

    /** @var array<string, array<string, array<string, mixed>>> status module => code => row (all statuses) */
    private static array $statusCache = [];

    /** @var array<string, array<string, array<string, mixed>>> category => code => row (all statuses) */
    private static array $lookupCache = [];

    // ------------------------------------------------------------------
    // Status definitions
    // ------------------------------------------------------------------

    /**
     * Maps a guide/controller module key to the module stored in status_definitions.
     */
    public function resolveModule(string $module): string
    {
        $module = strtolower(trim($module));

        return self::MODULE_ALIASES[$module] ?? $module;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDefinition(string $module, ?string $code): ?array
    {
        $code = strtolower(trim((string) $code));
        if ($code === '') {
            return null;
        }

        $module = $this->resolveModule($module);
        $rows   = $this->loadModule($module);

        if (isset($rows[$code])) {
            return $rows[$code];
        }

        // Master-data modules share the common record statuses (active / inactive).
        if (in_array($module, self::RECORD_MODULES, true)) {
            return $this->loadModule('record')[$code] ?? null;
        }

        return null;
    }

    /**
     * Active definitions of a module in display order.
     *
     * @return list<array<string, mixed>>
     */
    public function listForModule(string $module): array
    {
        $module = $this->resolveModule($module);
        $rows   = $this->loadModule($module);

        if ($rows === [] && in_array($module, self::RECORD_MODULES, true)) {
            $rows = $this->loadModule('record');
        }

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row['status'] ?? 'active') === 'active'
        ));
    }

    /**
     * Label for a status code, or null when there is no definition.
     */
    public function label(string $module, ?string $code): ?string
    {
        $row = $this->getDefinition($module, $code);

        return $row !== null ? (string) $row['label'] : null;
    }

    public function meaning(string $module, ?string $code): ?string
    {
        $row = $this->getDefinition($module, $code);

        return $row !== null ? (string) $row['meaning'] : null;
    }

    /**
     * Bootstrap contextual class (success, info, ...) or null when unknown.
     */
    public function badgeClass(string $module, ?string $code): ?string
    {
        $row = $this->getDefinition($module, $code);

        return $row !== null ? (string) $row['badge_class'] : null;
    }

    /**
     * Status entries shown in a module guide: list of
     * {code, label, meaning, badge_class, group}. Empty when nothing is configured.
     *
     * @return list<array{code: string, label: string, meaning: string, badge_class: string, group: string}>
     */
    public function forGuide(string $guideModule): array
    {
        $guideModule = strtolower(trim($guideModule));
        $sources     = self::GUIDE_MAP[$guideModule] ?? null;

        if ($sources === null) {
            $resolved = $this->resolveModule($guideModule);
            $sources  = self::GUIDE_MAP[$resolved] ?? null;
        }

        if ($sources === null) {
            return [];
        }

        $items = [];
        foreach ($sources as [$module, $only]) {
            $group = self::GROUP_TITLES[$module] ?? ucwords(str_replace('_', ' ', $module));

            foreach ($this->listForModule($module) as $row) {
                if ($only !== null && ! in_array($row['code'], $only, true)) {
                    continue;
                }

                $items[] = [
                    'code'        => (string) $row['code'],
                    'label'       => (string) $row['label'],
                    'meaning'     => (string) $row['meaning'],
                    'badge_class' => (string) $row['badge_class'],
                    'group'       => $group,
                ];
            }
        }

        return $items;
    }

    // ------------------------------------------------------------------
    // Lookup values
    // ------------------------------------------------------------------

    /**
     * code => label for a lookup category, or [] when none are configured.
     *
     * @return array<string, string>
     */
    public function lookupOptions(string $category, bool $activeOnly = true): array
    {
        $options = [];
        foreach ($this->lookupDefinitions($category, $activeOnly) as $row) {
            $options[(string) $row['code']] = (string) $row['label'];
        }

        return $options;
    }

    /**
     * Full rows of a lookup category in display order.
     *
     * @return list<array<string, mixed>>
     */
    public function lookupDefinitions(string $category, bool $activeOnly = true): array
    {
        $rows = $this->loadCategory($category);

        if ($activeOnly) {
            $rows = array_filter($rows, static fn (array $row): bool => ($row['status'] ?? 'active') === 'active');
        }

        return array_values($rows);
    }

    public function lookupLabel(string $category, ?string $code): ?string
    {
        $code = strtolower(trim((string) $code));

        return isset($this->loadCategory($category)[$code])
            ? (string) $this->loadCategory($category)[$code]['label']
            : null;
    }

    public function lookupMeaning(string $category, ?string $code): ?string
    {
        $code = strtolower(trim((string) $code));
        $row  = $this->loadCategory($category)[$code] ?? null;

        return $row !== null && ($row['meaning'] ?? '') !== '' ? (string) $row['meaning'] : null;
    }

    /**
     * Options from the database, or $fallback when the category is empty/unavailable.
     * Pass $activeOnly = false when you only need labels for stored values.
     *
     * @param array<string, string> $fallback
     *
     * @return array<string, string>
     */
    public function lookupOptionsOr(string $category, array $fallback, bool $activeOnly = true): array
    {
        $options = $this->lookupOptions($category, $activeOnly);

        return $options !== [] ? $options : $fallback;
    }

    // ------------------------------------------------------------------

    public function clearCache(): void
    {
        self::$statusCache = [];
        self::$lookupCache = [];
    }

    /**
     * @return array<string, array<string, mixed>> code => row, ordered by sort_order
     */
    private function loadModule(string $module): array
    {
        if (isset(self::$statusCache[$module])) {
            return self::$statusCache[$module];
        }

        $byCode = [];
        try {
            $rows = (new StatusDefinitionModel())->definitionsForModule($module, false);
            foreach ($rows as $row) {
                $byCode[strtolower((string) $row['code'])] = $row;
            }
        } catch (\Throwable $e) {
            log_message('warning', 'Status catalog unavailable: ' . $e->getMessage());
        }

        return self::$statusCache[$module] = $byCode;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadCategory(string $category): array
    {
        $category = strtolower(trim($category));

        if (isset(self::$lookupCache[$category])) {
            return self::$lookupCache[$category];
        }

        $byCode = [];
        try {
            $rows = (new LookupValueModel())->definitions($category, false);
            foreach ($rows as $row) {
                $byCode[strtolower((string) $row['code'])] = $row;
            }
        } catch (\Throwable $e) {
            log_message('warning', 'Lookup catalog unavailable: ' . $e->getMessage());
        }

        return self::$lookupCache[$category] = $byCode;
    }
}
