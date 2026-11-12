<?php
/**
 * NDR cases.
 *
 * Controller passes: $cases, $pager, $status (open|resolved|all), $q, $counts, $agents,
 * $reasons, $responses, $timezone.
 */
$qs        = static fn (array $p): string => http_build_query(array_filter($p, static fn ($v) => $v !== '' && $v !== null));
$minLocal  = (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format('Y-m-d\TH:i');
$defaultAt = (new DateTimeImmutable('tomorrow 11:00', new DateTimeZone($timezone)))->format('Y-m-d\TH:i');
$tabs = [
    'open'     => ['Open', (int) ($counts['open'] ?? 0)],
    'resolved' => ['Resolved', (int) ($counts['resolved'] ?? 0)],
    'all'      => ['All', array_sum($counts)],
];
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="ts-page-head">
    <div>
        <h2>NDR cases</h2>
        <p>Non-delivery reports raised from failed attempts. Resolve each case with a re-attempt or return to origin (RTO).</p>
    </div>
    <a href="<?= site_url('delivery') ?>" class="btn btn-soft"><i class="bi bi-bicycle me-1"></i> Delivery desk</a>
</div>

<div class="ts-chips">
    <?php foreach ($tabs as $key => [$text, $count]): ?>
        <a href="<?= site_url('ndr?' . $qs(['status' => $key, 'q' => $q])) ?>" class="ts-chip<?= $status === $key ? ' active' : '' ?>">
            <?= esc($text) ?> <span class="ts-chip__count"><?= esc($count) ?></span>
        </a>
    <?php endforeach; ?>
</div>

<section class="ts-card">
    <form method="get" action="<?= site_url('ndr') ?>" class="ts-filters" role="search">
        <input type="hidden" name="status" value="<?= esc($status, 'attr') ?>">
        <div class="flex-grow-1" style="min-width:240px">
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="search" name="q" value="<?= esc($q, 'attr') ?>" class="form-control" placeholder="Case #, tracking #, receiver or city" maxlength="60">
            </div>
        </div>
        <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i> Search</button>
        <?php if ($q !== ''): ?><a href="<?= site_url('ndr?status=' . esc($status, 'url')) ?>" class="btn btn-soft">Reset</a><?php endif; ?>
    </form>

    <div class="ts-card__body ts-card__body--flush">
        <?php if ($cases === []): ?>
            <div class="ts-empty">
                <i class="bi bi-emoji-smile"></i>
                <strong>No <?= $status === 'all' ? '' : esc($status) . ' ' ?>NDR cases</strong>
                Cases are opened automatically when a delivery attempt fails.
            </div>
        <?php else: ?>
            <div class="ts-table-wrap">
                <table class="ts-table">
                    <thead>
                        <tr>
                            <th>Case</th>
                            <th>Shipment</th>
                            <th>Receiver</th>
                            <th>Reason</th>
                            <th>Attempts</th>
                            <th>Status</th>
                            <th>Opened</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($cases as $c): ?>
                        <?php $open = $c['status'] === 'open'; $canAction = $open && $c['shipment_status'] === 'delivery_failed'; ?>
                        <tr>
                            <td class="ts-mono ts-cell-strong"><?= esc($c['case_number']) ?></td>
                            <td>
                                <a href="<?= site_url('shipments/' . (int) $c['shipment_id']) ?>" class="ts-mono ts-cell-strong"><?= esc($c['tracking_number']) ?></a>
                                <span class="ts-cell-sub"><?= format_status_badge($c['shipment_status']) ?></span>
                                <?php if ($c['payment_mode'] === 'cod'): ?><span class="ts-cell-sub">COD <?= esc(format_money($c['cod_amount'])) ?></span><?php endif; ?>
                            </td>
                            <td>
                                <span class="ts-cell-strong"><?= esc($c['receiver_name'] ?? '—') ?></span>
                                <span class="ts-cell-sub"><?= esc($c['receiver_city'] ?? '') ?> · <?= esc($c['receiver_phone'] ?? '') ?></span>
                            </td>
                            <td>
                                <?= esc($reasons[$c['reason_code']] ?? ucwords(str_replace('_', ' ', $c['reason_code']))) ?>
                                <?php if (! empty($c['agent_name'])): ?><span class="ts-cell-sub"><i class="bi bi-person me-1"></i><?= esc($c['agent_name']) ?></span><?php endif; ?>
                                <?php if (! empty($c['remarks'])): ?><span class="ts-cell-sub text-truncate-cell" title="<?= esc($c['remarks'], 'attr') ?>"><?= esc($c['remarks']) ?></span><?php endif; ?>
                            </td>
                            <td><?= (int) $c['attempts'] ?></td>
                            <td>
                                <?= format_status_badge($c['status']) ?>
                                <?php if (! $open): ?>
                                    <span class="ts-cell-sub">
                                        <?= $c['resolution'] === 'reattempt' ? 'Re-attempt' : 'RTO initiated' ?>
                                        <?php if (! empty($c['next_attempt_at'])): ?> · <?= esc(format_datetime($c['next_attempt_at'], 'd M, h:i A')) ?><?php endif; ?>
                                    </span>
                                    <?php if (! empty($c['customer_response'])): ?><span class="ts-cell-sub"><?= esc($responses[$c['customer_response']] ?? $c['customer_response']) ?></span><?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted text-nowrap"><?= esc(format_datetime($c['created_at'], 'd M Y')) ?></td>
                            <td class="ts-actions">
                                <?php if ($canAction): ?>
                                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#resolveModal"
                                            data-ndr-id="<?= (int) $c['id'] ?>" data-case="<?= esc($c['case_number'], 'attr') ?>" data-tracking="<?= esc($c['tracking_number'], 'attr') ?>">
                                        <i class="bi bi-check2-square me-1"></i> Resolve
                                    </button>
                                <?php elseif ($open): ?>
                                    <span class="text-muted small" title="Shipment is no longer in Delivery Failed status">Not actionable</span>
                                <?php else: ?>
                                    <?php if (! empty($c['resolver_first'])): ?><span class="ts-cell-sub">by <?= esc(trim($c['resolver_first'] . ' ' . ($c['resolver_last'] ?? ''))) ?></span><?php endif; ?>
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

<!-- Resolve modal -->
<div class="modal fade" id="resolveModal" tabindex="-1" aria-labelledby="resolveModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form class="modal-content" method="post" action="#" id="resolveForm" data-base="<?= esc(site_url('ndr'), 'attr') ?>">
            <?= csrf_field() ?>
            <div class="modal-header">
                <h5 class="modal-title" id="resolveModalLabel"><i class="bi bi-check2-square me-2"></i>Resolve NDR <span class="ts-mono" id="rsCase"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">Shipment <strong class="ts-mono" id="rsTracking"></strong></p>

                <div class="ts-mode" role="radiogroup" aria-label="Resolution">
                    <input type="radio" name="action" id="rs_reattempt" value="reattempt" checked><label for="rs_reattempt"><i class="bi bi-arrow-repeat me-1"></i> Re-attempt delivery</label>
                    <input type="radio" name="action" id="rs_rto" value="rto"><label for="rs_rto"><i class="bi bi-arrow-return-left me-1"></i> Return to origin (RTO)</label>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="rs_response">Customer response</label>
                        <select name="customer_response" id="rs_response" class="form-select">
                            <?php foreach ($responses as $code => $text): ?>
                                <option value="<?= esc($code, 'attr') ?>"><?= esc($text) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6" data-for="reattempt">
                        <label class="form-label ts-required" for="rs_next">Next attempt</label>
                        <input type="datetime-local" name="next_attempt_at" id="rs_next" class="form-control" min="<?= esc($minLocal, 'attr') ?>" value="<?= esc($defaultAt, 'attr') ?>">
                        <div class="ts-hint">Times are in <?= esc($timezone) ?>.</div>
                    </div>

                    <div class="col-12" data-for="reattempt">
                        <label class="form-label" for="rs_agent">Assign agent <span class="text-muted fw-normal">(optional)</span></label>
                        <select name="delivery_agent_id" id="rs_agent" class="form-select">
                            <option value="">Assign later from the delivery desk</option>
                            <?php foreach ($agents as $a): ?>
                                <option value="<?= (int) $a['id'] ?>"><?= esc($a['full_name']) ?> (<?= esc($a['agent_code']) ?>) — <?= (int) $a['open_tasks'] ?> open</option>
                            <?php endforeach; ?>
                        </select>
                        <div class="ts-hint">The shipment goes back to <strong>Out for Delivery</strong> immediately.</div>
                    </div>

                    <div class="col-12" data-for="rto" hidden>
                        <div class="ts-flash ts-flash--warning mb-0" style="animation:none">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <div class="ts-flash__body">The shipment will move to <strong>RTO Initiated</strong> and head back to the sender.</div>
                        </div>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="rs_remarks">Remarks</label>
                        <textarea name="remarks" id="rs_remarks" rows="2" maxlength="500" class="form-control" placeholder="Notes from the customer call…"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-soft" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-primary">Resolve case</button>
            </div>
        </form>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('resolveModal');
    if (!modal) { return; }
    var form = document.getElementById('resolveForm');
    var next = document.getElementById('rs_next');

    function sync() {
        var rto = document.getElementById('rs_rto').checked;
        form.querySelectorAll('[data-for="reattempt"]').forEach(function (el) { el.hidden = rto; });
        form.querySelectorAll('[data-for="rto"]').forEach(function (el) { el.hidden = !rto; });
        next.required = !rto;
        document.getElementById('rs_response').value = rto ? 'no_response' : 'reattempt';
    }
    form.querySelectorAll('input[name="action"]').forEach(function (r) { r.addEventListener('change', sync); });

    modal.addEventListener('show.bs.modal', function (ev) {
        var btn = ev.relatedTarget;
        if (!btn) { return; }
        form.action = form.getAttribute('data-base') + '/' + btn.getAttribute('data-ndr-id') + '/resolve';
        document.getElementById('rsCase').textContent = btn.getAttribute('data-case') || '';
        document.getElementById('rsTracking').textContent = btn.getAttribute('data-tracking') || '';
        document.getElementById('rs_reattempt').checked = true;
        sync();
    });
    sync();
});
</script>
<?= $this->endSection() ?>
