<?php
/**
 * Pickup locations list.
 *
 * @var list<array<string, mixed>> $locations
 * @var object|null                $pager
 * @var string                     $q
 * @var string                     $status
 */
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="ts-page-head">
    <div>
        <h2>Pickup locations</h2>
        <p>Places where couriers collect your shipments.</p>
    </div>
    <a href="<?= site_url('pickup-locations/create') ?>" class="btn btn-accent"><i class="bi bi-plus-lg me-1"></i> Add Pickup Location</a>
</div>

<section class="ts-card">
    <form method="get" action="<?= site_url('pickup-locations') ?>" class="ts-filters">
        <div class="input-group" style="max-width: 360px;">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="search" name="q" value="<?= esc($q, 'attr') ?>" class="form-control"
                   placeholder="Search name, code, city, postal code" aria-label="Search pickup locations">
        </div>
        <select name="status" class="form-select" style="max-width: 160px;" aria-label="Status filter">
            <option value="">All statuses</option>
            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
        <button type="submit" class="btn btn-soft"><i class="bi bi-funnel me-1"></i> Filter</button>
        <?php if ($q !== '' || $status !== ''): ?>
            <a href="<?= site_url('pickup-locations') ?>" class="btn btn-outline-primary">Reset</a>
        <?php endif; ?>
    </form>

    <div class="ts-card__body ts-card__body--flush">
        <?php if (empty($locations)): ?>
            <div class="ts-empty">
                <i class="bi bi-geo-alt"></i>
                <strong>No pickup locations found</strong>
                <?= ($q !== '' || $status !== '') ? 'Try a different search or filter.' : 'Add a pickup location to schedule courier pickups.' ?>
            </div>
        <?php else: ?>
            <div class="ts-table-wrap">
                <table class="ts-table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Location</th>
                            <th>Address</th>
                            <th>Contact</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($locations as $l): ?>
                            <tr>
                                <td><span class="ts-mono"><?= esc($l['location_code']) ?></span></td>
                                <td>
                                    <span class="ts-cell-strong"><?= esc($l['name']) ?></span>
                                    <?php if (! empty($l['is_default'])): ?>
                                        <span class="ts-badge ts-badge--info">Default</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= esc($l['address_line1'] ?? '—') ?>
                                    <span class="ts-cell-sub"><?= esc(trim(($l['city'] ?? '') . ', ' . ($l['state'] ?? '') . ' ' . ($l['postal_code'] ?? ''), ', ')) ?></span>
                                </td>
                                <td>
                                    <?= esc($l['contact_name'] ?? '—') ?>
                                    <span class="ts-cell-sub"><?= esc($l['contact_phone'] ?? '') ?></span>
                                </td>
                                <td><span class="ts-badge ts-badge--<?= esc($l['status'], 'attr') ?>"><?= esc(ucfirst((string) $l['status'])) ?></span></td>
                                <td class="ts-actions">
                                    <?= view('partials/master_row_actions', [
                                        'base' => 'pickup-locations', 'id' => $l['id'], 'status' => $l['status'],
                                        'label' => 'pickup location', 'name' => $l['name'],
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
