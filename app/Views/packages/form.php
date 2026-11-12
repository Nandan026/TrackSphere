<?php
/**
 * Package create / edit form.
 *
 * @var string               $mode
 * @var array<string, mixed> $package
 */
$isEdit = $mode === 'edit';
$action = $isEdit ? site_url('packages/' . (int) $package['id']) : site_url('packages');
$v      = static function (string $key, string $default = '') use ($package): string {
    $value = old($key, $package[$key] ?? $default);

    return is_numeric($value) && str_contains((string) $value, '.') ? rtrim(rtrim((string) $value, '0'), '.') : (string) $value;
};
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="ts-page-head">
    <div>
        <h2><?= $isEdit ? 'Edit package' : 'Add package' ?></h2>
        <p><?= $isEdit ? esc($package['package_code']) . ' · ' . esc($package['package_name']) : 'Define a standard package type.' ?></p>
    </div>
    <a href="<?= site_url('packages') ?>" class="btn btn-soft"><i class="bi bi-arrow-left me-1"></i> Back to packages</a>
</div>

<form action="<?= $action ?>" method="post" data-ts-once novalidate>
    <?= csrf_field() ?>

    <section class="ts-card mb-3">
        <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-box-seam"></i> Package details</h3></div>
        <div class="ts-card__body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label for="package_name" class="form-label">Package name *</label>
                    <input type="text" name="package_name" id="package_name" class="form-control" maxlength="100" required value="<?= esc($v('package_name'), 'attr') ?>">
                </div>
                <div class="col-md-3">
                    <label for="package_code" class="form-label">Package code *</label>
                    <input type="text" name="package_code" id="package_code" class="form-control ts-mono text-uppercase" maxlength="40" required
                           placeholder="BOX-M" value="<?= esc($v('package_code'), 'attr') ?>">
                </div>
                <div class="col-md-3">
                    <label for="status" class="form-label">Status</label>
                    <select name="status" id="status" class="form-select">
                        <option value="active" <?= $v('status', 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $v('status', 'active') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="length_cm" class="form-label">Length (cm)</label>
                    <input type="number" step="0.01" min="0" name="length_cm" id="length_cm" class="form-control" value="<?= esc($v('length_cm'), 'attr') ?>">
                </div>
                <div class="col-md-3">
                    <label for="width_cm" class="form-label">Width (cm)</label>
                    <input type="number" step="0.01" min="0" name="width_cm" id="width_cm" class="form-control" value="<?= esc($v('width_cm'), 'attr') ?>">
                </div>
                <div class="col-md-3">
                    <label for="height_cm" class="form-label">Height (cm)</label>
                    <input type="number" step="0.01" min="0" name="height_cm" id="height_cm" class="form-control" value="<?= esc($v('height_cm'), 'attr') ?>">
                </div>
                <div class="col-md-3">
                    <label for="default_weight_kg" class="form-label">Default weight (kg)</label>
                    <input type="number" step="0.001" min="0" name="default_weight_kg" id="default_weight_kg" class="form-control" value="<?= esc($v('default_weight_kg'), 'attr') ?>">
                </div>
            </div>
            <p class="text-muted small mt-3 mb-0">Volumetric weight = (L × W × H) / 5000. Billing uses the higher of actual and volumetric weight.</p>
        </div>
    </section>

    <div class="d-flex gap-2 justify-content-end">
        <a href="<?= site_url('packages') ?>" class="btn btn-outline-primary">Cancel</a>
        <button type="submit" class="btn btn-accent"><i class="bi bi-check2 me-1"></i> <?= $isEdit ? 'Save changes' : 'Create package' ?></button>
    </div>
</form>

<?= $this->endSection() ?>
