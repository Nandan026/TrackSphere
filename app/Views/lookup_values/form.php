<?php
/**
 * Lookup value create / edit form.
 *
 * @var string               $mode
 * @var array<string, mixed> $row
 * @var list<string>         $categories
 */
$isEdit = $mode === 'edit';
$action = $isEdit ? site_url('lookup-values/' . (int) $row['id']) : site_url('lookup-values');
$v      = static fn (string $key, string $default = ''): string => (string) old($key, $row[$key] ?? $default);
$back   = site_url('lookup-values' . (($row['category'] ?? '') !== '' ? '?category=' . rawurlencode((string) $row['category']) : ''));
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="ts-page-head">
    <div>
        <h2><?= $isEdit ? 'Edit lookup value' : 'Add lookup value' ?></h2>
        <p><?= $isEdit ? esc($row['category']) . ' · ' . esc($row['code']) : 'Add an option to a dropdown list.' ?></p>
    </div>
    <a href="<?= $back ?>" class="btn btn-soft"><i class="bi bi-arrow-left me-1"></i> Back to lookup values</a>
</div>

<form action="<?= $action ?>" method="post" data-ts-once novalidate>
    <?= csrf_field() ?>

    <section class="ts-card mb-3">
        <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-list-check"></i> Value details</h3></div>
        <div class="ts-card__body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="category" class="form-label">Category *</label>
                    <?php if ($isEdit): ?>
                        <input type="text" id="category" class="form-control ts-mono" value="<?= esc($row['category'], 'attr') ?>" disabled>
                        <div class="form-text">The category cannot be changed.</div>
                    <?php else: ?>
                        <input type="text" name="category" id="category" class="form-control ts-mono" maxlength="60" required
                               list="categoryList" placeholder="e.g. failure_reason" value="<?= esc($v('category'), 'attr') ?>">
                        <datalist id="categoryList">
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= esc($c, 'attr') ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                        <div class="form-text">Lowercase letters, numbers and underscores.</div>
                    <?php endif; ?>
                </div>
                <div class="col-md-4">
                    <label for="code" class="form-label">Code *</label>
                    <?php if ($isEdit): ?>
                        <input type="text" id="code" class="form-control ts-mono" value="<?= esc($row['code'], 'attr') ?>" disabled>
                        <div class="form-text">The code is stored on records and cannot be changed.</div>
                    <?php else: ?>
                        <input type="text" name="code" id="code" class="form-control ts-mono" maxlength="60" required
                               placeholder="e.g. customer_unavailable" value="<?= esc($v('code'), 'attr') ?>">
                    <?php endif; ?>
                </div>
                <div class="col-md-4">
                    <label for="label" class="form-label">Label *</label>
                    <input type="text" name="label" id="label" class="form-control" maxlength="150" required value="<?= esc($v('label'), 'attr') ?>">
                    <div class="form-text">Shown in dropdowns.</div>
                </div>

                <div class="col-12">
                    <label for="meaning" class="form-label">Meaning</label>
                    <textarea name="meaning" id="meaning" class="form-control" rows="3" maxlength="500"
                              placeholder="Optional plain-English explanation of this option."><?= esc($v('meaning')) ?></textarea>
                </div>

                <div class="col-md-3">
                    <label for="sort_order" class="form-label">Sort order</label>
                    <input type="number" name="sort_order" id="sort_order" class="form-control" min="0" max="100000" value="<?= esc($v('sort_order', '0'), 'attr') ?>">
                </div>
                <div class="col-md-3">
                    <label for="status" class="form-label">State</label>
                    <select name="status" id="status" class="form-select">
                        <option value="active" <?= $v('status', 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $v('status', 'active') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
            </div>
        </div>
    </section>

    <div class="d-flex gap-2 justify-content-end">
        <a href="<?= $back ?>" class="btn btn-outline-primary">Cancel</a>
        <button type="submit" class="btn btn-accent"><i class="bi bi-check2 me-1"></i> <?= $isEdit ? 'Save changes' : 'Add value' ?></button>
    </div>
</form>

<?= $this->endSection() ?>
