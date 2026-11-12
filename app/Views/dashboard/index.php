<?php
/**
 * Dashboard.
 *
 * Controller passes:
 *   $pageTitle      'Dashboard'
 *   $currentModule  'dashboard'
 *   $guide          Config\Guides dashboard entry (or the key 'dashboard')
 *   $kpis           ['total_shipments' => int, 'in_transit' => int, 'delivered_today' => int, 'open_ndr' => int]
 *   $recentShipments list of rows: tracking_number, receiver_name, destination_city,
 *                    current_status, courier_name (optional), created_at
 */
$kpis = $kpis ?? [];
$recentShipments = $recentShipments ?? [];

$toRow = static function ($row): array {
    if (is_array($row)) {
        return $row;
    }
    if (is_object($row) && method_exists($row, 'toArray')) {
        return $row->toArray();
    }

    return (array) $row;
};
$statusLabel = static fn (string $s): string => ucwords(str_replace('_', ' ', $s));
$fmtDate = static function ($v): string {
    $t = $v ? strtotime((string) $v) : false;

    return $t ? date('d M Y', $t) : '—';
};

$kpiCards = [
    ['key' => 'total_shipments', 'label' => 'Total Shipments', 'icon' => 'bi-boxes',          'tone' => 'teal',  'hint' => 'All time',            'url' => 'shipments'],
    ['key' => 'in_transit',      'label' => 'In Transit',      'icon' => 'bi-truck',          'tone' => 'blue',  'hint' => 'Moving right now',    'url' => 'shipments?status=in_transit'],
    ['key' => 'delivered_today', 'label' => 'Delivered Today', 'icon' => 'bi-check2-circle',  'tone' => 'green', 'hint' => 'Since midnight',      'url' => 'shipments?status=delivered'],
    ['key' => 'open_ndr',        'label' => 'Open NDR',        'icon' => 'bi-exclamation-octagon', 'tone' => 'coral', 'hint' => 'Needs attention', 'url' => 'ndr'],
];

$quickActions = [
    ['label' => 'Create shipment',  'icon' => 'bi-plus-lg',            'url' => 'shipments/create'],
    ['label' => 'Schedule pickup',  'icon' => 'bi-calendar2-plus',     'url' => 'pickups'],
    ['label' => 'Add customer',     'icon' => 'bi-person-plus',        'url' => 'customers/create'],
    ['label' => 'Resolve NDR cases','icon' => 'bi-exclamation-octagon','url' => 'ndr'],
    ['label' => 'View reports',     'icon' => 'bi-bar-chart-line',     'url' => 'reports'],
];

$sessUser = session()->get('user');
$firstName = is_array($sessUser) ? (string) ($sessUser['name'] ?? $sessUser['full_name'] ?? '') : (string) (session()->get('user_name') ?? '');
$firstName = $firstName !== '' ? explode(' ', trim($firstName))[0] : '';
$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="ts-page-head">
    <div>
        <h2><?= esc($greeting) ?><?= $firstName !== '' ? ', ' . esc($firstName) : '' ?> <span class="text-coral">&middot;</span> here's your day</h2>
        <p>Operational snapshot as of <?= esc(date('l, d M Y')) ?>.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= site_url('shipments') ?>" class="btn btn-soft"><i class="bi bi-list-ul me-1"></i> All shipments</a>
        <a href="<?= site_url('shipments/create') ?>" class="btn btn-accent"><i class="bi bi-plus-lg me-1"></i> New shipment</a>
    </div>
</div>

