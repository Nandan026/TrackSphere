<?php
/**
 * Dispatch desk.
 *
 * Controller passes: $rows, $agents, $kpi, $recentAttempts, $view, $q, $reasons.
 */
$qs = static fn (array $p): string => http_build_query(array_filter($p, static fn ($v) => $v !== '' && $v !== null));

$tabs = [
    ''           => ['All', 'bi-list-ul'],
    'hub'        => ['At hub', 'bi-buildings'],
    'unassigned' => ['Unassigned', 'bi-person-dash'],
    'out'        => ['Out for delivery', 'bi-bicycle'],
];

$kpiCards = [
    ['At destination hub', $kpi['at_hub'], 'bi-buildings', 'teal', 'Ready for assignment'],
    ['Out for delivery', $kpi['out_for_delivery'], 'bi-bicycle', 'blue', 'With agents right now'],
    ['Unassigned', $kpi['unassigned'], 'bi-person-dash', 'coral', 'Need an agent'],
    ['Failed attempts', $kpi['failed'], 'bi-x-octagon', 'coral', 'Awaiting NDR action'],
];
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="ts-page-head">
    <div>
        <h2>Delivery desk</h2>
        <p>Assign delivery agents, dispatch parcels and record last-mile outcomes.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= site_url('delivery/tasks') ?>" class="btn btn-soft"><i class="bi bi-list-task me-1"></i> Agent tasks</a>
        <a href="<?= site_url('ndr') ?>" class="btn btn-accent"><i class="bi bi-exclamation-octagon me-1"></i> NDR cases</a>
    </div>
</div>

