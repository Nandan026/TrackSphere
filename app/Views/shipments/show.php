<?php
/**
 * Shipment detail: summary, route, items, timeline and status-aware actions.
 *
 * Controller passes: $shipment, $sender, $receiver, $items, $events, $documents, $pickups,
 * $activePickup, $assignments, $activeAssignment, $attempts, $pod, $ndrCases, $returns,
 * $actions, $canManage, $canAssign, $canDeliver, $canReturn, $canNdr, $slots, $couriers, $locations.
 */
$status   = (string) $shipment['current_status'];
$label    = static fn (string $s): string => ucwords(str_replace('_', ' ', $s));
$kg       = static fn ($v): string => rtrim(rtrim(number_format((float) $v, 3), '0'), '.') . ' kg';
$money    = static fn ($v): string => format_money($v, $shipment['currency'] ?? 'INR');

// Horizontal tracker
$flow = [
    'draft'                   => 'Draft',
    'ready_to_ship'           => 'Ready',
    'pickup_scheduled'        => 'Pickup set',
    'picked_up'               => 'Picked up',
    'in_transit'              => 'In transit',
    'reached_destination_hub' => 'At hub',
    'out_for_delivery'        => 'Out for delivery',
    'delivered'               => 'Delivered',
];
$flowKeys  = array_keys($flow);
$trackAs   = $status;
if ($status === 'delivery_failed') {
    $trackAs = 'out_for_delivery';
} elseif ($status === 'pickup_failed') {
    $trackAs = 'pickup_scheduled';
}
$flowIndex = array_search($trackAs, $flowKeys, true);
$exception = in_array($status, ['pickup_failed', 'delivery_failed', 'cancelled', 'rto_initiated', 'rto_in_transit', 'rto_delivered'], true);

$icons = [
    'draft' => 'bi-pencil-square', 'ready_to_ship' => 'bi-box-seam', 'pickup_scheduled' => 'bi-calendar2-check',
    'picked_up' => 'bi-bag-check', 'in_transit' => 'bi-truck', 'reached_destination_hub' => 'bi-buildings',
    'out_for_delivery' => 'bi-bicycle', 'delivered' => 'bi-check2-circle', 'delivery_failed' => 'bi-x-octagon',
    'pickup_failed' => 'bi-x-octagon', 'cancelled' => 'bi-slash-circle', 'rto_initiated' => 'bi-arrow-return-left',
    'rto_in_transit' => 'bi-arrow-return-left', 'rto_delivered' => 'bi-house-check',
];
$failedCodes = ['delivery_failed', 'pickup_failed', 'cancelled'];

$advanceLabels = [
    'picked_up'               => ['Mark picked up', 'bi-bag-check'],
    'pickup_failed'           => ['Pickup failed', 'bi-x-octagon'],
    'in_transit'              => ['Mark in transit', 'bi-truck'],
    'reached_destination_hub' => ['Reached destination hub', 'bi-buildings'],
    'rto_in_transit'          => ['RTO in transit', 'bi-arrow-return-left'],
    'rto_delivered'           => ['RTO delivered', 'bi-house-check'],
];

$addressBlock = static function (?array $a, string $title): string {
    if ($a === null) {
        return '<div class="ts-addr"><div class="ts-addr__label">' . esc($title) . '</div><span class="text-muted">Address unavailable</span></div>';
    }
    $lines = array_filter([
        $a['address_line1'] ?? '', $a['address_line2'] ?? '', $a['landmark'] ?? '',
        trim(($a['city'] ?? '') . ', ' . ($a['state'] ?? '') . ' ' . ($a['postal_code'] ?? '')),
    ], static fn ($l) => trim((string) $l) !== '');

    return '<div class="ts-addr"><div class="ts-addr__label">' . esc($title) . '</div>'
        . '<strong>' . esc($a['contact_name'] ?? '') . '</strong><br>'
        . '<span class="text-muted"><i class="bi bi-telephone me-1"></i>' . esc($a['contact_phone'] ?? '') . '</span><br>'
        . implode('<br>', array_map(static fn ($l) => esc((string) $l), $lines)) . '</div>';
};

