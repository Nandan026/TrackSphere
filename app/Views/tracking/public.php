<?php
/**
 * Public tracking page (no login required). Standalone document.
 *
 * Controller passes:
 *   $trackingNumber  string|null  Number searched (echoed back in the box).
 *   $shipment        array|object|null  Fields: tracking_number, current_status, origin_city,
 *                    destination_city, courier_name, expected_delivery_date, created_at.
 *                    Do NOT pass private data (phone, full address, price) to this view.
 *   $events          list of rows (newest first): status, description|remarks, location, event_time|created_at
 *   $guide           optional guide array / key (module `tracking`)
 *
 * Search is a GET form (idempotent, shareable link) so no CSRF token is needed.
 */
$trackingNumber = $trackingNumber ?? '';
$shipment       = $shipment ?? null;
$events         = $events ?? [];
$searched       = trim((string) $trackingNumber) !== '';

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
$fmt = static function ($v, string $format = 'd M Y, h:i A'): string {
    $t = $v ? strtotime((string) $v) : false;

    return $t ? date($format, $t) : '—';
};

$s = $shipment ? $toRow($shipment) : null;
$currentStatus = $s ? (string) ($s['current_status'] ?? $s['status'] ?? 'draft') : '';

// Forward-flow tracker (shown only for normal progress states).
$flow = [
    'ready_to_ship'     => 'Booked',
    'pickup_scheduled'  => 'Pickup set',
    'picked_up'         => 'Picked up',
    'in_transit'        => 'In transit',
    'out_for_delivery'  => 'Out for delivery',
    'delivered'         => 'Delivered',
];
$flowKeys = array_keys($flow);
$flowIndex = array_search($currentStatus === 'reached_destination_hub' ? 'in_transit' : ($currentStatus === 'draft' ? 'ready_to_ship' : $currentStatus), $flowKeys, true);

$failedStates = ['pickup_failed', 'delivery_failed', 'cancelled', 'rto_initiated', 'rto_in_transit', 'rto_delivered'];

$guideData = null;
if (isset($guide) && ! empty($guide)) {
    $guideData = is_string($guide) ? config('Guides')->get($guide) : $guide;
}
$loggedIn = (bool) session()->get('isLoggedIn') || (bool) session()->get('user');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0F4C5C">
    <meta name="robots" content="noindex">
    <title>Track Parcel · TrackSphere</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&amp;family=Plus+Jakarta+Sans:wght@500;600;700;800&amp;display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= base_url('assets/css/tracksphere.css') ?>" rel="stylesheet">
