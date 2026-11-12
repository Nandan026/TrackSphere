<?php
/**
 * Customer create / edit form.
 *
 * @var string                     $mode      create|edit
 * @var array<string, mixed>       $customer
 * @var list<array<string, mixed>> $addresses existing addresses (edit)
 * @var list<string>               $types
 * @var list<string>               $addrTypes
 */
$isEdit = $mode === 'edit';
$action = $isEdit ? site_url('customers/' . (int) $customer['id']) : site_url('customers');
$v      = static fn (string $key, string $default = ''): string => (string) old($key, $customer[$key] ?? $default);
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="ts-page-head">
    <div>
        <h2><?= $isEdit ? 'Edit customer' : 'Add customer' ?></h2>
        <p><?= $isEdit ? esc($customer['customer_code']) . ' · ' . esc($customer['name']) : 'Enter customer details and, optionally, a primary address.' ?></p>
    </div>
    <a href="<?= site_url('customers') ?>" class="btn btn-soft"><i class="bi bi-arrow-left me-1"></i> Back to customers</a>
</div>

<form action="<?= $action ?>" method="post" data-ts-once novalidate>
    <?= csrf_field() ?>

    <section class="ts-card mb-3">
        <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-person-vcard"></i> Customer details</h3></div>
        <div class="ts-card__body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="customer_code" class="form-label">Customer code</label>
                    <?php if ($isEdit): ?>
                        <input type="text" id="customer_code" class="form-control ts-mono" value="<?= esc($customer['customer_code'], 'attr') ?>" readonly>
                    <?php else: ?>
                        <input type="text" name="customer_code" id="customer_code" class="form-control ts-mono text-uppercase" maxlength="50"
                               value="<?= esc($v('customer_code'), 'attr') ?>" placeholder="Auto-generated if empty">
                    <?php endif; ?>
                </div>
                <div class="col-md-4">
                    <label for="customer_type" class="form-label">Type *</label>
                    <select name="customer_type" id="customer_type" class="form-select" required>
                        <?php foreach ($types as $type): ?>
                            <option value="<?= esc($type, 'attr') ?>" <?= $v('customer_type', 'business') === $type ? 'selected' : '' ?>><?= esc(ucfirst($type)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="status" class="form-label">Status</label>
                    <select name="status" id="status" class="form-select">
                        <option value="active" <?= $v('status', 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $v('status', 'active') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label for="name" class="form-label">Name *</label>
                    <input type="text" name="name" id="name" class="form-control" maxlength="180" required value="<?= esc($v('name'), 'attr') ?>">
                </div>
                <div class="col-md-6">
                    <label for="contact_person" class="form-label">Contact person</label>
                    <input type="text" name="contact_person" id="contact_person" class="form-control" maxlength="150" value="<?= esc($v('contact_person'), 'attr') ?>">
                </div>
                <div class="col-md-4">
                    <label for="phone" class="form-label">Phone *</label>
                    <input type="text" name="phone" id="phone" class="form-control" maxlength="30" required value="<?= esc($v('phone'), 'attr') ?>">
                </div>
                <div class="col-md-4">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" name="email" id="email" class="form-control" maxlength="190" value="<?= esc($v('email'), 'attr') ?>">
                </div>
                <div class="col-md-4">
                    <label for="gstin" class="form-label">GSTIN</label>
                    <input type="text" name="gstin" id="gstin" class="form-control ts-mono text-uppercase" maxlength="15" value="<?= esc($v('gstin'), 'attr') ?>">
                </div>
            </div>
        </div>
    </section>

    <?php if ($isEdit && $addresses !== []): ?>
        <section class="ts-card mb-3">
            <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-geo-alt"></i> Saved addresses</h3></div>
            <div class="ts-card__body ts-card__body--flush">
                <div class="ts-table-wrap">
                    <table class="ts-table">
                        <thead><tr><th>Type</th><th>Contact</th><th>Address</th><th>Default</th></tr></thead>
                        <tbody>
                            <?php foreach ($addresses as $a): ?>
                                <tr>
                                    <td><?= esc(ucfirst((string) $a['address_type'])) ?></td>
                                    <td>
                                        <span class="ts-cell-strong"><?= esc($a['contact_name']) ?></span>
                                        <span class="ts-cell-sub"><?= esc($a['contact_phone']) ?></span>
                                    </td>
                                    <td>
                                        <?= esc($a['address_line1']) ?><?= ! empty($a['address_line2']) ? ', ' . esc($a['address_line2']) : '' ?>
                                        <span class="ts-cell-sub"><?= esc($a['city']) ?>, <?= esc($a['state']) ?> <?= esc($a['postal_code']) ?></span>
                                    </td>
                                    <td><?= ! empty($a['is_default']) ? '<span class="ts-badge ts-badge--active">Default</span>' : '—' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <section class="ts-card mb-3">
        <div class="ts-card__head">
            <h3 class="ts-card__title">
                <i class="bi bi-geo-alt"></i>
                <?= $isEdit ? 'Add another address' : 'Primary address' ?>
                <span class="text-muted fw-normal small ms-1">(optional)</span>
            </h3>
        </div>
        <div class="ts-card__body">
            <p class="text-muted small mb-3">Leave blank to skip. If you fill any of line 1, city, state or postal code, all required address fields must be completed.</p>
            <?= view('partials/address_fields', ['addr' => [], 'requireContact' => false, 'addrTypes' => $addrTypes]) ?>
        </div>
    </section>

    <div class="d-flex gap-2 justify-content-end">
        <a href="<?= site_url('customers') ?>" class="btn btn-outline-primary">Cancel</a>
        <button type="submit" class="btn btn-accent"><i class="bi bi-check2 me-1"></i> <?= $isEdit ? 'Save changes' : 'Create customer' ?></button>
    </div>
</form>

<?= $this->endSection() ?>
