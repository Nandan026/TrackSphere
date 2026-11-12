<?php
/**
 * TrackSphere authenticated shell.
 *
 * Child views: `<?= $this->extend('layouts/main') ?>` and fill the
 * `content` section (optionally `styles` / `scripts`).
 *
 * View data understood by this layout:
 *   $pageTitle     string       Heading shown in the top bar.
 *   $currentModule string       Highlights the matching nav item.
 *   $guide         array|string In-module guide (array from Config\Guides, or a module key).
 *   $orgName / $userName / $userRole  Optional overrides for session values.
 */

$session = session();
$sessUser = $session->get('auth_user') ?? $session->get('user');
$sessUser = is_array($sessUser) ? $sessUser : [];

$userName = $userName ?? ($sessUser['full_name'] ?? $sessUser['name'] ?? $session->get('user_name') ?? 'Guest');
$userRole = $userRole ?? ($sessUser['role_name'] ?? $sessUser['role'] ?? $session->get('user_role') ?? $session->get('role_name') ?? '');
$orgName  = $orgName ?? ($sessUser['organization_name'] ?? $session->get('organization_name') ?? $session->get('org_name') ?? 'TrackSphere');

$pageTitle     = $pageTitle ?? 'TrackSphere';
$currentModule = $currentModule ?? '';

