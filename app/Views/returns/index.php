<?php
/**
 * Return requests.
 *
 * Controller passes: $returns, $pager, $status, $q, $counts, $statuses, $reasons, $canDecide.
 */
$total = array_sum($counts);
$label = static fn (string $s): string => ucwords(str_replace('_', ' ', $s));
$qs    = static fn (array $p): string => http_build_query(array_filter($p, static fn ($v) => $v !== '' && $v !== null));
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="ts-page-head">
    <div>
        <h2>Returns</h2>
        <p>Customer return requests for delivered shipments. Operations approve, reject and receive returns.</p>
    </div>
    <a href="<?= site_url('returns/create') ?>" class="btn btn-accent"><i class="bi bi-plus-lg me-1"></i> New return request</a>
</div>

<div class="ts-chips">
    <a href="<?= site_url('returns' . ($q !== '' ? '?q=' . rawurlencode($q) : '')) ?>" class="ts-chip<?= $status === '' ? ' active' : '' ?>">All <span class="ts-chip__count"><?= esc($total) ?></span></a>
    <?php foreach ($statuses as $code): ?>
        <?php $n = (int) ($counts[$code] ?? 0); ?>
        <a href="<?= site_url('returns?' . $qs(['status' => $code, 'q' => $q])) ?>" class="ts-chip<?= $status === $code ? ' active' : '' ?>">
            <?= esc($label($code)) ?> <span class="ts-chip__count"><?= esc($n) ?></span>
        </a>
    <?php endforeach; ?>
</div>

<section class="ts-card">
    <form method="get" action="<?= site_url('returns') ?>" class="ts-filters" role="search">
        <div class="flex-grow-1" style="min-width:240px">
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="search" name="q" value="<?= esc($q, 'attr') ?>" class="form-control" placeholder="Return #, tracking #, customer or receiver" maxlength="60">
            </div>
        </div>
        <div style="min-width:170px">
            <select name="status" class="form-select" aria-label="Status">
                <option value="">All statuses</option>
                <?php foreach ($statuses as $code): ?><option value="<?= esc($code, 'attr') ?>" <?= $status === $code ? 'selected' : '' ?>><?= esc($label($code)) ?></option><?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i> Filter</button>
        <?php if ($q !== '' || $status !== ''): ?><a href="<?= site_url('returns') ?>" class="btn btn-soft">Reset</a><?php endif; ?>
    </form>

    <div class="ts-card__body ts-card__body--flush">
        <?php if ($returns === []): ?>
            <div class="ts-empty">
                <i class="bi bi-arrow-return-left"></i>
                <strong>No return requests</strong>
                Return requests can be raised for delivered shipments.
                <div class="mt-3"><a href="<?= site_url('returns/create') ?>" class="btn btn-accent btn-sm"><i class="bi bi-plus-lg me-1"></i> New return request</a></div>
            </div>
        <?php else: ?>
            <div class="ts-table-wrap">
                <table class="ts-table">
                    <thead>
                        <tr>
                            <th>Return</th>
                            <th>Original shipment</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th>Reverse shipment</th>
                            <th>Requested</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($returns as $r): ?>
                        <tr>
                            <td class="ts-mono ts-cell-strong"><?= esc($r['return_number']) ?></td>
                            <td>
                                <a href="<?= site_url('shipments/' . (int) $r['shipment_id']) ?>" class="ts-mono ts-cell-strong"><?= esc($r['tracking_number']) ?></a>
                                <span class="ts-cell-sub"><?= esc($r['customer_name'] ?? '') ?> · <?= esc($r['receiver_name'] ?? '') ?>, <?= esc($r['receiver_city'] ?? '') ?></span>
                            </td>
                            <td>
                                <?= esc($reasons[$r['reason_code']] ?? ucwords(str_replace('_', ' ', $r['reason_code']))) ?>
                                <?php if (! empty($r['reason_details'])): ?><span class="ts-cell-sub text-truncate-cell" title="<?= esc($r['reason_details'], 'attr') ?>"><?= esc($r['reason_details']) ?></span><?php endif; ?>
                            </td>
                            <td>
                                <?= format_status_badge($r['status']) ?>
                                <?php if (! empty($r['approved_at']) && in_array($r['status'], ['approved', 'rejected', 'received'], true)): ?>
                                    <span class="ts-cell-sub"><?= esc(format_datetime($r['approved_at'], 'd M Y')) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (! empty($r['reverse_shipment_id'])): ?>
                                    <a href="<?= site_url('shipments/' . (int) $r['reverse_shipment_id']) ?>" class="ts-mono"><?= esc($r['reverse_tracking']) ?></a>
                                    <span class="ts-cell-sub"><?= format_status_badge($r['reverse_status']) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted text-nowrap">
                                <?= esc(format_datetime($r['requested_at'], 'd M Y')) ?>
                                <?php if (! empty($r['requester_first'])): ?><span class="ts-cell-sub">by <?= esc(trim($r['requester_first'] . ' ' . ($r['requester_last'] ?? ''))) ?></span><?php endif; ?>
                            </td>
                            <td class="ts-actions">
                                <?php if ($canDecide && $r['status'] === 'requested'): ?>
                                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#approveModal"
                                            data-return-id="<?= (int) $r['id'] ?>" data-number="<?= esc($r['return_number'], 'attr') ?>">
                                        <i class="bi bi-check2 me-1"></i> Approve
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal"
                                            data-return-id="<?= (int) $r['id'] ?>" data-number="<?= esc($r['return_number'], 'attr') ?>">
                                        <i class="bi bi-x-lg"></i> Reject
                                    </button>
                                <?php elseif ($canDecide && $r['status'] === 'approved'): ?>
                                    <form method="post" action="<?= site_url('returns/' . (int) $r['id'] . '/received') ?>" class="d-inline"
                                          data-confirm="Mark return <?= esc($r['return_number'], 'attr') ?> as received at the warehouse?">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-soft"><i class="bi bi-box-arrow-in-down me-1"></i> Mark received</button>
                                    </form>
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