$orgToday  = (new DateTimeImmutable('today', new DateTimeZone((string) (current_user()['timezone'] ?? 'Asia/Kolkata'))));
$todayStr  = $orgToday->format('Y-m-d');
$tomorrowStr = $orgToday->modify('+1 day')->format('Y-m-d');
$isReverse = ($shipment['shipment_type'] ?? 'forward') === 'reverse';
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<!-- Hero -->
<div class="ts-hero">
    <div>
        <div class="ts-hero__eyebrow"><?= $isReverse ? 'Reverse shipment' : 'Shipment' ?></div>
        <div class="ts-hero__tracking"><?= esc($shipment['tracking_number']) ?></div>
        <div class="ts-hero__meta mt-1">
            <?= esc($shipment['customer_name'] ?? '—') ?> &middot; created <?= esc(format_datetime($shipment['created_at'])) ?>
            <?php if (! empty($shipment['creator_first'])): ?> by <?= esc(trim($shipment['creator_first'] . ' ' . ($shipment['creator_last'] ?? ''))) ?><?php endif; ?>
        </div>
    </div>
    <div class="text-end">
        <span class="ts-badge ts-badge--lg ts-badge--<?= esc($status, 'attr') ?>"><?= esc($label($status)) ?></span>
        <?php if (! empty($shipment['expected_delivery_at']) && ! in_array($status, ['delivered', 'cancelled', 'rto_delivered'], true)): ?>
            <div class="ts-hero__meta mt-2"><i class="bi bi-clock me-1"></i> ETA <?= esc(format_datetime($shipment['expected_delivery_at'], 'd M Y')) ?></div>
        <?php elseif (! empty($shipment['delivered_at'])): ?>
            <div class="ts-hero__meta mt-2"><i class="bi bi-check2 me-1"></i> Delivered <?= esc(format_datetime($shipment['delivered_at'])) ?></div>
        <?php endif; ?>
    </div>
</div>

<!-- Action bar -->
<div class="ts-actionbar">
    <a href="<?= site_url('shipments') ?>" class="btn btn-soft"><i class="bi bi-arrow-left me-1"></i> All shipments</a>

    <?php if ($canManage): ?>
        <?php if ($actions['ready']): ?>
            <form method="post" action="<?= site_url('shipments/' . (int) $shipment['id'] . '/ready') ?>" class="d-inline" data-ts-once>
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-primary"><i class="bi bi-box-seam me-1"></i> Mark ready to ship</button>
            </form>
        <?php endif; ?>

        <?php if ($actions['book_pickup']): ?>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#pickupModal">
                <i class="bi bi-calendar2-plus me-1"></i> <?= $actions['is_reschedule'] ? 'Re-book pickup' : 'Book pickup' ?>
            </button>
        <?php endif; ?>

        <?php if ($actions['assign_courier']): ?>
            <button type="button" class="btn btn-soft" data-bs-toggle="modal" data-bs-target="#courierModal">
                <i class="bi bi-truck me-1"></i> <?= empty($shipment['courier_partner_id']) ? 'Assign courier' : 'Change courier' ?>
            </button>
        <?php endif; ?>

        <?php foreach ($actions['advance'] as $target): ?>
            <?php [$text, $icon] = $advanceLabels[$target] ?? [$label($target), 'bi-arrow-right-circle']; ?>
            <button type="button" class="btn <?= $target === 'pickup_failed' ? 'btn-outline-danger' : 'btn-primary' ?>"
                    data-bs-toggle="modal" data-bs-target="#advanceModal"
                    data-status="<?= esc($target, 'attr') ?>" data-title="<?= esc($text, 'attr') ?>">
                <i class="bi <?= esc($icon, 'attr') ?> me-1"></i> <?= esc($text) ?>
            </button>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($actions['to_delivery'] && ($canAssign || $canDeliver)): ?>
        <a href="<?= site_url($canAssign ? 'delivery?q=' . rawurlencode($shipment['tracking_number']) : 'delivery/tasks') ?>" class="btn btn-accent">
            <i class="bi bi-bicycle me-1"></i> Open in delivery desk
        </a>
    <?php endif; ?>
    <?php if ($actions['to_ndr'] && $canNdr): ?>
        <a href="<?= site_url('ndr?q=' . rawurlencode($shipment['tracking_number'])) ?>" class="btn btn-accent"><i class="bi bi-exclamation-octagon me-1"></i> Resolve NDR</a>
    <?php endif; ?>
    <?php if ($actions['to_return'] && $canReturn): ?>
        <a href="<?= site_url('returns/create?shipment_id=' . (int) $shipment['id']) ?>" class="btn btn-soft"><i class="bi bi-arrow-return-left me-1"></i> Create return</a>
    <?php endif; ?>

    <?php if ($canManage && $actions['cancel']): ?>
        <button type="button" class="btn btn-outline-danger ms-auto" data-bs-toggle="modal" data-bs-target="#cancelModal">
            <i class="bi bi-x-circle me-1"></i> Cancel shipment
        </button>
    <?php endif; ?>
