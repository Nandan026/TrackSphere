<?php
/**
 * Status definition create / edit form.
 *
 * @var string                $mode
 * @var array<string, mixed>  $row
 * @var list<string>          $modules
 * @var list<string>          $badgeClasses
 */
$isEdit = $mode === 'edit';
$action = $isEdit ? site_url('status-definitions/' . (int) $row['id']) : site_url('status-definitions');
$v      = static fn (string $key, string $default = ''): string => (string) old($key, $row[$key] ?? $default);
$back   = site_url('status-definitions' . (($row['module'] ?? '') !== '' ? '?module=' . rawurlencode((string) $row['module']) : ''));
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="ts-page-head">
    <div>
        <h2><?= $isEdit ? 'Edit status' : 'Add status' ?></h2>
        <p><?= $isEdit ? esc($row['module']) . ' · ' . esc($row['code']) : 'Define a status code and explain what it means.' ?></p>
    </div>
    <a href="<?= $back ?>" class="btn btn-soft"><i class="bi bi-arrow-left me-1"></i> Back to glossary</a>
</div>

<form action="<?= $action ?>" method="post" data-ts-once novalidate>
    <?= csrf_field() ?>

    <section class="ts-card mb-3">
        <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-journal-text"></i> Status details</h3></div>
        <div class="ts-card__body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="module" class="form-label">Module *</label>
                    <?php if ($isEdit): ?>
                        <input type="text" id="module" class="form-control ts-mono" value="<?= esc($row['module'], 'attr') ?>" disabled>
                        <div class="form-text">The module cannot be changed.</div>
                    <?php else: ?>
                        <input type="text" name="module" id="module" class="form-control ts-mono" maxlength="40" required
                               list="moduleList" placeholder="e.g. shipments" value="<?= esc($v('module'), 'attr') ?>">
                        <datalist id="moduleList">
                            <?php foreach ($modules as $m): ?>
                                <option value="<?= esc($m, 'attr') ?>"></option>
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
                        <input type="text" name="code" id="code" class="form-control ts-mono" maxlength="40" required
                               placeholder="e.g. out_for_delivery" value="<?= esc($v('code'), 'attr') ?>">
                        <div class="form-text">Must match the value stored in the database.</div>
                    <?php endif; ?>
                </div>
                <div class="col-md-4">
                    <label for="label" class="form-label">Label *</label>
                    <input type="text" name="label" id="label" class="form-control" maxlength="100" required value="<?= esc($v('label'), 'attr') ?>">
                </div>

                <div class="col-12">
                    <label for="meaning" class="form-label">Meaning *</label>
                    <textarea name="meaning" id="meaning" class="form-control" rows="3" maxlength="500" required
                              placeholder="Explain in plain English what this status means."><?= esc($v('meaning')) ?></textarea>
                </div>

                <div class="col-md-3">
                    <label for="badge_class" class="form-label">Badge colour *</label>
                    <select name="badge_class" id="badge_class" class="form-select">
                        <?php foreach ($badgeClasses as $class): ?>
                            <option value="<?= esc($class, 'attr') ?>" <?= $v('badge_class', 'secondary') === $class ? 'selected' : '' ?>><?= esc(ucfirst($class)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Preview: <span class="badge text-bg-<?= esc($v('badge_class', 'secondary'), 'attr') ?>" id="badgePreview"><?= esc($v('label', 'Status') ?: 'Status') ?></span></div>
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
                <div class="col-md-3 d-flex align-items-end">
                    <div class="form-check mb-2">
                        <input type="checkbox" class="form-check-input" name="is_terminal" id="is_terminal" value="1" <?= $v('is_terminal', '0') === '1' ? 'checked' : '' ?>>
                        <label for="is_terminal" class="form-check-label">Final status (nothing follows)</label>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div class="d-flex gap-2 justify-content-end">
        <a href="<?= $back ?>" class="btn btn-outline-primary">Cancel</a>
        <button type="submit" class="btn btn-accent"><i class="bi bi-check2 me-1"></i> <?= $isEdit ? 'Save changes' : 'Add status' ?></button>
    </div>
</form>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    var select = document.getElementById('badge_class');
    var label  = document.getElementById('label');
    var badge  = document.getElementById('badgePreview');
    if (!select || !badge) { return; }
    function sync() {
        badge.className = 'badge text-bg-' + select.value;
        badge.textContent = (label && label.value.trim()) || 'Status';
    }
    select.addEventListener('change', sync);
    if (label) { label.addEventListener('input', sync); }
}());
</script>
<?= $this->endSection() ?>