$initials = '';
foreach (preg_split('/\s+/', trim((string) $userName)) ?: [] as $part) {
    if ($part !== '') {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    if (mb_strlen($initials) >= 2) {
        break;
    }
}
$initials = $initials !== '' ? $initials : 'U';

/**
 * Navigation map. `modules` lists every $currentModule value that
 * should light up the item.
 */
$navGroups = [
    'Overview' => [
        ['label' => 'Dashboard', 'icon' => 'bi-speedometer2', 'url' => 'dashboard', 'modules' => ['dashboard']],
    ],
    'Master Data' => [
        ['label' => 'Customers',        'icon' => 'bi-people',        'url' => 'customers',        'modules' => ['customers']],
        ['label' => 'Pickup Locations', 'icon' => 'bi-geo-alt',       'url' => 'pickup-locations', 'modules' => ['pickup_locations']],
        ['label' => 'Warehouses',       'icon' => 'bi-buildings',     'url' => 'warehouses',       'modules' => ['warehouses']],
        ['label' => 'Couriers',         'icon' => 'bi-truck',         'url' => 'couriers',         'modules' => ['couriers']],
        ['label' => 'Agents',           'icon' => 'bi-person-badge',  'url' => 'agents',           'modules' => ['agents']],
        ['label' => 'Packages',         'icon' => 'bi-box-seam',      'url' => 'packages',         'modules' => ['packages']],
        ['label' => 'Rate Cards',       'icon' => 'bi-tags',          'url' => 'rate-cards',       'modules' => ['rates', 'rate_cards']],
    ],
    'Operations' => [
        ['label' => 'Shipments',      'icon' => 'bi-send',                'url' => 'shipments', 'modules' => ['shipments']],
        ['label' => 'Pickups',        'icon' => 'bi-calendar2-check',     'url' => 'pickups',   'modules' => ['pickups']],
        ['label' => 'Delivery Desk',  'icon' => 'bi-bicycle',             'url' => 'delivery',  'modules' => ['delivery']],
        ['label' => 'NDR',            'icon' => 'bi-exclamation-octagon', 'url' => 'ndr',       'modules' => ['ndr']],
        ['label' => 'Returns',        'icon' => 'bi-arrow-return-left',   'url' => 'returns',   'modules' => ['returns']],
    ],
    'Commercial' => [
        ['label' => 'Invoices',         'icon' => 'bi-receipt',         'url' => 'invoices',        'modules' => ['invoices', 'billing']],
        ['label' => 'COD Remittances',  'icon' => 'bi-cash-coin',       'url' => 'cod-remittances', 'modules' => ['cod', 'cod_remittances']],
        ['label' => 'Reports',          'icon' => 'bi-bar-chart-line',  'url' => 'reports',         'modules' => ['reports']],
    ],
    'Admin' => [
        ['label' => 'Users', 'icon' => 'bi-shield-lock', 'url' => 'users', 'modules' => ['users']],
        ['label' => 'Status Glossary', 'icon' => 'bi-journal-text', 'url' => 'status-definitions', 'modules' => ['status_definitions']],
        ['label' => 'Lookup Values',   'icon' => 'bi-list-check',   'url' => 'lookup-values',      'modules' => ['lookup_values']],
    ],
    'Public' => [
        ['label' => 'Track Parcel', 'icon' => 'bi-search', 'url' => 'track', 'modules' => ['tracking'], 'external' => true],
    ],
];

// Resolve guide (array, or module key looked up in Config\Guides).
$guideData = null;
if (isset($guide) && ! empty($guide)) {
    $guideData = is_string($guide) ? config('Guides')->get($guide) : $guide;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= esc(csrf_hash()) ?>">
    <meta name="theme-color" content="#0F4C5C">
    <title><?= esc($pageTitle) ?> · TrackSphere</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&amp;family=Plus+Jakarta+Sans:wght@500;600;700;800&amp;display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= base_url('assets/css/tracksphere.css') ?>" rel="stylesheet">
    <?= $this->renderSection('styles') ?>
</head>
<body>
<div class="ts-app">

    <!-- Sidebar -->
    <aside class="ts-sidebar" id="tsSidebar" aria-label="Primary navigation">
        <a href="<?= site_url('dashboard') ?>" class="ts-sidebar__brand">
            <span class="ts-logo"><i class="bi bi-globe-central-south-asia"></i></span>
            <span>
                <span class="ts-brand-name">Track<span>Sphere</span></span>
                <span class="ts-brand-tag">Logistics Suite</span>
            </span>
        </a>

        <nav class="ts-sidebar__nav">
            <?php foreach ($navGroups as $groupLabel => $items): ?>
                <div class="ts-nav-group">
                    <div class="ts-nav-label"><?= esc($groupLabel) ?></div>
                    <?php foreach ($items as $item): ?>
                        <?php $isActive = in_array($currentModule, $item['modules'], true); ?>
                        <a href="<?= site_url($item['url']) ?>"
                           class="ts-nav-link<?= $isActive ? ' active' : '' ?>"
                           <?= $isActive ? 'aria-current="page"' : '' ?>
                           <?= ! empty($item['external']) ? 'target="_blank" rel="noopener"' : '' ?>>
                            <i class="bi <?= esc($item['icon'], 'attr') ?>"></i>
                            <span><?= esc($item['label']) ?></span>
                            <?php if (! empty($item['external'])): ?>
                                <i class="bi bi-box-arrow-up-right ts-nav-ext"></i>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </nav>

        <div class="ts-sidebar__footer">
            <span><i class="bi bi-circle-fill text-success me-1" style="font-size:.5rem"></i> All systems normal</span>
            <span>v1.0</span>
        </div>
    </aside>
    <div class="ts-backdrop" aria-hidden="true"></div>

    <!-- Main -->
    <div class="ts-main">
        <header class="ts-topbar">
            <button type="button" class="ts-topbar__toggle" data-ts-sidebar-toggle
                    aria-label="Toggle navigation" aria-controls="tsSidebar" aria-expanded="false">
                <i class="bi bi-list"></i>
            </button>

            <div>
                <h1 class="ts-topbar__title"><?= esc($pageTitle) ?></h1>
                <p class="ts-topbar__crumb">TrackSphere <i class="bi bi-chevron-right" style="font-size:.6rem"></i> <?= esc($pageTitle) ?></p>
            </div>

            <div class="ts-topbar__actions">
                <span class="ts-org-chip" title="Organization">
                    <i class="bi bi-building"></i>
                    <span class="ts-org-chip__name"><?= esc($orgName) ?></span>
                </span>

                <div class="ts-user">
                    <span class="ts-avatar" aria-hidden="true"><?= esc($initials) ?></span>
                    <div class="ts-user__meta">
                        <div class="ts-user__name"><?= esc($userName) ?></div>
                        <?php if ($userRole !== ''): ?>
                            <div class="ts-user__role"><?= esc(ucwords(str_replace('_', ' ', (string) $userRole))) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <form action="<?= site_url('logout') ?>" method="post" class="m-0">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-outline-primary" title="Sign out">
                        <i class="bi bi-box-arrow-right"></i><span class="d-none d-md-inline ms-1">Logout</span>
                    </button>
                </form>
            </div>
        </header>

        <main class="ts-content">
            <?= $this->include('partials/flash') ?>

            <?php if ($guideData): ?>
                <?= view('partials/guide', ['guide' => $guideData, 'module' => $currentModule !== '' ? $currentModule : 'general']) ?>
            <?php endif; ?>

            <?= $this->renderSection('content') ?>
        </main>

        <footer class="ts-footer d-flex flex-wrap justify-content-between gap-2">
            <span>&copy; <?= date('Y') ?> TrackSphere &mdash; Logistics &amp; last-mile delivery management.</span>
            <span>Built with CodeIgniter 4 &middot; Bootstrap 5</span>
        </footer>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= base_url('assets/js/tracksphere.js') ?>"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
