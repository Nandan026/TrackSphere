<?php
/**
 * Courier partners list.
 *
 * @var list<array<string, mixed>> $couriers
 * @var int                        $orgId
 * @var object|null                $pager
 * @var string                     $q
 * @var string                     $status
 */
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="ts-page-head">
    <div>
        <h2>Courier partners</h2>
        <p>Carriers that move your shipments and the services they support.</p>
    </div>
    <a href="<?= site_url('couriers/create') ?>" class="btn btn-accent"><i class="bi bi-plus-lg me-1"></i> Add Courier</a>
</div>

<section class="ts-card">
    <form method="get" action="<?= site_url('couriers') ?>" class="ts-filters">
        <div class="input-group" style="max-width: 360px;">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="search" name="q" value="<?= esc($q, 'attr') ?>" class="form-control"
                   placeholder="Search name or code" aria-label="Search couriers">
        </div>
        <select name="status" class="form-select" style="max-width: 160px;" aria-label="Status filter">
            <option value="">All statuses</option>
            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
        <button type="submit" class="btn btn-soft"><i class="bi bi-funnel me-1"></i> Filter</button>
        <?php if ($q !== '' || $status !== ''): ?>
            <a href="<?= site_url('couriers') ?>" class="btn btn-outline-primary">Reset</a>
        <?php endif; ?>
    </form>

    <div class="ts-card__body ts-card__body--flush">
        <?php if (empty($couriers)): ?>
            <div class="ts-empty">
                <i class="bi bi-truck"></i>
                <strong>No couriers found</strong>
                <?= ($q !== '' || $status !== '') ? 'Try a different search or filter.' : 'Add a courier partner to start booking shipments.' ?>
            </div>
        <?php else: ?>
            <div class="ts-table-wrap">
                <table class="ts-table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Courier</th>
                            <th>Integration</th>
                            <th>Services</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($couriers as $c): ?>
                            <?php $own = (int) ($c['organization_id'] ?? 0) === $orgId; ?>
                            <tr>
                                <td><span class="ts-mono"><?= esc($c['code']) ?></span></td>
                                <td>
                                    <span class="ts-cell-strong"><?= esc($c['name']) ?></span>
                                    <?php if (! $own): ?>
                                        <span class="ts-badge ts-badge--muted">Platform</span>
                                    <?php endif; ?>
                                    <?php if (! empty($c['api_base_url'])): ?>
                                        <span class="ts-cell-sub"><?= esc($c['api_base_url']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= esc(ucfirst((string) $c['integration_type'])) ?></td>
                                <td>
                                    <?php if (! empty($c['supports_cod'])): ?><span class="ts-badge ts-badge--info">COD</span><?php endif; ?>
                                    <?php if (! empty($c['supports_reverse'])): ?><span class="ts-badge ts-badge--info">Reverse</span><?php endif; ?>
                                    <?php if (empty($c['supports_cod']) && empty($c['supports_reverse'])): ?>—<?php endif; ?>
                                </td>
                                <td><span class="ts-badge ts-badge--<?= esc($c['status'], 'attr') ?>"><?= esc(ucfirst((string) $c['status'])) ?></span></td>
                                <td class="ts-actions">
                                    <?= view('partials/master_row_actions', [
                                        'base' => 'couriers', 'id' => $c['id'], 'status' => $c['status'],
                                        'label' => 'courier', 'name' => $c['name'], 'canEdit' => $own,
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
