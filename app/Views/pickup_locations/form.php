<?php
/**
 * Pickup location create / edit form.
 *
 * @var string                     $mode
 * @var array<string, mixed>       $location
 * @var array<string, mixed>       $addr
 * @var array{weekdays: string, sunday: string} $hours
 * @var list<array<string, mixed>> $addressOptions
 */
$isEdit = $mode === 'edit';
$action = $isEdit ? site_url('pickup-locations/' . (int) $location['id']) : site_url('pickup-locations');
$v      = static fn (string $key, string $default = ''): string => (string) old($key, $location[$key] ?? $default);
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="ts-page-head">
    <div>
        <h2><?= $isEdit ? 'Edit pickup location' : 'Add pickup location' ?></h2>
        <p><?= $isEdit ? esc($location['location_code']) . ' · ' . esc($location['name']) : 'Where should couriers collect shipments?' ?></p>
    </div>
    <a href="<?= site_url('pickup-locations') ?>" class="btn btn-soft"><i class="bi bi-arrow-left me-1"></i> Back to pickup locations</a>
</div>

<form action="<?= $action ?>" method="post" data-ts-once novalidate>
    <?= csrf_field() ?>

    <section class="ts-card mb-3">
        <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-geo-alt"></i> Location details</h3></div>
        <div class="ts-card__body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label for="name" class="form-label">Name *</label>
                    <input type="text" name="name" id="name" class="form-control" maxlength="150" required value="<?= esc($v('name'), 'attr') ?>">
                </div>
                <div class="col-md-3">
                    <label for="location_code" class="form-label">Location code<?= $isEdit ? ' *' : '' ?></label>
                    <input type="text" name="location_code" id="location_code" class="form-control ts-mono text-uppercase" maxlength="50"
                           value="<?= esc($v('location_code'), 'attr') ?>" <?= $isEdit ? 'required' : 'placeholder="Auto-generated if empty"' ?>>
                </div>
                <div class="col-md-3">
                    <label for="status" class="form-label">Status</label>
                    <select name="status" id="status" class="form-select">
                        <option value="active" <?= $v('status', 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $v('status', 'active') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label for="contact_email" class="form-label">Contact email</label>
                    <input type="email" name="contact_email" id="contact_email" class="form-control" maxlength="190" value="<?= esc($v('contact_email'), 'attr') ?>">
                </div>
                <div class="col-md-3">
                    <label for="hours_weekdays" class="form-label">Hours Mon–Sat</label>
                    <input type="text" name="hours_weekdays" id="hours_weekdays" class="form-control" maxlength="30" placeholder="09:00-19:00"
                           value="<?= esc((string) old('hours_weekdays', $hours['weekdays']), 'attr') ?>">
                </div>
                <div class="col-md-3">
                    <label for="hours_sunday" class="form-label">Hours Sunday</label>
                    <input type="text" name="hours_sunday" id="hours_sunday" class="form-control" maxlength="30" placeholder="closed"
                           value="<?= esc((string) old('hours_sunday', $hours['sunday']), 'attr') ?>">
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="is_default" id="is_default" value="1"
                               <?= (string) old('is_default', (string) ($location['is_default'] ?? '0')) === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_default">Use as the default pickup location</label>
                    </div>
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
        <a href="<?= site_url('pickup-locations') ?>" class="btn btn-outline-primary">Cancel</a>
        <button type="submit" class="btn btn-accent"><i class="bi bi-check2 me-1"></i> <?= $isEdit ? 'Save changes' : 'Create location' ?></button>
    </div>
</form>

<?= $this->endSection() ?>