</head>
<body>
<div class="ts-auth ts-auth--top">
    <div class="ts-auth__wrap ts-auth__wrap--wide">

        <div class="ts-auth__brand">
            <span class="ts-logo ts-logo--lg"><i class="bi bi-globe-central-south-asia"></i></span>
            <h1>Track<span>Sphere</span></h1>
            <p>Where is my parcel? Enter your tracking number below.</p>
        </div>

        <div class="ts-auth__card">
            <?= $this->include('partials/flash') ?>

            <form action="<?= site_url('track') ?>" method="get" role="search" class="mb-1">
                <label for="trackingNumber" class="form-label">Tracking number</label>
                <div class="ts-track-search">
                    <input type="text" class="form-control" id="trackingNumber" name="tracking_number"
                           value="<?= esc($trackingNumber) ?>" placeholder="e.g. TS2026XXXXXXXX"
                           maxlength="40" autocomplete="off" required autofocus>
                    <button type="submit" class="btn btn-accent btn-lg text-nowrap">
                        <i class="bi bi-search me-1"></i> Track
                    </button>
                </div>
            </form>

            <?php if ($guideData): ?>
                <div class="mt-4 text-start"><?= view('partials/guide', ['guide' => $guideData, 'module' => 'tracking']) ?></div>
            <?php endif; ?>

            <?php if ($searched && ! $s): ?>
                <div class="ts-empty mt-3">
                    <i class="bi bi-search-heart"></i>
                    <strong>No shipment found for “<?= esc($trackingNumber) ?>”</strong>
                    Check the number for typos and try again. New shipments can take a few minutes to appear.
                </div>
            <?php elseif ($s): ?>
                <hr class="my-4" style="border-color:var(--ts-border)">

                <!-- Summary -->
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                    <div>
                        <div class="text-muted small">Tracking number</div>
                        <div class="ts-mono fs-5 fw-bold text-teal"><?= esc($s['tracking_number'] ?? $trackingNumber) ?></div>
                    </div>
                    <span class="ts-badge ts-badge--lg ts-badge--<?= esc($currentStatus, 'attr') ?>"><?= esc($statusLabel($currentStatus)) ?></span>
                </div>

                <dl class="ts-track-summary mb-4">
                    <div><dt>From</dt><dd><?= esc($s['origin_city'] ?? '—') ?></dd></div>
                    <div><dt>To</dt><dd><?= esc($s['destination_city'] ?? '—') ?></dd></div>
                    <div><dt>Carrier</dt><dd><?= esc($s['courier_name'] ?? 'Assigning…') ?></dd></div>
                    <div><dt>Expected delivery</dt><dd><?= esc($fmt($s['expected_delivery_date'] ?? null, 'd M Y')) ?></dd></div>
                </dl>

                <?php if ($flowIndex !== false && ! in_array($currentStatus, $failedStates, true)): ?>
                    <ol class="ts-progress mb-4" aria-label="Delivery progress">
                        <?php foreach ($flowKeys as $i => $key): ?>
                            <li class="<?= $i < $flowIndex || $currentStatus === 'delivered' ? 'is-done' : ($i === $flowIndex ? 'is-current' : '') ?>">
                                <?= esc($flow[$key]) ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>

                <!-- Timeline -->
                <h3 class="ts-card__title mb-3"><i class="bi bi-signpost-split"></i> Shipment journey</h3>
                <?php if ($events === []): ?>
                    <div class="ts-empty py-4"><i class="bi bi-hourglass-split"></i>No tracking events yet.</div>
                <?php else: ?>
                    <ol class="ts-timeline">
                        <?php foreach ($events as $i => $raw): ?>
                            <?php
                            $e = $toRow($raw);
                            $evStatus = (string) ($e['status'] ?? $e['to_status'] ?? '');
                            $isFailed = in_array($evStatus, $failedStates, true);
                            $cls = $i === 0 ? 'is-current' : 'is-done';
                            if ($isFailed) {
                                $cls = 'is-failed';
                            }
                            $icon = $isFailed ? 'bi-x-lg' : ($evStatus === 'delivered' ? 'bi-check-lg' : ($i === 0 ? 'bi-geo-alt-fill' : 'bi-check'));
                            $desc = $e['description'] ?? $e['remarks'] ?? '';
                            ?>
                            <li class="ts-timeline__item <?= esc($cls, 'attr') ?>">
                                <span class="ts-timeline__dot"><i class="bi <?= esc($icon, 'attr') ?>"></i></span>
                                <div class="ts-timeline__content">
                                    <p class="ts-timeline__status"><?= esc($statusLabel($evStatus !== '' ? $evStatus : 'update')) ?></p>
                                    <?php if ($desc !== ''): ?>
                                        <p class="ts-timeline__desc"><?= esc($desc) ?></p>
                                    <?php endif; ?>
                                    <div class="ts-timeline__meta">
                                        <span><i class="bi bi-clock"></i><?= esc($fmt($e['event_time'] ?? $e['created_at'] ?? null)) ?></span>
                                        <?php if (! empty($e['location'])): ?>
                                            <span><i class="bi bi-pin-map"></i><?= esc($e['location']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="ts-auth__foot">
            <?php if ($loggedIn): ?>
                <a href="<?= site_url('dashboard') ?>"><i class="bi bi-speedometer2 me-1"></i>Back to dashboard</a>
            <?php else: ?>
                Shipping with us? <a href="<?= site_url('login') ?>">Sign in to TrackSphere</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= base_url('assets/js/tracksphere.js') ?>"></script>
</body>
</html>
