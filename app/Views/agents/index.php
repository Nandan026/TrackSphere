<?php
/**
 * Delivery agents list.
 *
 * @var list<array<string, mixed>> $agents
 * @var object|null                $pager
 * @var string                     $q
 * @var string                     $status
 */
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="ts-page-head">
    <div>
        <h2>Delivery agents</h2>
        <p>Last-mile agents, their vehicles and availability.</p>
    </div>
    <a href="<?= site_url('agents/create') ?>" class="btn btn-accent"><i class="bi bi-plus-lg me-1"></i> Add Agent</a>
</div>

<section class="ts-card">
    <form method="get" action="<?= site_url('agents') ?>" class="ts-filters">
        <div class="input-group" style="max-width: 360px;">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="search" name="q" value="<?= esc($q, 'attr') ?>" class="form-control"
                   placeholder="Search name, code, phone, vehicle" aria-label="Search agents">
        </div>
        <select name="status" class="form-select" style="max-width: 160px;" aria-label="Status filter">
            <option value="">All statuses</option>
            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
        <button type="submit" class="btn btn-soft"><i class="bi bi-funnel me-1"></i> Filter</button>
        <?php if ($q !== '' || $status !== ''): ?>
            <a href="<?= site_url('agents') ?>" class="btn btn-outline-primary">Reset</a>
        <?php endif; ?>
    </form>

    <div class="ts-card__body ts-card__body--flush">
        <?php if (empty($agents)): ?>
            <div class="ts-empty">
                <i class="bi bi-person-badge"></i>
                <strong>No agents found</strong>
                <?= ($q !== '' || $status !== '') ? 'Try a different search or filter.' : 'Register your first delivery agent.' ?>
            </div>
        <?php else: ?>
            <div class="ts-table-wrap">
                <table class="ts-table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Agent</th>
                            <th>Vehicle</th>
                            <th>Login</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($agents as $a): ?>
                            <tr>
                                <td><span class="ts-mono"><?= esc($a['agent_code']) ?></span></td>
                                <td>
                                    <span class="ts-cell-strong"><?= esc($a['full_name']) ?></span>
                                    <span class="ts-cell-sub"><?= esc($a['phone']) ?></span>
                                </td>
                                <td>
                                    <?= esc($a['vehicle_type'] ?? '—') ?>
                                    <?php if (! empty($a['vehicle_number'])): ?>
                                        <span class="ts-cell-sub ts-mono"><?= esc($a['vehicle_number']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= esc($a['user_email'] ?? '—') ?></td>
                                <td><span class="ts-badge ts-badge--<?= esc($a['status'], 'attr') ?>"><?= esc(ucfirst((string) $a['status'])) ?></span></td>
                                <td class="ts-actions">
                                    <?= view('partials/master_row_actions', [
                                        'base' => 'agents', 'id' => $a['id'], 'status' => $a['status'],
                                        'label' => 'agent', 'name' => $a['full_name'],
                                    ]) ?>
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
