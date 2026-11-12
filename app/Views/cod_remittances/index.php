<?php
/**
 * COD remittance batches.
 *
 * Controller passes: $remittances, $pager, $status, $courierId, $couriers, $counts, $statuses, $pendingNet, $remittedNet.
 */
$total = array_sum($counts);
$label = static fn (string $s): string => ucwords(str_replace('_', ' ', $s));
$qs    = static fn (array $p): string => http_build_query(array_filter($p, static fn ($v) => $v !== '' && $v !== null && $v !== 0));
$date  = static fn (?string $d): string => $d ? format_datetime($d . ' 00:00:00', 'd M Y', 'UTC') : '—';
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="ts-page-head">
    <div>
        <h2>COD remittances</h2>
        <p>Cash collected by couriers on delivery, grouped into batches and tracked until the money reaches you.</p>
    </div>
    <a href="<?= site_url('cod-remittances/create') ?>" class="btn btn-accent"><i class="bi bi-plus-lg me-1"></i> New remittance</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-sm-6">
        <div class="ts-card ts-kpi ts-kpi--coral">
            <span class="ts-kpi__icon"><i class="bi bi-hourglass-split"></i></span>
            <span class="ts-kpi__body">
                <div class="ts-kpi__label">Awaiting remittance</div>
                <div class="ts-kpi__value"><?= esc(format_money($pendingNet)) ?></div>
                <div class="ts-kpi__hint"><?= esc((int) ($counts['pending'] ?? 0)) ?> pending batch(es), net of deductions</div>
            </span>
        </div>
    </div>
    <div class="col-12 col-sm-6">
        <div class="ts-card ts-kpi ts-kpi--green">
            <span class="ts-kpi__icon"><i class="bi bi-cash-coin"></i></span>
            <span class="ts-kpi__body">
                <div class="ts-kpi__label">Remitted</div>
                <div class="ts-kpi__value"><?= esc(format_money($remittedNet)) ?></div>
                <div class="ts-kpi__hint"><?= esc((int) ($counts['remitted'] ?? 0)) ?> batch(es) received</div>
            </span>
        </div>
    </div>
</div>

<div class="ts-chips">
    <a href="<?= site_url('cod-remittances' . ($courierId > 0 ? '?courier_id=' . $courierId : '')) ?>" class="ts-chip<?= $status === '' ? ' active' : '' ?>">All <span class="ts-chip__count"><?= esc($total) ?></span></a>
    <?php foreach ($statuses as $code): ?>
        <a href="<?= site_url('cod-remittances?' . $qs(['status' => $code, 'courier_id' => $courierId])) ?>" class="ts-chip<?= $status === $code ? ' active' : '' ?>">
            <?= esc($label($code)) ?> <span class="ts-chip__count"><?= esc((int) ($counts[$code] ?? 0)) ?></span>
        </a>
    <?php endforeach; ?>
</div>

