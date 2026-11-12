<?php
/**
 * Shipments list.
 *
 * Controller passes: $shipments (rows), $pager, $status, $q, $counts (status => n),
 * $statuses (all status codes), $canCreate.
 */
$total = array_sum($counts);
$label = static fn (string $s): string => ucwords(str_replace('_', ' ', $s));
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="ts-page-head">
    <div>
        <h2>Shipments</h2>
        <p>Track every parcel from draft to delivery. <?= esc(number_format($total)) ?> shipment<?= $total === 1 ? '' : 's' ?> in your organization.</p>
    </div>
    <?php if ($canCreate): ?>
        <a href="<?= site_url('shipments/create') ?>" class="btn btn-accent"><i class="bi bi-plus-lg me-1"></i> New shipment</a>
    <?php endif; ?>
</div>

<!-- Status filter chips -->
<div class="ts-chips">
    <a href="<?= site_url('shipments' . ($q !== '' ? '?q=' . rawurlencode($q) : '')) ?>" class="ts-chip<?= $status === '' ? ' active' : '' ?>">
        All <span class="ts-chip__count"><?= esc($total) ?></span>
    </a>
    <?php foreach ($statuses as $code): ?>
        <?php $n = (int) ($counts[$code] ?? 0); ?>
        <?php if ($n > 0 || $status === $code): ?>
            <a href="<?= site_url('shipments?status=' . rawurlencode($code) . ($q !== '' ? '&q=' . rawurlencode($q) : '')) ?>"
               class="ts-chip<?= $status === $code ? ' active' : '' ?>">
                <?= esc($label($code)) ?> <span class="ts-chip__count"><?= esc($n) ?></span>
            </a>
        <?php endif; ?>
    <?php endforeach; ?>
</div>

<section class="ts-card">
    <form method="get" action="<?= site_url('shipments') ?>" class="ts-filters" role="search">
        <div class="flex-grow-1" style="min-width:240px">
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="search" name="q" value="<?= esc($q, 'attr') ?>" class="form-control"
                       placeholder="Search tracking #, courier AWB, customer or receiver" maxlength="60" aria-label="Search shipments">
            </div>
        </div>
        <div style="min-width:200px">
            <select name="status" class="form-select" aria-label="Filter by status">
                <option value="">All statuses</option>
                <?php foreach ($statuses as $code): ?>
                    <option value="<?= esc($code, 'attr') ?>" <?= $status === $code ? 'selected' : '' ?>><?= esc($label($code)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i> Filter</button>
        <?php if ($q !== '' || $status !== ''): ?>
            <a href="<?= site_url('shipments') ?>" class="btn btn-soft">Reset</a>
        <?php endif; ?>
    </form>

    <div class="ts-card__body ts-card__body--flush">
        <?php if ($shipments === []): ?>
            <div class="ts-empty">
                <i class="bi bi-inboxes"></i>
                <strong>No shipments found</strong>
                <?= ($q !== '' || $status !== '') ? 'Try a different search or clear the filters.' : 'Create your first shipment to get started.' ?>
                <?php if ($canCreate && $q === '' && $status === ''): ?>
                    <div class="mt-3"><a href="<?= site_url('shipments/create') ?>" class="btn btn-accent btn-sm"><i class="bi bi-plus-lg me-1"></i> New shipment</a></div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="ts-table-wrap">
                <table class="ts-table">
                    <thead>
                        <tr>
                            <th>Tracking #</th>
                            <th>Customer</th>
                            <th>Receiver</th>
                            <th>Courier</th>
                            <th>Payment</th>
                            <th class="text-end">Weight</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($shipments as $s): ?>
                        <tr>
                            <td>
                                <a href="<?= site_url('shipments/' . (int) $s['id']) ?>" class="ts-mono ts-cell-strong"><?= esc($s['tracking_number']) ?></a>
                                <?php if (! empty($s['courier_tracking_number'])): ?>
                                    <span class="ts-cell-sub">AWB <?= esc($s['courier_tracking_number']) ?></span>
                                <?php endif; ?>
                                <?php if (($s['shipment_type'] ?? 'forward') === 'reverse'): ?>
                                    <span class="badge text-bg-light border mt-1">Reverse</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="ts-cell-strong"><?= esc($s['customer_name'] ?? '—') ?></span>
                                <span class="ts-cell-sub"><?= esc($s['customer_code'] ?? '') ?></span>
                            </td>
                            <td>
                                <?= esc($s['receiver_city'] ?? '—') ?>
                                <span class="ts-cell-sub"><?= esc(trim(($s['receiver_state'] ?? '') . ' ' . ($s['receiver_postal_code'] ?? ''))) ?></span>
                            </td>
                            <td><?= ! empty($s['courier_name']) ? esc($s['courier_name']) : '<span class="text-muted">Unassigned</span>' ?></td>
                            <td>
                                <?php if ($s['payment_mode'] === 'cod'): ?>
                                    <span class="badge text-bg-warning">COD</span>
                                    <span class="ts-cell-sub"><?= esc(format_money($s['cod_amount'], $s['currency'] ?? 'INR')) ?></span>
                                <?php else: ?>
                                    <span class="badge text-bg-light border">Prepaid</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?= esc(rtrim(rtrim(number_format((float) $s['chargeable_weight_kg'], 3), '0'), '.')) ?> kg</td>
                            <td><?= format_status_badge($s['current_status']) ?></td>
                            <td class="text-muted text-nowrap"><?= esc(format_datetime($s['created_at'], 'd M Y')) ?></td>
                            <td class="ts-actions">
                                <a class="ts-icon-btn" title="View shipment" href="<?= site_url('shipments/' . (int) $s['id']) ?>"><i class="bi bi-eye"></i></a>
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

<?= $this->endSection() ?>
