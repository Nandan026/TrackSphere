<?php
/**
 * Delivery agent task list.
 *
 * Controller passes: $tasks, $recent, $agents, $agentId, $ownAgent, $canAssign, $reasons.
 */
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="ts-page-head">
    <div>
        <h2>Delivery tasks</h2>
        <p>
            <?php if ($canAssign): ?>
                Tasks for <?= $agentId ? 'the selected agent' : 'all agents' ?>.
            <?php elseif ($ownAgent): ?>
                Your assigned deliveries, <?= esc($ownAgent['full_name']) ?> (<?= esc($ownAgent['agent_code']) ?>).
            <?php else: ?>
                Your login is not linked to a delivery agent profile.
            <?php endif; ?>
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($canAssign): ?>
            <form method="get" action="<?= site_url('delivery/tasks') ?>" class="d-flex gap-2">
                <select name="agent_id" class="form-select" onchange="this.form.submit()" aria-label="Filter by agent">
                    <option value="">All agents</option>
                    <?php foreach ($agents as $a): ?>
                        <option value="<?= (int) $a['id'] ?>" <?= (int) $agentId === (int) $a['id'] ? 'selected' : '' ?>><?= esc($a['full_name']) ?> (<?= (int) $a['open_tasks'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </form>
            <a href="<?= site_url('delivery') ?>" class="btn btn-soft"><i class="bi bi-grid me-1"></i> Dispatch desk</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($tasks === []): ?>
    <section class="ts-card mb-4">
        <div class="ts-empty">
            <i class="bi bi-emoji-smile"></i>
            <strong>No open tasks</strong>
            <?= (! $canAssign && ! $ownAgent) ? 'Ask an administrator to link your user to a delivery agent.' : 'Assigned deliveries will appear here.' ?>
        </div>
    </section>
<?php else: ?>
    <div class="row g-3 mb-4 ts-stagger">
        <?php foreach ($tasks as $t): ?>
            <?php
            $atHub = $t['current_status'] === 'reached_destination_hub';
            $ofd   = $t['current_status'] === 'out_for_delivery';
            $addr  = trim(implode(', ', array_filter([$t['address_line1'], $t['address_line2'], $t['landmark'], $t['city'] . ' ' . $t['postal_code']])));
            ?>
            <div class="col-md-6 col-xxl-4">
                <article class="ts-card ts-task">
                    <div class="ts-task__head">
                        <div>
                            <a href="<?= site_url('shipments/' . (int) $t['shipment_id']) ?>" class="ts-mono ts-cell-strong"><?= esc($t['tracking_number']) ?></a>
                            <div class="small text-muted"><?= esc($t['customer_name'] ?? '') ?><?= $canAssign ? ' · ' . esc($t['agent_name']) : '' ?></div>
                        </div>
                        <?= format_status_badge($t['current_status']) ?>
                    </div>

                    <div>
                        <div class="ts-cell-strong"><i class="bi bi-person me-1 text-teal"></i><?= esc($t['receiver_name'] ?? '—') ?></div>
                        <div class="small"><i class="bi bi-geo-alt me-1 text-teal"></i><?= esc($addr) ?></div>
                        <a class="small" href="tel:<?= esc(preg_replace('/[^0-9+]/', '', (string) $t['receiver_phone']), 'attr') ?>"><i class="bi bi-telephone me-1 text-teal"></i><?= esc($t['receiver_phone'] ?? '') ?></a>
                    </div>

                    <div class="d-flex flex-wrap gap-2 small text-muted">
                        <span><i class="bi bi-box-seam me-1"></i><?= (int) $t['total_packages'] ?> pkg</span>
                        <span><i class="bi bi-speedometer2 me-1"></i><?= esc(rtrim(rtrim(number_format((float) $t['chargeable_weight_kg'], 3), '0'), '.')) ?> kg</span>
                        <?php if ((int) $t['attempts'] > 0): ?><span class="text-danger"><i class="bi bi-arrow-repeat me-1"></i><?= (int) $t['attempts'] ?> prior attempt<?= (int) $t['attempts'] === 1 ? '' : 's' ?></span><?php endif; ?>
                    </div>

                    <?php if ($t['payment_mode'] === 'cod' && (float) $t['cod_amount'] > 0): ?>
                        <div class="ts-task__cod"><i class="bi bi-cash-coin me-1"></i> Collect <?= esc(format_money($t['cod_amount'])) ?> in cash / UPI</div>
                    <?php endif; ?>
                    <?php if (! empty($t['notes']) || ! empty($t['special_instructions'])): ?>
                        <div class="small p-2 rounded" style="background:var(--ts-slate-50);border:1px solid var(--ts-border)">
                            <i class="bi bi-chat-left-text me-1"></i><?= esc($t['notes'] ?: $t['special_instructions']) ?>
                        </div>
                    <?php endif; ?>

                    <div class="ts-task__actions">
                        <?php if ($atHub): ?>
                            <form method="post" action="<?= site_url('delivery/' . (int) $t['shipment_id'] . '/out-for-delivery') ?>" data-ts-once>
                                <?= csrf_field() ?>
                                <input type="hidden" name="return_to" value="tasks">
                                <button class="btn btn-accent btn-sm" type="submit"><i class="bi bi-send me-1"></i> Start delivery</button>
                            </form>
                        <?php elseif ($ofd): ?>
                            <a href="<?= site_url('delivery/' . (int) $t['shipment_id'] . '/pod') ?>" class="btn btn-primary btn-sm"><i class="bi bi-check2-circle me-1"></i> Deliver &amp; POD</a>
                            <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#attemptModal"
                                    data-shipment-id="<?= (int) $t['shipment_id'] ?>" data-tracking="<?= esc($t['tracking_number'], 'attr') ?>">
                                <i class="bi bi-clipboard-x me-1"></i> Record outcome
                            </button>
                        <?php endif; ?>
                    </div>
                </article>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($recent !== []): ?>
    <section class="ts-card">
        <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-clock-history"></i> Recently completed</h3></div>
        <div class="ts-card__body ts-card__body--flush">
            <div class="ts-table-wrap">
                <table class="ts-table">
                    <thead><tr><th>Shipment</th><th>Receiver</th><?php if ($canAssign): ?><th>Agent</th><?php endif; ?><th>Result</th><th>Closed</th></tr></thead>
                    <tbody>
                    <?php foreach ($recent as $t): ?>
                        <tr>
                            <td><a href="<?= site_url('shipments/' . (int) $t['shipment_id']) ?>" class="ts-mono ts-cell-strong"><?= esc($t['tracking_number']) ?></a></td>
                            <td><?= esc($t['receiver_name'] ?? '—') ?><span class="ts-cell-sub"><?= esc($t['city'] ?? '') ?></span></td>
                            <?php if ($canAssign): ?><td><?= esc($t['agent_name']) ?></td><?php endif; ?>
                            <td><?= format_status_badge($t['assignment_status']) ?></td>
                            <td class="text-muted"><?= esc(format_datetime($t['completed_at'], 'd M, h:i A')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
<?php endif; ?>

<?= view('delivery/_attempt_modal', ['reasons' => $reasons, 'returnTo' => 'tasks']) ?>

<?= $this->endSection() ?>
