<?php
/**
 * Status glossary list (platform-wide).
 *
 * @var list<array<string, mixed>> $rows
 * @var object|null                $pager
 * @var list<string>               $modules
 * @var array{module: string, status: string, q: string} $filter
 */
$allowed  = ['success', 'info', 'primary', 'warning', 'danger', 'secondary', 'dark'];
$filtered = $filter['module'] !== '' || $filter['status'] !== '' || $filter['q'] !== '';
$addUrl   = site_url('status-definitions/create' . ($filter['module'] !== '' ? '?module=' . rawurlencode($filter['module']) : ''));
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="ts-page-head">
    <div>
        <h2>Status glossary</h2>
        <p>What every status means, shared by all organizations. Shown in module guides and as badge colours.</p>
    </div>
    <a href="<?= $addUrl ?>" class="btn btn-accent"><i class="bi bi-plus-lg me-1"></i> Add Status</a>
</div>

<section class="ts-card">
    <form method="get" action="<?= site_url('status-definitions') ?>" class="ts-filters">
        <select name="module" class="form-select" style="max-width: 220px;" aria-label="Module filter">
            <option value="">All modules</option>
            <?php foreach ($modules as $m): ?>
                <option value="<?= esc($m, 'attr') ?>" <?= $filter['module'] === $m ? 'selected' : '' ?>><?= esc($m) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="input-group" style="max-width: 320px;">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="search" name="q" value="<?= esc($filter['q'], 'attr') ?>" class="form-control"
                   placeholder="Search code, label, meaning" aria-label="Search statuses">
        </div>
        <select name="status" class="form-select" style="max-width: 160px;" aria-label="Status filter">
            <option value="">All statuses</option>
            <option value="active" <?= $filter['status'] === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $filter['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
        <button type="submit" class="btn btn-soft"><i class="bi bi-funnel me-1"></i> Filter</button>
        <?php if ($filtered): ?>
            <a href="<?= site_url('status-definitions') ?>" class="btn btn-outline-primary">Reset</a>
        <?php endif; ?>
    </form>

    <div class="ts-card__body ts-card__body--flush">
        <?php if (empty($rows)): ?>
            <div class="ts-empty">
                <i class="bi bi-journal-text"></i>
                <strong>No statuses found</strong>
                <?= $filtered ? 'Try a different module or search.' : 'Run "php spark db:seed StatusCatalogSeeder" or add the first status.' ?>
            </div>
        <?php else: ?>
            <div class="ts-table-wrap">
                <table class="ts-table">
                    <thead>
                        <tr>
                            <th>Module</th>
                            <th>Status</th>
                            <th>Meaning</th>
                            <th class="text-center">Order</th>
                            <th class="text-center">Final</th>
                            <th>State</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <?php $badge = in_array($r['badge_class'], $allowed, true) ? $r['badge_class'] : 'secondary'; ?>
                            <tr>
                                <td><span class="ts-mono"><?= esc($r['module']) ?></span></td>
                                <td>
                                    <span class="badge text-bg-<?= esc($badge, 'attr') ?>"><?= esc($r['label']) ?></span>
                                    <span class="ts-cell-sub ts-mono"><?= esc($r['code']) ?></span>
                                </td>
                                <td><span class="ts-status-glossary__meaning"><?= esc($r['meaning']) ?></span></td>
                                <td class="text-center"><?= (int) $r['sort_order'] ?></td>
                                <td class="text-center"><?= (int) $r['is_terminal'] === 1 ? '<i class="bi bi-flag-fill text-muted" title="Final status"></i>' : '—' ?></td>
                                <td><span class="ts-badge ts-badge--<?= esc($r['status'], 'attr') ?>"><?= esc(ucfirst((string) $r['status'])) ?></span></td>
                                <td class="ts-actions">
                                    <?= view('partials/master_row_actions', [
                                        'base' => 'status-definitions', 'id' => $r['id'], 'status' => $r['status'],
                                        'label' => 'status', 'name' => $r['label'],
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
