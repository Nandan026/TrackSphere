<?php
/**
 * Warehouse create / edit form.
 *
 * @var string                     $mode
 * @var array<string, mixed>       $warehouse
 * @var array<string, mixed>       $addr
 * @var list<array<string, mixed>> $addressOptions
 */
$isEdit = $mode === 'edit';
$action = $isEdit ? site_url('warehouses/' . (int) $warehouse['id']) : site_url('warehouses');
$v      = static fn (string $key, string $default = ''): string => (string) old($key, $warehouse[$key] ?? $default);
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="ts-page-head">
    <div>
        <h2><?= $isEdit ? 'Edit warehouse' : 'Add warehouse' ?></h2>
        <p><?= $isEdit ? esc($warehouse['warehouse_code']) . ' · ' . esc($warehouse['name']) : 'Register a warehouse or hub.' ?></p>
    </div>
    <a href="<?= site_url('warehouses') ?>" class="btn btn-soft"><i class="bi bi-arrow-left me-1"></i> Back to warehouses</a>
</div>

<form action="<?= $action ?>" method="post" data-ts-once novalidate>
    <?= csrf_field() ?>

    <section class="ts-card mb-3">
        <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-buildings"></i> Warehouse details</h3></div>
        <div class="ts-card__body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label for="name" class="form-label">Name *</label>
                    <input type="text" name="name" id="name" class="form-control" maxlength="150" required value="<?= esc($v('name'), 'attr') ?>">
                </div>
                <div class="col-md-3">
                    <label for="warehouse_code" class="form-label">Warehouse code<?= $isEdit ? ' *' : '' ?></label>
                    <input type="text" name="warehouse_code" id="warehouse_code" class="form-control ts-mono text-uppercase" maxlength="50"
                           value="<?= esc($v('warehouse_code'), 'attr') ?>" <?= $isEdit ? 'required' : 'placeholder="Auto-generated if empty"' ?>>
                </div>
                <div class="col-md-3">
                    <label for="status" class="form-label">Status</label>
                    <select name="status" id="status" class="form-select">
                        <option value="active" <?= $v('status', 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $v('status', 'active') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
            </div>
        </div>
    </section>

    <section class="ts-card mb-3">
        <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-signpost-split"></i> Address</h3></div>
        <div class="ts-card__body">
            <?= view('partials/address_picker', ['addressOptions' => $addressOptions, 'addr' => $addr, 'isEdit' => $isEdit]) ?>
        </div>
    </section>

    <div class="d-flex gap-2 justify-content-end">
        <a href="<?= site_url('warehouses') ?>" class="btn btn-outline-primary">Cancel</a>
        <button type="submit" class="btn btn-accent"><i class="bi bi-check2 me-1"></i> <?= $isEdit ? 'Save changes' : 'Create warehouse' ?></button>
    </div>
</form>

<?= $this->endSection() ?>