<section class="ts-card">
    <form method="get" action="<?= site_url('cod-remittances') ?>" class="ts-filters">
        <div style="min-width:220px">
            <select name="courier_id" class="form-select" aria-label="Courier">
                <option value="">All couriers</option>
                <?php foreach ($couriers as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $courierId === (int) $c['id'] ? 'selected' : '' ?>><?= esc($c['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div style="min-width:170px">
            <select name="status" class="form-select" aria-label="Status">
                <option value="">All statuses</option>
                <?php foreach ($statuses as $code): ?><option value="<?= esc($code, 'attr') ?>" <?= $status === $code ? 'selected' : '' ?>><?= esc($label($code)) ?></option><?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i> Filter</button>
        <?php if ($status !== '' || $courierId > 0): ?><a href="<?= site_url('cod-remittances') ?>" class="btn btn-soft">Reset</a><?php endif; ?>
    </form>

    <div class="ts-card__body ts-card__body--flush">
        <?php if ($remittances === []): ?>
            <div class="ts-empty">
                <i class="bi bi-cash-coin"></i>
                <strong>No COD remittances</strong>
                Create a batch for a courier and period to track the COD money owed to you.
                <div class="mt-3"><a href="<?= site_url('cod-remittances/create') ?>" class="btn btn-accent btn-sm"><i class="bi bi-plus-lg me-1"></i> New remittance</a></div>
            </div>
        <?php else: ?>
            <div class="ts-table-wrap">
                <table class="ts-table">
                    <thead>
                        <tr>
                            <th>Remittance</th>
                            <th>Courier</th>
                            <th>Period</th>
                            <th class="text-end">COD collected</th>
                            <th class="text-end">Deductions</th>
                            <th class="text-end">Net</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($remittances as $r): ?>
                        <tr>
                            <td class="ts-mono ts-cell-strong">
                                <?= esc($r['remittance_number']) ?>
                                <?php if (! empty($r['reference_number'])): ?><span class="ts-cell-sub">Ref <?= esc($r['reference_number']) ?></span><?php endif; ?>
                            </td>
                            <td><?= esc($r['courier_name'] ?? '—') ?></td>
                            <td class="text-nowrap text-muted"><?= esc($date($r['period_start'])) ?> – <?= esc($date($r['period_end'])) ?></td>
                            <td class="text-end"><?= esc(format_money($r['total_cod_amount'])) ?></td>
                            <td class="text-end text-muted"><?= esc(format_money($r['deductions'])) ?></td>
                            <td class="text-end ts-cell-strong"><?= esc(format_money($r['net_amount'])) ?></td>
                            <td>
                                <?= format_status_badge($r['status']) ?>
                                <?php if (! empty($r['remitted_at'])): ?><span class="ts-cell-sub"><?= esc(format_datetime($r['remitted_at'], 'd M Y')) ?></span><?php endif; ?>
                            </td>
                            <td class="ts-actions">
                                <?php if ($r['status'] === 'pending'): ?>
                                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#remitModal"
                                            data-id="<?= (int) $r['id'] ?>" data-number="<?= esc($r['remittance_number'], 'attr') ?>"
                                            data-net="<?= esc(format_money($r['net_amount']), 'attr') ?>" data-ref="<?= esc((string) ($r['reference_number'] ?? ''), 'attr') ?>">
                                        <i class="bi bi-check2-circle me-1"></i> Mark remitted
                                    </button>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
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

<!-- Mark remitted modal -->
<div class="modal fade" id="remitModal" tabindex="-1" aria-labelledby="remitModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="#" id="remitForm" data-base="<?= esc(site_url('cod-remittances'), 'attr') ?>">
            <?= csrf_field() ?>
            <div class="modal-header">
                <h5 class="modal-title" id="remitModalLabel"><i class="bi bi-cash-coin me-2"></i>Mark <span class="ts-mono" id="rmNumber"></span> remitted</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">Confirm that the courier has paid <strong id="rmNet"></strong> to your account.</p>
                <label class="form-label" for="rm_ref">Bank / UTR reference <span class="text-muted">(optional)</span></label>
                <input type="text" name="reference_number" id="rm_ref" class="form-control" maxlength="100" placeholder="e.g. UTR123456789">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-soft" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-primary">Mark remitted</button>
            </div>
        </form>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('remitModal');
    if (!modal) { return; }
    var form = document.getElementById('remitForm');
    modal.addEventListener('show.bs.modal', function (ev) {
        var btn = ev.relatedTarget;
        if (!btn) { return; }
        form.action = form.getAttribute('data-base') + '/' + btn.getAttribute('data-id') + '/remit';
        document.getElementById('rmNumber').textContent = btn.getAttribute('data-number') || '';
        document.getElementById('rmNet').textContent = btn.getAttribute('data-net') || '';
        document.getElementById('rm_ref').value = btn.getAttribute('data-ref') || '';
    });
});
</script>
<?= $this->endSection() ?>
