<?php
/**
 * New return request.
 *
 * Controller passes: $shipments (delivered, returnable), $reasons, $preset (shipment id).
 */
$errors = session()->getFlashdata('errors');
$errors = is_array($errors) ? $errors : [];
$err    = static fn (string $k): bool => isset($errors[$k]);
$msg    = static fn (string $k): string => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . esc($errors[$k]) . '</div>' : '';

$selected = (int) (old('shipment_id') ?: $preset);
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="ts-page-head">
    <div>
        <h2>New return request</h2>
        <p>Raise a return for a delivered shipment. Operations will review and approve it.</p>
    </div>
    <a href="<?= site_url('returns') ?>" class="btn btn-soft"><i class="bi bi-arrow-left me-1"></i> Back to returns</a>
</div>

<div class="row">
    <div class="col-lg-8 col-xl-6">
        <?php if ($shipments === []): ?>
            <section class="ts-card">
                <div class="ts-empty">
                    <i class="bi bi-box2"></i>
                    <strong>No returnable shipments</strong>
                    Only delivered shipments without an open return can be returned.
                </div>
            </section>
        <?php else: ?>
            <form method="post" action="<?= site_url('returns') ?>" data-ts-once novalidate>
                <?= csrf_field() ?>
                <section class="ts-card">
                    <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-arrow-return-left"></i> Return details</h3></div>
                    <div class="ts-card__body">
                        <div class="mb-3">
                            <label class="form-label ts-required" for="shipment_id">Delivered shipment</label>
                            <select name="shipment_id" id="shipment_id" class="form-select<?= $err('shipment_id') ? ' is-invalid' : '' ?>" required>
                                <option value="">Select shipment…</option>
                                <?php foreach ($shipments as $s): ?>
                                    <option value="<?= (int) $s['id'] ?>" <?= $selected === (int) $s['id'] ? 'selected' : '' ?>>
                                        <?= esc($s['tracking_number']) ?> — <?= esc($s['customer_name'] ?? '') ?> → <?= esc($s['receiver_name'] ?? '') ?>, <?= esc($s['receiver_city'] ?? '') ?>
                                        (delivered <?= esc(format_datetime($s['delivered_at'], 'd M')) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?= $msg('shipment_id') ?>
                        </div>

                        <div class="mb-3">
                            <label class="form-label ts-required" for="reason_code">Reason</label>
                            <select name="reason_code" id="reason_code" class="form-select<?= $err('reason_code') ? ' is-invalid' : '' ?>" required>
                                <option value="">Select reason…</option>
                                <?php foreach ($reasons as $code => $text): ?>
                                    <option value="<?= esc($code, 'attr') ?>" <?= old('reason_code') === $code ? 'selected' : '' ?>><?= esc($text) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?= $msg('reason_code') ?>
                        </div>

                        <div>
                            <label class="form-label" for="reason_details">Details</label>
                            <textarea name="reason_details" id="reason_details" rows="4" maxlength="500" class="form-control<?= $err('reason_details') ? ' is-invalid' : '' ?>"
                                      placeholder="Describe the issue — clear details speed up approval."><?= old('reason_details') ?></textarea>
                            <?= $msg('reason_details') ?>
                        </div>
                    </div>
                    <div class="ts-card__foot d-flex justify-content-end gap-2">
                        <a href="<?= site_url('returns') ?>" class="btn btn-soft">Cancel</a>
                        <button type="submit" class="btn btn-accent"><i class="bi bi-send me-1"></i> Submit request</button>
                    </div>
                </section>
            </form>
        <?php endif; ?>
    </div>
</div>

<?= $this->endSection() ?>
