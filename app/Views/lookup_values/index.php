<?php
/**
 * Lookup values list (platform-wide).
 *
 * @var list<array<string, mixed>> $rows
 * @var object|null                $pager
 * @var list<string>               $categories
 * @var array{category: string, status: string, q: string} $filter
 */
$filtered = $filter['category'] !== '' || $filter['status'] !== '' || $filter['q'] !== '';
$addUrl   = site_url('lookup-values/create' . ($filter['category'] !== '' ? '?category=' . rawurlencode($filter['category']) : ''));
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="ts-page-head">
    <div>
        <h2>Lookup values</h2>
        <p>Dropdown options such as failure reasons and vehicle types, shared by all organizations.</p>
    </div>
    <a href="<?= $addUrl ?>" class="btn btn-accent"><i class="bi bi-plus-lg me-1"></i> Add Value</a>
</div>

<section class="ts-card">
    <form method="get" action="<?= site_url('lookup-values') ?>" class="ts-filters">
        <select name="category" class="form-select" style="max-width: 240px;" aria-label="Category filter">
            <option value="">All categories</option>
            <?php foreach ($categories as $c): ?>
                <option value="<?= esc($c, 'attr') ?>" <?= $filter['category'] === $c ? 'selected' : '' ?>><?= esc($c) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="input-group" style="max-width: 320px;">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="search" name="q" value="<?= esc($filter['q'], 'attr') ?>" class="form-control"
                   placeholder="Search code, label, meaning" aria-label="Search lookup values">
        </div>
        <select name="status" class="form-select" style="max-width: 160px;" aria-label="Status filter">
            <option value="">All statuses</option>
            <option value="active" <?= $filter['status'] === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $filter['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
        <button type="submit" class="btn btn-soft"><i class="bi bi-funnel me-1"></i> Filter</button>
        <?php if ($filtered): ?>
            <a href="<?= site_url('lookup-values') ?>" class="btn btn-outline-primary">Reset</a>
        <?php endif; ?>
    </form>

    <div class="ts-card__body ts-card__body--flush">
        <?php if (empty($rows)): ?>
            <div class="ts-empty">
                <i class="bi bi-list-check"></i>
                <strong>No lookup values found</strong>
                <?= $filtered ? 'Try a different category or search.' : 'Run "php spark db:seed StatusCatalogSeeder" or add the first value.' ?>
            </div>
        <?php else: ?>
            <div class="ts-table-wrap">
                <table class="ts-table">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Value</th>
                            <th>Meaning</th>
                            <th class="text-center">Order</th>
                            <th>State</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><span class="ts-mono"><?= esc($r['category']) ?></span></td>
                                <td>
                                    <span class="ts-cell-strong"><?= esc($r['label']) ?></span>
                                    <span class="ts-cell-sub ts-mono"><?= esc($r['code']) ?></span>
                                </td>
                                <td><span class="ts-status-glossary__meaning"><?= esc($r['meaning'] ?? '—') ?></span></td>
                                <td class="text-center"><?= (int) $r['sort_order'] ?></td>
                                <td><span class="ts-badge ts-badge--<?= esc($r['status'], 'attr') ?>"><?= esc(ucfirst((string) $r['status'])) ?></span></td>
                                <td class="ts-actions">
                                    <?= view('partials/master_row_actions', [
                                        'base' => 'lookup-values', 'id' => $r['id'], 'status' => $r['status'],
                                        'label' => 'value', 'name' => $r['label'],
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