<div class="row g-3 mb-4 ts-stagger">
    <?php foreach ($kpiCards as [$name, $value, $icon, $tone, $hint]): ?>
        <div class="col-6 col-xl-3">
            <div class="ts-card ts-kpi ts-kpi--<?= esc($tone, 'attr') ?>">
                <span class="ts-kpi__icon"><i class="bi <?= esc($icon, 'attr') ?>"></i></span>
                <span class="ts-kpi__body">
                    <div class="ts-kpi__label"><?= esc($name) ?></div>
                    <div class="ts-kpi__value"><?= esc(number_format((int) $value)) ?></div>
                    <div class="ts-kpi__hint"><?= esc($hint) ?></div>
                </span>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <div class="col-xl-9">
        <div class="ts-chips">
            <?php foreach ($tabs as $key => [$text, $icon]): ?>
                <a href="<?= site_url('delivery' . ($key !== '' || $q !== '' ? '?' . $qs(['view' => $key, 'q' => $q]) : '')) ?>" class="ts-chip<?= $view === $key ? ' active' : '' ?>">
                    <i class="bi <?= esc($icon, 'attr') ?>"></i> <?= esc($text) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <section class="ts-card">
            <form method="get" action="<?= site_url('delivery') ?>" class="ts-filters" role="search">
                <input type="hidden" name="view" value="<?= esc($view, 'attr') ?>">
                <div class="flex-grow-1" style="min-width:220px">
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="search" name="q" value="<?= esc($q, 'attr') ?>" class="form-control" placeholder="Tracking #, receiver, city or agent" maxlength="60">
                    </div>
                </div>
                <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i> Search</button>
                <?php if ($q !== '' || $view !== ''): ?><a href="<?= site_url('delivery') ?>" class="btn btn-soft">Reset</a><?php endif; ?>
            </form>

            <div class="ts-card__body ts-card__body--flush">
                <?php if ($rows === []): ?>
                    <div class="ts-empty">
                        <i class="bi bi-bicycle"></i>
                        <strong>Nothing to dispatch</strong>
                        Shipments appear here once they reach the destination hub.
                    </div>
                <?php else: ?>
                    <div class="ts-table-wrap">
                        <table class="ts-table">
                            <thead>
                                <tr>
                                    <th>Shipment</th>
                                    <th>Deliver to</th>
                                    <th>COD</th>
                                    <th>Status</th>
                                    <th>Agent</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($rows as $r): ?>
                                <?php
                                $atHub    = $r['current_status'] === 'reached_destination_hub';
                                $assigned = ! empty($r['assignment_id']);
                                ?>
                                <tr>
                                    <td>
                                        <a href="<?= site_url('shipments/' . (int) $r['id']) ?>" class="ts-mono ts-cell-strong"><?= esc($r['tracking_number']) ?></a>
                                        <span class="ts-cell-sub"><?= esc($r['customer_name'] ?? '') ?><?= ! empty($r['courier_name']) ? ' · ' . esc($r['courier_name']) : '' ?></span>
                                        <?php if ((int) $r['attempts'] > 0): ?><span class="ts-cell-sub text-danger"><?= (int) $r['attempts'] ?> previous attempt<?= (int) $r['attempts'] === 1 ? '' : 's' ?></span><?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="ts-cell-strong"><?= esc($r['receiver_name'] ?? '—') ?></span>
                                        <span class="ts-cell-sub text-truncate-cell"><?= esc($r['receiver_address'] ?? '') ?>, <?= esc($r['receiver_city'] ?? '') ?> <?= esc($r['receiver_postal_code'] ?? '') ?></span>
                                        <span class="ts-cell-sub"><i class="bi bi-telephone me-1"></i><?= esc($r['receiver_phone'] ?? '') ?></span>
                                    </td>
                                    <td>
                                        <?= $r['payment_mode'] === 'cod'
                                            ? '<span class="badge text-bg-warning">COD</span><span class="ts-cell-sub">' . esc(format_money($r['cod_amount'])) . '</span>'
                                            : '<span class="badge text-bg-light border">Prepaid</span>' ?>
                                    </td>
                                    <td><?= format_status_badge($r['current_status']) ?></td>
                                    <td>
                                        <?php if ($assigned): ?>
                                            <span class="ts-cell-strong"><?= esc($r['agent_name']) ?></span>
                                            <span class="ts-cell-sub"><?= format_status_badge($r['assignment_status']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">Unassigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="ts-actions">
                                        <button type="button" class="btn btn-sm <?= $assigned ? 'btn-soft' : 'btn-primary' ?>"
                                                data-bs-toggle="modal" data-bs-target="#assignModal"
                                                data-shipment-id="<?= (int) $r['id'] ?>" data-tracking="<?= esc($r['tracking_number'], 'attr') ?>"
                                                data-hub="<?= $atHub ? '1' : '0' ?>" data-agent="<?= (int) ($r['agent_id'] ?? 0) ?>">
                                            <i class="bi bi-person-plus me-1"></i><?= $assigned ? 'Reassign' : 'Assign' ?>
                                        </button>
                                        <?php if ($atHub && $assigned): ?>
                                            <form method="post" action="<?= site_url('delivery/' . (int) $r['id'] . '/out-for-delivery') ?>" class="d-inline" data-ts-once>
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="return_to" value="desk">
                                                <button type="submit" class="btn btn-sm btn-accent"><i class="bi bi-send me-1"></i> Dispatch</button>
                                            </form>
                                        <?php elseif (! $atHub): ?>
                                            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#attemptModal"
                                                    data-shipment-id="<?= (int) $r['id'] ?>" data-tracking="<?= esc($r['tracking_number'], 'attr') ?>">
                                                <i class="bi bi-clipboard-check me-1"></i> Outcome
                                            </button>
                                        <?php endif; ?>
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

    <div class="col-xl-3">
        <section class="ts-card mb-3">
            <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-person-badge"></i> Agents</h3></div>
            <div class="ts-card__body">
                <?php if ($agents === []): ?>
                    <p class="text-muted mb-0">No active delivery agents.</p>
                <?php else: ?>
                    <?php foreach ($agents as $a): ?>
                        <div class="d-flex align-items-center gap-2 py-2 border-bottom" style="border-color:var(--ts-slate-100)!important">
                            <span class="ts-avatar" style="width:34px;height:34px;font-size:.75rem"><?= esc(mb_strtoupper(mb_substr($a['full_name'], 0, 1))) ?></span>
                            <div class="flex-grow-1 min-w-0">
                                <div class="ts-cell-strong text-truncate"><?= esc($a['full_name']) ?></div>
                                <small class="text-muted"><?= esc($a['agent_code']) ?> · <?= esc(ucfirst((string) $a['vehicle_type'])) ?></small>
                            </div>
                            <a href="<?= site_url('delivery/tasks?agent_id=' . (int) $a['id']) ?>" class="badge text-bg-<?= (int) $a['open_tasks'] > 0 ? 'primary' : 'light border' ?>" title="Open tasks"><?= (int) $a['open_tasks'] ?> task<?= (int) $a['open_tasks'] === 1 ? '' : 's' ?></a>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="ts-card">
            <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-activity"></i> Recent attempts</h3></div>
            <div class="ts-card__body">
                <?php if ($recentAttempts === []): ?>
                    <p class="text-muted mb-0">No attempts recorded yet.</p>
                <?php else: ?>
                    <?php foreach ($recentAttempts as $t): ?>
                        <div class="d-flex justify-content-between gap-2 py-2">
                            <div>
                                <div class="ts-mono ts-cell-strong"><?= esc($t['tracking_number']) ?></div>
                                <small class="text-muted"><?= esc($t['agent_name'] ?? 'Unassigned') ?> · <?= esc(format_datetime($t['attempted_at'], 'd M, h:i A')) ?></small>
                            </div>
                            <?= format_status_badge($t['outcome']) ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<!-- Assign agent modal -->
<div class="modal fade" id="assignModal" tabindex="-1" aria-labelledby="assignModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?= site_url('delivery/assign') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="shipment_id" id="assignShipmentId" value="">
            <div class="modal-header">
                <h5 class="modal-title" id="assignModalLabel"><i class="bi bi-person-plus me-2"></i>Assign delivery agent</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">Shipment <strong class="ts-mono" id="assignTracking">—</strong></p>
                <div class="mb-3">
                    <label class="form-label ts-required" for="assignAgent">Agent</label>
                    <select name="delivery_agent_id" id="assignAgent" class="form-select" required>
                        <option value="">Select agent…</option>
                        <?php foreach ($agents as $a): ?>
                            <option value="<?= (int) $a['id'] ?>"><?= esc($a['full_name']) ?> (<?= esc($a['agent_code']) ?>) — <?= (int) $a['open_tasks'] ?> open</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="assignNotes">Notes for the agent</label>
                    <textarea name="notes" id="assignNotes" rows="2" maxlength="500" class="form-control" placeholder="Collect COD, call before arriving…"></textarea>
                </div>
                <div class="form-check" id="dispatchWrap">
                    <input class="form-check-input" type="checkbox" name="dispatch_now" value="1" id="dispatchNow" checked>
                    <label class="form-check-label" for="dispatchNow">Dispatch now (mark Out for Delivery)</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-soft" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-primary">Assign agent</button>
            </div>
        </form>
    </div>
</div>

<?= view('delivery/_attempt_modal', ['reasons' => $reasons, 'returnTo' => 'desk']) ?>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('assignModal');
    if (!modal) { return; }
    modal.addEventListener('show.bs.modal', function (ev) {
        var btn = ev.relatedTarget;
        if (!btn) { return; }
        document.getElementById('assignShipmentId').value = btn.getAttribute('data-shipment-id');
        document.getElementById('assignTracking').textContent = btn.getAttribute('data-tracking') || '';
        var agent = btn.getAttribute('data-agent');
        document.getElementById('assignAgent').value = agent && agent !== '0' ? agent : '';
        var atHub = btn.getAttribute('data-hub') === '1';
        document.getElementById('dispatchWrap').hidden = !atHub;
        document.getElementById('dispatchNow').checked = atHub;
    });
});
</script>
<?= $this->endSection() ?>
