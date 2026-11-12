<?php
/**
 * Proof of delivery capture / read-only display.
 *
 * Controller passes: $shipment (with receiver address), $pod, $assignment, $relations, $methods.
 */
$errors = session()->getFlashdata('errors');
$errors = is_array($errors) ? $errors : [];
$err    = static fn (string $k): bool => isset($errors[$k]);
$msg    = static fn (string $k): string => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . esc($errors[$k]) . '</div>' : '';

$isCod  = $shipment['payment_mode'] === 'cod' && (float) $shipment['cod_amount'] > 0;
$addr   = trim(implode(', ', array_filter([$shipment['address_line1'], $shipment['address_line2'], $shipment['landmark'], $shipment['city'] . ', ' . $shipment['state'] . ' ' . $shipment['postal_code']])));
$method = old('verification_method') ?: 'name';
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="ts-page-head">
    <div>
        <h2>Proof of delivery</h2>
        <p>Shipment <span class="ts-mono ts-cell-strong"><?= esc($shipment['tracking_number']) ?></span> · <?= format_status_badge($shipment['current_status']) ?></p>
    </div>
    <a href="<?= site_url(user_can('delivery.assign') ? 'delivery' : 'delivery/tasks') ?>" class="btn btn-soft"><i class="bi bi-arrow-left me-1"></i> Back to deliveries</a>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <section class="ts-card">
            <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-geo-alt"></i> Delivery details</h3></div>
            <div class="ts-card__body">
                <dl class="ts-kv" style="grid-template-columns:1fr">
                    <div><dt>Receiver</dt><dd><?= esc($shipment['receiver_name'] ?? '—') ?></dd></div>
                    <div><dt>Phone</dt><dd><?= esc($shipment['receiver_phone'] ?? '—') ?></dd></div>
                    <div><dt>Address</dt><dd><?= esc($addr) ?></dd></div>
                    <div><dt>Customer</dt><dd><?= esc($shipment['customer_name'] ?? '—') ?></dd></div>
                    <?php if ($assignment): ?><div><dt>Agent</dt><dd><?= esc($assignment['agent_name']) ?> (<?= esc($assignment['agent_code']) ?>)</dd></div><?php endif; ?>
                </dl>
                <?php if ($isCod): ?>
                    <div class="ts-task__cod mt-3"><i class="bi bi-cash-coin me-1"></i> Collect <?= esc(format_money($shipment['cod_amount'], $shipment['currency'] ?? 'INR')) ?></div>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <div class="col-lg-7">
        <?php if ($pod): ?>
            <section class="ts-card">
                <div class="ts-card__head">
                    <h3 class="ts-card__title"><i class="bi bi-patch-check"></i> Proof recorded</h3>
                    <?= format_status_badge('delivered') ?>
                </div>
                <div class="ts-card__body">
                    <dl class="ts-kv">
                        <div><dt>Received by</dt><dd><?= esc($pod['received_by']) ?></dd></div>
                        <div><dt>Relation</dt><dd><?= esc($relations[$pod['receiver_relation']] ?? ($pod['receiver_relation'] ?: '—')) ?></dd></div>
                        <div><dt>Verification</dt><dd><?= esc($methods[$pod['verification_method']] ?? $pod['verification_method']) ?></dd></div>
                        <div><dt>Reference</dt><dd class="ts-mono"><?= esc($pod['verification_reference'] ?: '—') ?></dd></div>
                        <div><dt>Delivered at</dt><dd><?= esc(format_datetime($pod['delivered_at'])) ?></dd></div>
                    </dl>
                    <?php if (! empty($pod['notes'])): ?><p class="mt-3 mb-0 text-muted"><i class="bi bi-chat-left-text me-1"></i><?= esc($pod['notes']) ?></p><?php endif; ?>
                    <div class="ts-hint mt-3"><i class="bi bi-lock me-1"></i> Proof of delivery cannot be changed once saved.</div>
                    <a href="<?= site_url('shipments/' . (int) $shipment['id']) ?>" class="btn btn-primary mt-3">View shipment</a>
                </div>
            </section>
        <?php else: ?>
            <form method="post" action="<?= site_url('delivery/' . (int) $shipment['id'] . '/pod') ?>" data-ts-once novalidate>
                <?= csrf_field() ?>
                <section class="ts-card">
                    <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-pen"></i> Capture proof</h3></div>
                    <div class="ts-card__body">
                        <div class="row g-3">
                            <div class="col-md-7">
                                <label class="form-label ts-required" for="received_by">Received by</label>
                                <input type="text" name="received_by" id="received_by" class="form-control<?= $err('received_by') ? ' is-invalid' : '' ?>"
                                       value="<?= old('received_by') ?: esc($shipment['receiver_name'] ?? '', 'attr') ?>" maxlength="150" required>
                                <?= $msg('received_by') ?>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label" for="receiver_relation">Relation</label>
                                <select name="receiver_relation" id="receiver_relation" class="form-select<?= $err('receiver_relation') ? ' is-invalid' : '' ?>">
                                    <?php foreach ($relations as $code => $text): ?>
                                        <option value="<?= esc($code, 'attr') ?>" <?= (old('receiver_relation') ?: 'self') === $code ? 'selected' : '' ?>><?= esc($text) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="form-label d-block">Verification method</label>
                                <div class="ts-mode" role="radiogroup" aria-label="Verification method">
                                    <?php foreach ($methods as $code => $text): ?>
                                        <input type="radio" name="verification_method" id="vm_<?= esc($code, 'attr') ?>" value="<?= esc($code, 'attr') ?>" <?= $method === $code ? 'checked' : '' ?>>
                                        <label for="vm_<?= esc($code, 'attr') ?>"><?= esc($text) ?></label>
                                    <?php endforeach; ?>
                                </div>
                                <?= $msg('verification_method') ?>
                            </div>

                            <div class="col-md-7">
                                <label class="form-label" for="verification_reference" id="refLabel">Verification reference</label>
                                <input type="text" name="verification_reference" id="verification_reference" class="form-control<?= $err('verification_reference') ? ' is-invalid' : '' ?>"
                                       value="<?= old('verification_reference') ?>" maxlength="100" autocomplete="off">
                                <?= $msg('verification_reference') ?>
                                <div class="ts-hint" id="refHint">Optional. e.g. signature ID or photo reference.</div>
                            </div>

                            <div class="col-12">
                                <label class="form-label" for="pod_notes">Notes</label>
                                <textarea name="notes" id="pod_notes" rows="2" maxlength="500" class="form-control<?= $err('notes') ? ' is-invalid' : '' ?>" placeholder="Left with security desk, parcel intact…"><?= old('notes') ?></textarea>
                                <?= $msg('notes') ?>
                            </div>

                            <?php if ($isCod): ?>
                                <div class="col-12">
                                    <div class="form-check p-3 rounded" style="background:var(--ts-coral-50);border:1px dashed var(--ts-coral-400)">
                                        <input class="form-check-input ms-0 me-2<?= $err('cod_collected') ? ' is-invalid' : '' ?>" type="checkbox" name="cod_collected" value="1" id="cod_collected" <?= old('cod_collected') ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="cod_collected">I confirm <strong><?= esc(format_money($shipment['cod_amount'], $shipment['currency'] ?? 'INR')) ?></strong> was collected from the receiver.</label>
                                        <?= $msg('cod_collected') ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="ts-card__foot d-flex justify-content-between align-items-center">
                        <span class="ts-hint mb-0"><i class="bi bi-lock me-1"></i> Saving marks the shipment as Delivered. This cannot be undone.</span>
                        <button type="submit" class="btn btn-accent"><i class="bi bi-check2-circle me-1"></i> Confirm delivery</button>
                    </div>
                </section>
            </form>
        <?php endif; ?>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var hints = {
        name: ['Verification reference', 'Optional.'],
        otp: ['OTP shared by receiver', 'Required: 4-6 digit OTP.'],
        signature: ['Signature reference', 'Optional. e.g. signature ID.'],
        photo: ['Photo reference', 'Optional. e.g. photo ID / file name.']
    };
    function sync() {
        var r = document.querySelector('input[name="verification_method"]:checked');
        var h = hints[r ? r.value : 'name'];
        var label = document.getElementById('refLabel');
        if (!label) { return; }
        label.textContent = h[0];
        label.classList.toggle('ts-required', r && r.value === 'otp');
        document.getElementById('refHint').textContent = h[1];
    }
    document.querySelectorAll('input[name="verification_method"]').forEach(function (r) { r.addEventListener('change', sync); });
    sync();
});
</script>
<?= $this->endSection() ?>