</div>

<!-- Progress tracker / exception banner -->
<section class="ts-card mb-3">
    <div class="ts-card__body">
        <?php if ($status === 'cancelled'): ?>
            <div class="d-flex align-items-center gap-2 text-muted"><i class="bi bi-slash-circle fs-4"></i> This shipment was cancelled<?= ! empty($shipment['cancelled_at']) ? ' on ' . esc(format_datetime($shipment['cancelled_at'])) : '' ?>.</div>
        <?php else: ?>
            <?php if ($exception): ?>
                <div class="ts-flash ts-flash--warning mb-3" role="status" style="animation:none">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <div class="ts-flash__body">
                        <?php if ($status === 'delivery_failed'): ?>
                            The last delivery attempt failed. Resolve the NDR case to re-attempt delivery or return the parcel to origin.
                        <?php elseif ($status === 'pickup_failed'): ?>
                            The pickup failed. This is a final status in the workflow; create a new shipment if the parcel still needs to move.
                        <?php else: ?>
                            This shipment is on the <strong>return-to-origin</strong> path (<?= esc($label($status)) ?>).
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if (! str_starts_with($status, 'rto_')): ?>
                <ol class="ts-progress">
                    <?php foreach ($flow as $code => $text): ?>
                        <?php
                        $i   = array_search($code, $flowKeys, true);
                        $cls = $flowIndex !== false && $i < $flowIndex ? 'is-done' : ($flowIndex !== false && $i === $flowIndex ? 'is-current' : '');
                        ?>
                        <li class="<?= $cls ?>"><?= esc($text) ?></li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<div class="row g-3">
    <!-- Left column -->
    <div class="col-xl-8">

        <!-- Route -->
        <section class="ts-card mb-3">
            <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-signpost-split"></i> Route</h3></div>
            <div class="ts-card__body">
                <div class="ts-route">
                    <?= $addressBlock($sender, 'From · Sender') ?>
                    <div class="ts-route__arrow"><i class="bi bi-arrow-right-circle-fill"></i></div>
                    <?= $addressBlock($receiver, 'To · Receiver') ?>
                </div>
                <?php if (! empty($shipment['special_instructions'])): ?>
                    <div class="mt-3 p-3 rounded" style="background:var(--ts-coral-50);border:1px dashed var(--ts-coral-400)">
                        <strong><i class="bi bi-exclamation-circle me-1"></i> Instructions:</strong> <?= esc($shipment['special_instructions']) ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Shipment summary -->
        <section class="ts-card mb-3">
            <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-info-circle"></i> Shipment details</h3></div>
            <div class="ts-card__body">
                <dl class="ts-kv">
                    <div><dt>Customer</dt><dd><?= esc($shipment['customer_name'] ?? '—') ?><br><small class="text-muted"><?= esc($shipment['customer_code'] ?? '') ?></small></dd></div>
                    <div><dt>Courier</dt><dd><?= ! empty($shipment['courier_name']) ? esc($shipment['courier_name']) : '<span class="text-muted">Not assigned</span>' ?></dd></div>
                    <div><dt>Courier AWB</dt><dd class="ts-mono"><?= esc($shipment['courier_tracking_number'] ?: '—') ?></dd></div>
                    <div><dt>Pickup location</dt><dd><?= esc($shipment['pickup_location_name'] ?? '—') ?></dd></div>
                    <div><dt>Payment</dt><dd><?= $shipment['payment_mode'] === 'cod' ? '<span class="badge text-bg-warning">COD</span> ' . esc($money($shipment['cod_amount'])) : '<span class="badge text-bg-light border">Prepaid</span>' ?></dd></div>
                    <div><dt>Shipping charge</dt><dd><?= (float) $shipment['shipping_charge'] > 0 ? esc($money($shipment['shipping_charge'])) : '<span class="text-muted">Not rated</span>' ?></dd></div>
                    <div><dt>Declared value</dt><dd><?= esc($money($shipment['declared_value'])) ?></dd></div>
                    <div><dt>Packages</dt><dd><?= (int) $shipment['total_packages'] ?></dd></div>
                    <div><dt>Actual weight</dt><dd><?= esc($kg($shipment['actual_weight_kg'])) ?></dd></div>
                    <div><dt>Volumetric weight</dt><dd><?= esc($kg($shipment['volumetric_weight_kg'])) ?></dd></div>
                    <div><dt>Chargeable weight</dt><dd class="text-coral"><?= esc($kg($shipment['chargeable_weight_kg'])) ?></dd></div>
                    <div><dt>Expected delivery</dt><dd><?= esc(format_datetime($shipment['expected_delivery_at'], 'd M Y')) ?></dd></div>
                </dl>
            </div>
        </section>

        <!-- Items -->
        <section class="ts-card mb-3">
            <div class="ts-card__head">
                <h3 class="ts-card__title"><i class="bi bi-boxes"></i> Items <span class="text-muted fw-normal">(<?= count($items) ?>)</span></h3>
            </div>
            <div class="ts-card__body ts-card__body--flush">
                <?php if ($items === []): ?>
                    <div class="ts-empty"><i class="bi bi-box"></i>No items recorded.</div>
                <?php else: ?>
                    <div class="ts-table-wrap">
                        <table class="ts-table">
                            <thead><tr><th>Item</th><th>SKU</th><th>HSN</th><th class="text-end">Qty</th><th class="text-end">Unit value</th><th class="text-end">Total</th></tr></thead>
                            <tbody>
                            <?php $sum = 0.0; foreach ($items as $it): $sum += (float) $it['total_value']; ?>
                                <tr>
                                    <td class="ts-cell-strong"><?= esc($it['item_name']) ?></td>
                                    <td class="ts-mono"><?= esc($it['sku'] ?: '—') ?></td>
                                    <td class="ts-mono"><?= esc($it['hsn_code'] ?: '—') ?></td>
                                    <td class="text-end"><?= (int) $it['quantity'] ?></td>
                                    <td class="text-end"><?= esc($money($it['unit_value'])) ?></td>
                                    <td class="text-end ts-cell-strong"><?= esc($money($it['total_value'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot><tr><td colspan="5" class="text-end text-muted pe-3">Items total</td><td class="text-end ts-cell-strong"><?= esc($money($sum)) ?></td></tr></tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Pickups -->
        <?php if ($pickups !== []): ?>
            <section class="ts-card mb-3">
                <div class="ts-card__head">
                    <h3 class="ts-card__title"><i class="bi bi-calendar2-check"></i> Pickup requests</h3>
                    <a href="<?= site_url('pickups') ?>" class="btn btn-sm btn-soft">Pickup desk</a>
                </div>
                <div class="ts-card__body ts-card__body--flush">
                    <div class="ts-table-wrap">
                        <table class="ts-table">
                            <thead><tr><th>Date</th><th>Slot</th><th>Location</th><th>Reference</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php foreach ($pickups as $p): ?>
                                <tr>
                                    <td class="ts-cell-strong"><?= esc(date('d M Y', strtotime((string) $p['requested_date']))) ?></td>
                                    <td><?= esc($p['time_slot'] ?: '—') ?></td>
                                    <td><?= esc($p['pickup_location_name'] ?? '—') ?></td>
                                    <td class="ts-mono"><?= esc($p['courier_reference'] ?: '—') ?></td>
                                    <td><?= format_status_badge($p['status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <!-- Delivery -->
        <?php if ($assignments !== [] || $attempts !== [] || $pod !== null): ?>
            <section class="ts-card mb-3">
                <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-bicycle"></i> Last-mile delivery</h3></div>
                <div class="ts-card__body">
                    <?php if ($activeAssignment): ?>
                        <div class="d-flex align-items-center gap-3 mb-3 p-3 rounded" style="background:var(--ts-teal-50);border:1px solid var(--ts-teal-100)">
                            <span class="ts-avatar"><i class="bi bi-person"></i></span>
                            <div>
                                <div class="ts-cell-strong"><?= esc($activeAssignment['agent_name']) ?> <small class="text-muted">(<?= esc($activeAssignment['agent_code']) ?>)</small></div>
                                <small class="text-muted"><i class="bi bi-telephone me-1"></i><?= esc($activeAssignment['agent_phone'] ?? '') ?>
                                    <?php if (! empty($activeAssignment['vehicle_type'])): ?> &middot; <?= esc(ucfirst($activeAssignment['vehicle_type'])) ?> <?= esc($activeAssignment['vehicle_number'] ?? '') ?><?php endif; ?></small>
                            </div>
                            <span class="ms-auto"><?= format_status_badge($activeAssignment['status']) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($attempts !== []): ?>
                        <h4 class="ts-section-label">Delivery attempts</h4>
                        <div class="ts-table-wrap mb-3">
                            <table class="ts-table">
                                <thead><tr><th>#</th><th>When</th><th>Outcome</th><th>Reason / remarks</th></tr></thead>
                                <tbody>
                                <?php foreach ($attempts as $a): ?>
                                    <tr>
                                        <td class="ts-cell-strong"><?= (int) $a['attempt_number'] ?></td>
                                        <td class="text-nowrap"><?= esc(format_datetime($a['attempted_at'])) ?></td>
                                        <td><?= format_status_badge($a['outcome']) ?></td>
                                        <td>
                                            <?= ! empty($a['failure_reason']) ? esc(\App\Services\Delivery\DeliveryService::failureReasons(false)[$a['failure_reason']] ?? $a['failure_reason']) : '' ?>
                                            <?php if (! empty($a['remarks'])): ?><span class="ts-cell-sub"><?= esc($a['remarks']) ?></span><?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <?php if ($pod !== null): ?>
                        <h4 class="ts-section-label">Proof of delivery</h4>
                        <dl class="ts-kv">
                            <div><dt>Received by</dt><dd><?= esc($pod['received_by']) ?></dd></div>
                            <div><dt>Relation</dt><dd><?= esc(\App\Services\Delivery\DeliveryService::receiverRelations(false)[$pod['receiver_relation']] ?? ($pod['receiver_relation'] ?: '—')) ?></dd></div>
                            <div><dt>Verified by</dt><dd><?= esc(\App\Services\Delivery\DeliveryService::verificationMethods(false)[$pod['verification_method']] ?? $pod['verification_method']) ?></dd></div>
                            <div><dt>Reference</dt><dd class="ts-mono"><?= esc($pod['verification_reference'] ?: '—') ?></dd></div>
                            <div><dt>Delivered at</dt><dd><?= esc(format_datetime($pod['delivered_at'])) ?></dd></div>
                        </dl>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- NDR / returns -->
        <?php if ($ndrCases !== [] || $returns !== []): ?>
            <section class="ts-card mb-3">
                <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-exclamation-octagon"></i> Exceptions &amp; returns</h3></div>
                <div class="ts-card__body ts-card__body--flush">
                    <div class="ts-table-wrap">
                        <table class="ts-table">
                            <thead><tr><th>Reference</th><th>Type</th><th>Reason</th><th>Status</th><th>Raised</th></tr></thead>
                            <tbody>
                            <?php foreach ($ndrCases as $n): ?>
                                <tr>
                                    <td class="ts-mono ts-cell-strong"><?= esc($n['case_number']) ?></td>
                                    <td>NDR</td>
                                    <td><?= esc(\App\Services\Delivery\DeliveryService::failureReasons(false)[$n['reason_code']] ?? $n['reason_code']) ?></td>
                                    <td><?= format_status_badge($n['status']) ?></td>
                                    <td class="text-muted"><?= esc(format_datetime($n['created_at'], 'd M Y')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php foreach ($returns as $r): ?>
                                <tr>
                                    <td class="ts-mono ts-cell-strong"><?= esc($r['return_number']) ?></td>
                                    <td>Return</td>
                                    <td><?= esc(\App\Services\Return\ReturnService::reasons(false)[$r['reason_code']] ?? $r['reason_code']) ?></td>
                                    <td><?= format_status_badge($r['status']) ?></td>
                                    <td class="text-muted"><?= esc(format_datetime($r['requested_at'], 'd M Y')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        <?php endif; ?>
    </div>

    <!-- Right column: timeline + documents -->
    <div class="col-xl-4">
        <section class="ts-card mb-3">
            <div class="ts-card__head">
                <h3 class="ts-card__title"><i class="bi bi-clock-history"></i> Timeline</h3>
                <span class="text-muted small"><?= count($events) ?> event<?= count($events) === 1 ? '' : 's' ?></span>
            </div>
            <div class="ts-card__body">
                <?php if ($events === []): ?>
                    <div class="ts-empty"><i class="bi bi-hourglass"></i>No events yet.</div>
                <?php else: ?>
                    <ol class="ts-timeline">
                        <?php foreach ($events as $idx => $ev): ?>
                            <?php
                            $code = (string) $ev['status_code'];
                            $cls  = $idx === 0 ? 'is-current' : (in_array($code, $failedCodes, true) ? 'is-failed' : 'is-done');
                            if ($idx === 0 && in_array($code, $failedCodes, true)) {
                                $cls = 'is-failed';
                            }
                            $by = trim(($ev['first_name'] ?? '') . ' ' . ($ev['last_name'] ?? ''));
                            ?>
                            <li class="ts-timeline__item <?= $cls ?>">
                                <span class="ts-timeline__dot"><i class="bi <?= esc($icons[$code] ?? 'bi-circle', 'attr') ?>"></i></span>
                                <div class="ts-timeline__content">
                                    <p class="ts-timeline__status"><?= esc($ev['title']) ?></p>
                                    <?php if (! empty($ev['description'])): ?><p class="ts-timeline__desc"><?= esc($ev['description']) ?></p><?php endif; ?>
                                    <div class="ts-timeline__meta">
                                        <span><i class="bi bi-calendar3"></i><?= esc(format_datetime($ev['occurred_at'])) ?></span>
                                        <?php if (! empty($ev['event_location'])): ?><span><i class="bi bi-geo-alt"></i><?= esc($ev['event_location']) ?></span><?php endif; ?>
                                        <?php if ($by !== ''): ?><span><i class="bi bi-person"></i><?= esc($by) ?></span><?php endif; ?>
                                        <?php if ((int) $ev['visible_to_customer'] === 0): ?><span title="Not shown on public tracking"><i class="bi bi-eye-slash"></i>Internal</span><?php endif; ?>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </div>
        </section>

        <section class="ts-card mb-3">
            <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-paperclip"></i> Documents</h3></div>
            <div class="ts-card__body">
                <?php if ($documents === []): ?>
                    <p class="text-muted mb-0"><i class="bi bi-file-earmark me-1"></i> No documents attached to this shipment yet.</p>
                <?php else: ?>
                    <?php foreach ($documents as $d): ?>
                        <div class="ts-doc">
                            <i class="bi bi-file-earmark-text fs-4 text-teal"></i>
                            <div class="flex-grow-1 min-w-0">
                                <div class="ts-cell-strong text-truncate"><?= esc($d['original_name']) ?></div>
                                <small class="text-muted"><?= esc(ucwords(str_replace('_', ' ', $d['document_type']))) ?> &middot; <?= esc(format_datetime($d['created_at'], 'd M Y')) ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="ts-card">
            <div class="ts-card__body d-flex align-items-center gap-3">
                <i class="bi bi-qr-code-scan fs-2 text-teal"></i>
                <div>
                    <div class="ts-cell-strong">Public tracking</div>
                    <a href="<?= site_url('track/' . rawurlencode($shipment['tracking_number'])) ?>" target="_blank" rel="noopener" class="small">Open customer tracking page <i class="bi bi-box-arrow-up-right"></i></a>
                </div>
            </div>
        </section>
    </div>
</div>

<?php if ($canManage): ?>

    <?php if ($actions['book_pickup']): ?>
    <!-- Book pickup modal -->
    <div class="modal fade" id="pickupModal" tabindex="-1" aria-labelledby="pickupModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="post" action="<?= site_url('shipments/' . (int) $shipment['id'] . '/pickup') ?>">
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h5 class="modal-title" id="pickupModalLabel"><i class="bi bi-calendar2-plus me-2"></i><?= $actions['is_reschedule'] ? 'Re-book pickup' : 'Book pickup' ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label ts-required" for="pk_location">Pickup location</label>
                            <select name="pickup_location_id" id="pk_location" class="form-select" required>
                                <?php foreach ($locations as $loc): ?>
                                    <option value="<?= (int) $loc['id'] ?>" <?= (int) $loc['id'] === (int) $shipment['pickup_location_id'] ? 'selected' : '' ?>>
                                        <?= esc($loc['name']) ?> — <?= esc($loc['city']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label ts-required" for="pk_date">Pickup date</label>
                            <input type="date" name="requested_date" id="pk_date" class="form-control" min="<?= esc($todayStr, 'attr') ?>" value="<?= esc($tomorrowStr, 'attr') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label ts-required" for="pk_slot">Time slot</label>
                            <select name="time_slot" id="pk_slot" class="form-select" required>
                                <?php foreach ($slots as $slot): ?><option value="<?= esc($slot, 'attr') ?>"><?= esc($slot) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label ts-required" for="pk_courier">Courier</label>
                            <select name="courier_partner_id" id="pk_courier" class="form-select" required>
                                <option value="">Select courier…</option>
                                <?php foreach ($couriers as $cp): ?>
                                    <?php
                                    $blocked = ($shipment['payment_mode'] === 'cod' && (int) $cp['supports_cod'] !== 1)
                                        || ($isReverse && (int) $cp['supports_reverse'] !== 1);
                                    ?>
                                    <option value="<?= (int) $cp['id'] ?>" <?= (int) $cp['id'] === (int) $shipment['courier_partner_id'] ? 'selected' : '' ?> <?= $blocked ? 'disabled' : '' ?>>
                                        <?= esc($cp['name']) ?><?= $blocked ? ($isReverse ? ' (no reverse pickup)' : ' (no COD)') : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="pk_remarks">Remarks</label>
                            <textarea name="remarks" id="pk_remarks" rows="2" maxlength="500" class="form-control" placeholder="Gate code, contact on arrival…"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-soft" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i> Confirm pickup</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($actions['assign_courier']): ?>
    <!-- Assign courier modal -->
    <div class="modal fade" id="courierModal" tabindex="-1" aria-labelledby="courierModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="post" action="<?= site_url('shipments/' . (int) $shipment['id'] . '/courier') ?>">
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h5 class="modal-title" id="courierModalLabel"><i class="bi bi-truck me-2"></i>Assign courier</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label ts-required" for="cr_courier">Courier partner</label>
                    <select name="courier_partner_id" id="cr_courier" class="form-select" required>
                        <option value="">Select courier…</option>
                        <?php foreach ($couriers as $cp): ?>
                            <?php
                            $blocked = ($shipment['payment_mode'] === 'cod' && (int) $cp['supports_cod'] !== 1)
                                || ($isReverse && (int) $cp['supports_reverse'] !== 1);
                            ?>
                            <option value="<?= (int) $cp['id'] ?>" <?= (int) $cp['id'] === (int) $shipment['courier_partner_id'] ? 'selected' : '' ?> <?= $blocked ? 'disabled' : '' ?>>
                                <?= esc($cp['name']) ?><?= $blocked ? ($isReverse ? ' (no reverse pickup)' : ' (no COD)') : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="ts-hint">The shipping charge is re-estimated from the courier's active rate card.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-soft" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save courier</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($actions['advance'] !== []): ?>
    <!-- Advance status modal -->
    <div class="modal fade" id="advanceModal" tabindex="-1" aria-labelledby="advanceModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="post" action="<?= site_url('shipments/' . (int) $shipment['id'] . '/advance') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="status" id="advStatus" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="advanceModalLabel"><i class="bi bi-arrow-right-circle me-2"></i><span id="advTitle">Update status</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="adv_location">Location</label>
                        <input type="text" name="location" id="adv_location" class="form-control" maxlength="180" placeholder="e.g. Bhiwandi Sorting Hub">
                    </div>
                    <div>
                        <label class="form-label" for="adv_remarks">Remarks</label>
                        <textarea name="remarks" id="adv_remarks" rows="2" maxlength="500" class="form-control" placeholder="Optional note shown on the timeline"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-soft" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Update status</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($actions['cancel']): ?>
    <!-- Cancel modal -->
    <div class="modal fade" id="cancelModal" tabindex="-1" aria-labelledby="cancelModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="post" action="<?= site_url('shipments/' . (int) $shipment['id'] . '/cancel') ?>">
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h5 class="modal-title" id="cancelModalLabel"><i class="bi bi-x-circle me-2 text-danger"></i>Cancel shipment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Cancel <strong class="ts-mono"><?= esc($shipment['tracking_number']) ?></strong>? This cannot be undone.</p>
                    <label class="form-label" for="cancel_reason">Reason</label>
                    <textarea name="reason" id="cancel_reason" rows="2" maxlength="400" class="form-control" placeholder="Why is this shipment being cancelled?"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-soft" data-bs-dismiss="modal">Keep shipment</button>
                    <button type="submit" class="btn btn-danger">Cancel shipment</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('advanceModal');
    if (!modal) { return; }
    modal.addEventListener('show.bs.modal', function (ev) {
        var btn = ev.relatedTarget;
        if (!btn) { return; }
        document.getElementById('advStatus').value = btn.getAttribute('data-status') || '';
        document.getElementById('advTitle').textContent = btn.getAttribute('data-title') || 'Update status';
    });
});
</script>
<?= $this->endSection() ?>
