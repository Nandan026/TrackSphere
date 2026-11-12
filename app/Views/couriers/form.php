<?php
/**
 * Courier create / edit form.
 *
 * @var string               $mode
 * @var array<string, mixed> $courier
 * @var list<string>         $integrations
 */
$isEdit = $mode === 'edit';
$action = $isEdit ? site_url('couriers/' . (int) $courier['id']) : site_url('couriers');
$v      = static fn (string $key, string $default = ''): string => (string) old($key, $courier[$key] ?? $default);
$flag   = static fn (string $key): bool => (string) old($key, (string) ($courier[$key] ?? '0')) === '1';
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="ts-page-head">
    <div>
        <h2><?= $isEdit ? 'Edit courier' : 'Add courier' ?></h2>
        <p><?= $isEdit ? esc($courier['code']) . ' · ' . esc($courier['name']) : 'Configure a courier partner.' ?></p>
    </div>
    <a href="<?= site_url('couriers') ?>" class="btn btn-soft"><i class="bi bi-arrow-left me-1"></i> Back to couriers</a>
</div>

<form action="<?= $action ?>" method="post" data-ts-once novalidate>
    <?= csrf_field() ?>

    <section class="ts-card mb-3">
        <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-truck"></i> Courier details</h3></div>
        <div class="ts-card__body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label for="name" class="form-label">Name *</label>
                    <input type="text" name="name" id="name" class="form-control" maxlength="150" required value="<?= esc($v('name'), 'attr') ?>">
                </div>
                <div class="col-md-3">
                    <label for="code" class="form-label">Code *</label>
                    <input type="text" name="code" id="code" class="form-control ts-mono text-uppercase" maxlength="50" required value="<?= esc($v('code'), 'attr') ?>">
                </div>
                <div class="col-md-3">
                    <label for="status" class="form-label">Status</label>
                    <select name="status" id="status" class="form-select">
                        <option value="active" <?= $v('status', 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $v('status', 'active') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="integration_type" class="form-label">Integration type *</label>
                    <select name="integration_type" id="integration_type" class="form-select" required>
                        <?php foreach ($integrations as $type): ?>
                            <option value="<?= esc($type, 'attr') ?>" <?= $v('integration_type', 'simulated') === $type ? 'selected' : '' ?>><?= esc(ucfirst($type)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-8">
                    <label for="api_base_url" class="form-label">API base URL</label>
                    <input type="url" name="api_base_url" id="api_base_url" class="form-control" maxlength="255"
                           placeholder="https://api.courier.example/v1" value="<?= esc($v('api_base_url'), 'attr') ?>">
                    <div class="form-text">Required when the integration type is API.</div>
                </div>
                <div class="col-12 d-flex flex-wrap gap-4">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="supports_cod" id="supports_cod" value="1" <?= $flag('supports_cod') ? 'checked' : '' ?>>
                        <label class="form-check-label" for="supports_cod">Supports Cash on Delivery</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="supports_reverse" id="supports_reverse" value="1" <?= $flag('supports_reverse') ? 'checked' : '' ?>>
                        <label class="form-check-label" for="supports_reverse">Supports reverse pickup</label>
                    </div>
                </div>
                <div class="col-12">
                    <label for="configuration" class="form-label">Configuration (JSON, optional)</label>
                    <textarea name="configuration" id="configuration" rows="5" class="form-control ts-mono" spellcheck="false"
                              placeholder='{"account_id": "...", "service_level": "standard"}'><?= esc($v('configuration')) ?></textarea>
                    <div class="form-text">Stored as part of the courier record. Keep credentials here only if you trust everyone with courier access.</div>
                </div>
            </div>
        </div>
    </section>

    <div class="d-flex gap-2 justify-content-end">
        <a href="<?= site_url('couriers') ?>" class="btn btn-outline-primary">Cancel</a>
        <button type="submit" class="btn btn-accent"><i class="bi bi-check2 me-1"></i> <?= $isEdit ? 'Save changes' : 'Create courier' ?></button>
    </div>
</form>

<?= $this->endSection() ?>
