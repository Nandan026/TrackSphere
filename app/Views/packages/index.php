<?php
/**
 * Packages list.
 *
 * @var list<array<string, mixed>> $packages
 * @var object|null                $pager
 * @var string                     $q
 * @var string                     $status
 */
$dimension = static function (array $p): string {
    if ($p['length_cm'] === null && $p['width_cm'] === null && $p['height_cm'] === null) {
        return '—';
    }

    $fmt = static fn ($n): string => $n === null ? '?' : rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');

    return $fmt($p['length_cm']) . ' × ' . $fmt($p['width_cm']) . ' × ' . $fmt($p['height_cm']) . ' cm';
};
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="ts-page-head">
    <div>
        <h2>Packages</h2>
        <p>Standard package types with dimensions and default weight.</p>
    </div>
    <a href="<?= site_url('packages/create') ?>" class="btn btn-accent"><i class="bi bi-plus-lg me-1"></i> Add Package</a>
</div>

<section class="ts-card">
    <form method="get" action="<?= site_url('packages') ?>" class="ts-filters">
        <div class="input-group" style="max-width: 360px;">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="search" name="q" value="<?= esc($q, 'attr') ?>" class="form-control"
                   placeholder="Search name or code" aria-label="Search packages">
        </div>
        <select name="status" class="form-select" style="max-width: 160px;" aria-label="Status filter">
            <option value="">All statuses</option>
            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
        <button type="submit" class="btn btn-soft"><i class="bi bi-funnel me-1"></i> Filter</button>
        <?php if ($q !== '' || $status !== ''): ?>
            <a href="<?= site_url('packages') ?>" class="btn btn-outline-primary">Reset</a>
        <?php endif; ?>
    </form>

    <div class="ts-card__body ts-card__body--flush">
        <?php if (empty($packages)): ?>
            <div class="ts-empty">
                <i class="bi bi-box-seam"></i>
                <strong>No packages found</strong>
                <?= ($q !== '' || $status !== '') ? 'Try a different search or filter.' : 'Add standard package sizes to speed up shipment creation.' ?>
            </div>
        <?php else: ?>
            <div class="ts-table-wrap">
                <table class="ts-table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Package</th>
                            <th>Dimensions</th>
                            <th>Default weight</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($packages as $p): ?>
                            <tr>
                                <td><span class="ts-mono"><?= esc($p['package_code']) ?></span></td>
                                <td><span class="ts-cell-strong"><?= esc($p['package_name']) ?></span></td>
                                <td><?= esc($dimension($p)) ?></td>
                                <td><?= $p['default_weight_kg'] !== null ? esc(rtrim(rtrim(number_format((float) $p['default_weight_kg'], 3, '.', ''), '0'), '.')) . ' kg' : '—' ?></td>
                                <td><span class="ts-badge ts-badge--<?= esc($p['status'], 'attr') ?>"><?= esc(ucfirst((string) $p['status'])) ?></span></td>
                                <td class="ts-actions">
                                    <?= view('partials/master_row_actions', [
                                        'base' => 'packages', 'id' => $p['id'], 'status' => $p['status'],
                                        'label' => 'package', 'name' => $p['package_name'],
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
