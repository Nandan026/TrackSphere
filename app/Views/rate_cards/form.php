<?php
/**
 * Rate card create / edit form.
 *
 * @var string                     $mode
 * @var array<string, mixed>       $rate
 * @var list<array<string, mixed>> $couriers
 */
$isEdit = $mode === 'edit';
$action = $isEdit ? site_url('rate-cards/' . (int) $rate['id']) : site_url('rate-cards');
$v      = static function (string $key, string $default = '') use ($rate): string {
    $value = (string) old($key, $rate[$key] ?? $default);

    // Show DECIMAL columns without trailing zeros (10.500 -> 10.5), but keep 2 decimals for money-like zero values.
    return preg_match('/^-?\d+\.\d+$/', $value) === 1 ? rtrim(rtrim($value, '0'), '.') : $value;
};
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="ts-page-head">
    <div>
        <h2><?= $isEdit ? 'Edit rate card' : 'Add rate card' ?></h2>
        <p><?= $isEdit ? esc($rate['service_name']) : 'Define pricing for a courier service.' ?></p>
    </div>
    <a href="<?= site_url('rate-cards') ?>" class="btn btn-soft"><i class="bi bi-arrow-left me-1"></i> Back to rate cards</a>
</div>

<form action="<?= $action ?>" method="post" data-ts-once novalidate>
    <?= csrf_field() ?>

    <section class="ts-card mb-3">
        <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-truck"></i> Service &amp; route</h3></div>
        <div class="ts-card__body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="courier_partner_id" class="form-label">Courier</label>
                    <select name="courier_partner_id" id="courier_partner_id" class="form-select">
                        <option value="">— Any courier —</option>
                        <?php foreach ($couriers as $c): ?>
                            <option value="<?= (int) $c['id'] ?>" <?= $v('courier_partner_id') === (string) $c['id'] ? 'selected' : '' ?>>
                                <?= esc($c['name'] . ' (' . $c['code'] . ')' . ($c['status'] !== 'active' ? ' — inactive' : '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <label for="service_name" class="form-label">Service name *</label>
                    <input type="text" name="service_name" id="service_name" class="form-control" maxlength="100" required
                           placeholder="Surface, Express, Air…" value="<?= esc($v('service_name'), 'attr') ?>">
                </div>
                <div class="col-md-3">
                    <label for="status" class="form-label">Status</label>
                    <select name="status" id="status" class="form-select">
                        <option value="active" <?= $v('status', 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $v('status', 'active') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="origin_postal_prefix" class="form-label">Origin postal prefix</label>
                    <input type="text" name="origin_postal_prefix" id="origin_postal_prefix" class="form-control ts-mono" maxlength="10"
                           placeholder="Any" value="<?= esc($v('origin_postal_prefix'), 'attr') ?>">
                </div>
                <div class="col-md-3">
                    <label for="destination_postal_prefix" class="form-label">Destination postal prefix</label>
                    <input type="text" name="destination_postal_prefix" id="destination_postal_prefix" class="form-control ts-mono" maxlength="10"
                           placeholder="Any" value="<?= esc($v('destination_postal_prefix'), 'attr') ?>">
                </div>
                <div class="col-md-3">
                    <label for="effective_from" class="form-label">Effective from *</label>
                    <input type="date" name="effective_from" id="effective_from" class="form-control" required
                           value="<?= esc($v('effective_from', gmdate('Y-m-d')), 'attr') ?>">
                </div>
                <div class="col-md-3">
                    <label for="effective_to" class="form-label">Effective to</label>
                    <input type="date" name="effective_to" id="effective_to" class="form-control" value="<?= esc($v('effective_to'), 'attr') ?>">
                </div>
            </div>
            <p class="text-muted small mt-3 mb-0">Leave the postal prefixes empty to apply the rate to every route.</p>
        </div>
    </section>

    <section class="ts-card mb-3">
        <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-cash-stack"></i> Weight band &amp; charges</h3></div>
        <div class="ts-card__body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label for="min_weight_kg" class="form-label">Min weight (kg) *</label>
                    <input type="number" step="0.001" min="0" name="min_weight_kg" id="min_weight_kg" class="form-control" required value="<?= esc($v('min_weight_kg', '0'), 'attr') ?>">
                </div>
                <div class="col-md-3">
                    <label for="max_weight_kg" class="form-label">Max weight (kg)</label>
                    <input type="number" step="0.001" min="0" name="max_weight_kg" id="max_weight_kg" class="form-control" placeholder="No limit" value="<?= esc($v('max_weight_kg'), 'attr') ?>">
                </div>
                <div class="col-md-3">
                    <label for="base_charge" class="form-label">Base charge (₹)</label>
                    <input type="number" step="0.01" min="0" name="base_charge" id="base_charge" class="form-control" value="<?= esc($v('base_charge', '0'), 'attr') ?>">
                </div>
                <div class="col-md-3">
                    <label for="per_kg_charge" class="form-label">Per-kg charge (₹)</label>
                    <input type="number" step="0.01" min="0" name="per_kg_charge" id="per_kg_charge" class="form-control" value="<?= esc($v('per_kg_charge', '0'), 'attr') ?>">
                </div>
                <div class="col-md-3">
                    <label for="cod_charge" class="form-label">COD charge (₹)</label>
                    <input type="number" step="0.01" min="0" name="cod_charge" id="cod_charge" class="form-control" value="<?= esc($v('cod_charge', '0'), 'attr') ?>">
                </div>
                <div class="col-md-3">
                    <label for="fuel_surcharge_percent" class="form-label">Fuel surcharge (%)</label>
                    <input type="number" step="0.01" min="0" max="100" name="fuel_surcharge_percent" id="fuel_surcharge_percent" class="form-control" value="<?= esc($v('fuel_surcharge_percent', '0'), 'attr') ?>">
                </div>
            </div>
        </div>
    </section>

    <div class="d-flex gap-2 justify-content-end">
        <a href="<?= site_url('rate-cards') ?>" class="btn btn-outline-primary">Cancel</a>
        <button type="submit" class="btn btn-accent"><i class="bi bi-check2 me-1"></i> <?= $isEdit ? 'Save changes' : 'Create rate card' ?></button>
    </div>
</form>

<?= $this->endSection() ?>