<!-- KPIs -->
<div class="row g-3 mb-4 ts-stagger">
    <?php foreach ($kpiCards as $card): ?>
        <div class="col-12 col-sm-6 col-xl-3">
            <a href="<?= site_url($card['url']) ?>" class="ts-card ts-card--hover ts-kpi ts-kpi--<?= esc($card['tone'], 'attr') ?>">
                <span class="ts-kpi__icon"><i class="bi <?= esc($card['icon'], 'attr') ?>"></i></span>
                <span class="ts-kpi__body">
                    <div class="ts-kpi__label"><?= esc($card['label']) ?></div>
                    <div class="ts-kpi__value"><?= esc(number_format((int) ($kpis[$card['key']] ?? 0))) ?></div>
                    <div class="ts-kpi__hint"><?= esc($card['hint']) ?></div>
                </span>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <!-- Recent shipments -->
    <div class="col-xl-8">
        <section class="ts-card">
            <div class="ts-card__head">
                <h3 class="ts-card__title"><i class="bi bi-clock-history"></i> Recent shipments</h3>
                <a href="<?= site_url('shipments') ?>" class="btn btn-sm btn-soft">View all <i class="bi bi-arrow-right ms-1"></i></a>
            </div>

            <div class="ts-card__body ts-card__body--flush">
                <?php if ($recentShipments === []): ?>
                    <div class="ts-empty">
                        <i class="bi bi-inboxes"></i>
                        <strong>No shipments yet</strong>
                        Create your first shipment to see it here.
                        <div class="mt-3"><a href="<?= site_url('shipments/create') ?>" class="btn btn-accent btn-sm"><i class="bi bi-plus-lg me-1"></i> Create shipment</a></div>
                    </div>
                <?php else: ?>
                    <div class="ts-table-wrap">
                        <table class="ts-table">
                            <thead>
                                <tr>
                                    <th>Tracking #</th>
                                    <th>Receiver</th>
                                    <th>Destination</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($recentShipments as $raw): ?>
                                <?php $s = $toRow($raw); $status = (string) ($s['current_status'] ?? $s['status'] ?? 'draft'); ?>
                                <tr>
                                    <td>
                                        <a href="<?= site_url('shipments/' . rawurlencode((string) ($s['id'] ?? $s['tracking_number'] ?? ''))) ?>" class="ts-mono ts-cell-strong">
                                            <?= esc($s['tracking_number'] ?? '—') ?>
                                        </a>
                                        <?php if (! empty($s['courier_name'])): ?>
                                            <span class="ts-cell-sub"><i class="bi bi-truck me-1"></i><?= esc($s['courier_name']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= esc($s['receiver_name'] ?? '—') ?></td>
                                    <td><?= esc($s['destination_city'] ?? $s['receiver_city'] ?? '—') ?></td>
                                    <td>
                                        <span class="ts-badge ts-badge--<?= esc($status, 'attr') ?>"><?= esc($statusLabel($status)) ?></span>
                                    </td>
                                    <td class="text-muted"><?= esc($fmtDate($s['created_at'] ?? null)) ?></td>
                                    <td class="ts-actions">
                                        <a class="ts-icon-btn" title="View"
                                           href="<?= site_url('shipments/' . rawurlencode((string) ($s['id'] ?? $s['tracking_number'] ?? ''))) ?>">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <!-- Quick actions + public tracking -->
    <div class="col-xl-4">
        <section class="ts-card mb-3">
            <div class="ts-card__head">
                <h3 class="ts-card__title"><i class="bi bi-lightning-charge"></i> Quick actions</h3>
            </div>
            <div class="ts-card__body d-grid gap-2">
                <?php foreach ($quickActions as $qa): ?>
                    <a href="<?= site_url($qa['url']) ?>" class="ts-quick">
                        <i class="bi <?= esc($qa['icon'], 'attr') ?> ts-quick__icon"></i>
                        <span><?= esc($qa['label']) ?></span>
                        <i class="bi bi-arrow-right ts-quick__arrow"></i>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="ts-card">
            <div class="ts-card__head">
                <h3 class="ts-card__title"><i class="bi bi-search"></i> Track a parcel</h3>
            </div>
            <div class="ts-card__body">
                <form action="<?= site_url('track') ?>" method="get" target="_blank">
                    <label for="dashTrack" class="form-label">Tracking number</label>
                    <div class="input-group">
                        <input type="text" id="dashTrack" name="tracking_number" class="form-control ts-mono"
                               placeholder="TS2026XXXXXXXX" autocomplete="off" required>
                        <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button>
                    </div>
                </form>
            </div>
        </section>
    </div>
</div>

<?= $this->endSection() ?>
