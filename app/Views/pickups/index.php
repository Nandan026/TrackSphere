<?php
/**
 * Pickup requests.
 *
 * Controller passes: $pickups, $pager, $status, $q, $date, $counts, $statuses, $awaiting, $canManage.
 */
$total = array_sum($counts);
$label = static fn (string $s): string => ucwords(str_replace('_', ' ', $s));
$qs    = static fn (array $params): string => http_build_query(array_filter($params, static fn ($v) => $v !== '' && $v !== null));
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="ts-page-head">
    <div>
        <h2>Pickups</h2>
        <p>Courier pickups scheduled from the shipment page. Complete a pickup to move its shipment to <strong>Picked Up</strong>.</p>
    </div>
    <a href="<?= site_url('shipments?status=ready_to_ship') ?>" class="btn btn-accent"><i class="bi bi-calendar2-plus me-1"></i> Shipments ready to ship</a>
</div>

<div class="ts-chips">
    <a href="<?= site_url('pickups') ?>" class="ts-chip<?= $status === '' ? ' active' : '' ?>">All <span class="ts-chip__count"><?= esc($total) ?></span></a>
    <?php foreach ($statuses as $code): ?>
        <?php $n = (int) ($counts[$code] ?? 0); ?>
        <?php if ($n > 0 || $status === $code): ?>
            <a href="<?= site_url('pickups?' . $qs(['status' => $code, 'q' => $q, 'date' => $date])) ?>" class="ts-chip<?= $status === $code ? ' active' : '' ?>">
                <?= esc($label($code)) ?> <span class="ts-chip__count"><?= esc($n) ?></span>
            </a>
        <?php endif; ?>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <div class="col-xl-9">
        <section class="ts-card">
            <form method="get" action="<?= site_url('pickups') ?>" class="ts-filters" role="search">
                <div class="flex-grow-1" style="min-width:220px">
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="search" name="q" value="<?= esc($q, 'attr') ?>" class="form-control" placeholder="Tracking #, pickup ref, customer or location" maxlength="60">
                    </div>
                </div>
                <div style="min-width:170px"><input type="date" name="date" value="<?= esc($date, 'attr') ?>" class="form-control" aria-label="Pickup date"></div>
                <div style="min-width:160px">
                    <select name="status" class="form-select" aria-label="Status">
                        <option value="">All statuses</option>
                        <?php foreach ($statuses as $code): ?>
                            <option value="<?= esc($code, 'attr') ?>" <?= $status === $code ? 'selected' : '' ?>><?= esc($label($code)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i> Filter</button>
                <?php if ($q !== '' || $status !== '' || $date !== ''): ?><a href="<?= site_url('pickups') ?>" class="btn btn-soft">Reset</a><?php endif; ?>
            </form>

            <div class="ts-card__body ts-card__body--flush">
                <?php if ($pickups === []): ?>
                    <div class="ts-empty">
                        <i class="bi bi-calendar2-x"></i>
                        <strong>No pickup requests found</strong>
                        Book a pickup from a shipment that is Ready to Ship.
                    </div>
                <?php else: ?>
                    <div class="ts-table-wrap">
                        <table class="ts-table">
                            <thead>
                                <tr>
                                    <th>Shipment</th>
                                    <th>Pickup</th>
                                    <th>Location</th>
                                    <th>Courier</th>
                                    <th>Reference</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($pickups as $p): ?>
                                <?php $open = in_array($p['status'], ['requested', 'scheduled'], true); ?>
                                <tr>
                                    <td>
                                        <a href="<?= site_url('shipments/' . (int) $p['shipment_id']) ?>" class="ts-mono ts-cell-strong"><?= esc($p['tracking_number']) ?></a>
                                        <span class="ts-cell-sub"><?= esc($p['customer_name'] ?? '') ?> &rarr; <?= esc($p['receiver_city'] ?? '—') ?></span>
                                        <span class="ts-cell-sub"><?= format_status_badge($p['shipment_status']) ?></span>
                                    </td>
                                    <td class="text-nowrap">
                                        <span class="ts-cell-strong"><?= esc(date('d M Y', strtotime((string) $p['requested_date']))) ?></span>
                                        <span class="ts-cell-sub"><i class="bi bi-clock me-1"></i><?= esc($p['time_slot'] ?: 'Any time') ?></span>
                                    </td>
                                    <td><?= esc($p['pickup_location_name'] ?? '—') ?><span class="ts-cell-sub"><?= esc($p['pickup_city'] ?? '') ?></span></td>
                                    <td><?= esc($p['courier_name'] ?? '—') ?></td>
                                    <td class="ts-mono"><?= esc($p['courier_reference'] ?: '—') ?></td>
                                    <td>
                                        <?= format_status_badge($p['status']) ?>
                                        <?php if (! empty($p['completed_at'])): ?><span class="ts-cell-sub"><?= esc(format_datetime($p['completed_at'], 'd M, h:i A')) ?></span><?php endif; ?>
                                    </td>
                                    <td class="ts-actions">
                                        <?php if ($open && $canManage): ?>
                                            <form method="post" action="<?= site_url('pickups/' . (int) $p['id'] . '/complete') ?>" class="d-inline"
                                                  data-confirm="Mark pickup for <?= esc($p['tracking_number'], 'attr') ?> as completed? The shipment will move to Picked Up.">
                                                <?= csrf_field() ?>
                                                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check2-circle me-1"></i> Complete</button>
                                            </form>
                                            <form method="post" action="<?= site_url('pickups/' . (int) $p['id'] . '/cancel') ?>" class="d-inline"
                                                  data-confirm="Cancel this pickup request? You can re-book it from the shipment page.">
                                                <?= csrf_field() ?>
                                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i> Cancel</button>
                                            </form>
                                        <?php else: ?>
                                            <a class="ts-icon-btn" title="View shipment" href="<?= site_url('shipments/' . (int) $p['shipment_id']) ?>"><i class="bi bi-eye"></i></a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?= view('partials/pagination', ['pager' => $pager]) ?>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <div class="col-xl-3">
        <section class="ts-card">
            <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-box-seam"></i> Awaiting pickup booking</h3></div>
            <div class="ts-card__body">
                <?php if ($awaiting === []): ?>
                    <p class="text-muted mb-0"><i class="bi bi-check2-all me-1"></i> Nothing waiting — all ready shipments have a pickup.</p>
                <?php else: ?>
                    <div class="d-grid gap-2">
                        <?php foreach ($awaiting as $a): ?>
                            <a href="<?= site_url('shipments/' . (int) $a['id']) ?>" class="ts-quick">
                                <span>
                                    <span class="ts-mono d-block"><?= esc($a['tracking_number']) ?></span>
                                    <small class="text-muted fw-normal"><?= esc($a['customer_name'] ?? '') ?> &rarr; <?= esc($a['receiver_city'] ?? '—') ?></small>
                                </span>
                                <i class="bi bi-arrow-right ts-quick__arrow"></i>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<?= $this->endSection() ?>