<?php if ($canDecide): ?>
<!-- Approve modal -->
<div class="modal fade" id="approveModal" tabindex="-1" aria-labelledby="approveModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="#" id="approveForm" data-base="<?= esc(site_url('returns'), 'attr') ?>">
            <?= csrf_field() ?>
            <div class="modal-header">
                <h5 class="modal-title" id="approveModalLabel"><i class="bi bi-check2-circle me-2"></i>Approve return <span class="ts-mono" id="apNumber"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="form-check p-3 rounded" style="background:var(--ts-teal-50);border:1px solid var(--ts-teal-100)">
                    <input class="form-check-input ms-0 me-2" type="checkbox" name="create_reverse" value="1" id="create_reverse" checked>
                    <label class="form-check-label" for="create_reverse"><strong>Create a reverse shipment</strong><br>
                        <small class="text-muted">A draft shipment from the receiver back to the sender is created so a pickup can be booked.</small></label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-soft" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-primary">Approve return</button>
            </div>
        </form>
    </div>
</div>

<!-- Reject modal -->
<div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="#" id="rejectForm" data-base="<?= esc(site_url('returns'), 'attr') ?>">
            <?= csrf_field() ?>
            <div class="modal-header">
                <h5 class="modal-title" id="rejectModalLabel"><i class="bi bi-x-circle me-2 text-danger"></i>Reject return <span class="ts-mono" id="rjNumber"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label class="form-label" for="rj_note">Note for the requester</label>
                <textarea name="note" id="rj_note" rows="3" maxlength="300" class="form-control" placeholder="Why is this return being rejected?"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-soft" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-danger">Reject return</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    function wire(modalId, formId, numberId, action) {
        var modal = document.getElementById(modalId);
        if (!modal) { return; }
        var form = document.getElementById(formId);
        modal.addEventListener('show.bs.modal', function (ev) {
            var btn = ev.relatedTarget;
            if (!btn) { return; }
            form.action = form.getAttribute('data-base') + '/' + btn.getAttribute('data-return-id') + '/' + action;
            document.getElementById(numberId).textContent = btn.getAttribute('data-number') || '';
        });
    }
    wire('approveModal', 'approveForm', 'apNumber', 'approve');
    wire('rejectModal', 'rejectForm', 'rjNumber', 'reject');
});
</script>
<?= $this->endSection() ?>
