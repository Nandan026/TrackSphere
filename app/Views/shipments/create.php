<?php
/**
 * New shipment form.
 *
 * Controller passes: $customers, $locations, $addresses, $packages, $couriers, $presetCustomer.
 * Note: old() already HTML-escapes values, so they are printed as-is.
 */
$errors = session()->getFlashdata('errors');
$errors = is_array($errors) ? $errors : [];
$err    = static fn (string $key): bool => isset($errors[$key]);
$msg    = static fn (string $key): string => isset($errors[$key]) ? '<div class="invalid-feedback d-block">' . esc($errors[$key]) . '</div>' : '';

$oldCustomer = (int) (old('customer_id') ?: $presetCustomer);
$senderMode  = old('sender_mode') ?: 'pickup';
$receiverMode = old('receiver_mode') ?: 'existing';
$payment     = old('payment_mode') ?: 'prepaid';
$oldItems    = old('items');
$oldItems    = is_array($oldItems) && $oldItems !== [] ? array_values($oldItems) : [['quantity' => 1]];

$defaultLocation = (int) (old('pickup_location_id') ?: 0);
if ($defaultLocation === 0) {
    foreach ($locations as $loc) {
        if ((int) $loc['is_default'] === 1) {
            $defaultLocation = (int) $loc['id'];
            break;
        }
    }
}

$addrLabel = static fn (array $a): string => $a['contact_name'] . ' — ' . $a['address_line1'] . ', ' . $a['city'] . ' ' . $a['postal_code'];
$addressFields = static function (string $prefix, array $errors): string {
    $fields = [
        'contact_name'  => ['Contact name', 'text', 'col-md-6', true],
        'contact_phone' => ['Phone', 'tel', 'col-md-6', true],
        'address_line1' => ['Address line 1', 'text', 'col-12', true],
        'address_line2' => ['Address line 2', 'text', 'col-md-6', false],
        'landmark'      => ['Landmark', 'text', 'col-md-6', false],
        'city'          => ['City', 'text', 'col-md-4', true],
        'state'         => ['State', 'text', 'col-md-4', true],
        'postal_code'   => ['PIN code', 'text', 'col-md-4', true],
    ];
    $html = '<div class="row g-3">';
    foreach ($fields as $name => [$label, $type, $col, $required]) {
        $key     = $prefix . '.' . $name;
        $invalid = isset($errors[$key]) ? ' is-invalid' : '';
        $html   .= '<div class="' . $col . '"><label class="form-label' . ($required ? ' ts-required' : '') . '" for="' . $prefix . '_' . $name . '">' . $label . '</label>'
            . '<input type="' . $type . '" class="form-control' . $invalid . '" id="' . $prefix . '_' . $name . '" name="' . $prefix . '[' . $name . ']"'
            . ' value="' . old($prefix . '.' . $name) . '"' . ($name === 'postal_code' ? ' maxlength="6" inputmode="numeric"' : ' maxlength="255"') . '>'
            . (isset($errors[$key]) ? '<div class="invalid-feedback">' . esc($errors[$key]) . '</div>' : '')
            . '</div>';
    }

    return $html . '</div>';
};
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="ts-page-head">
    <div>
        <h2>New shipment</h2>
        <p>Capture parties, package details and items. The shipment starts as a <strong>Draft</strong> with an auto-generated tracking number.</p>
    </div>
    <a href="<?= site_url('shipments') ?>" class="btn btn-soft"><i class="bi bi-arrow-left me-1"></i> Back to shipments</a>
</div>

<form method="post" action="<?= site_url('shipments') ?>" id="shipmentForm" data-ts-once novalidate>
    <?= csrf_field() ?>

    <div class="row g-3">
        <div class="col-xl-8">

            <!-- 1. Customer & pickup -->
            <section class="ts-card mb-3">
                <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-person-badge"></i> Customer &amp; pickup</h3></div>
                <div class="ts-card__body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="customer_id" class="form-label ts-required">Customer</label>
                            <select name="customer_id" id="customer_id" class="form-select<?= $err('customer_id') ? ' is-invalid' : '' ?>" required>
                                <option value="">Select customer…</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= (int) $c['id'] ?>" <?= $oldCustomer === (int) $c['id'] ? 'selected' : '' ?>>
                                        <?= esc($c['name']) ?> (<?= esc($c['customer_code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?= $msg('customer_id') ?>
                        </div>
                        <div class="col-md-6">
                            <label for="pickup_location_id" class="form-label ts-required">Pickup location</label>
                            <select name="pickup_location_id" id="pickup_location_id" class="form-select<?= $err('pickup_location_id') ? ' is-invalid' : '' ?>" required>
                                <option value="">Select pickup location…</option>
                                <?php foreach ($locations as $loc): ?>
                                    <option value="<?= (int) $loc['id'] ?>" <?= $defaultLocation === (int) $loc['id'] ? 'selected' : '' ?>>
                                        <?= esc($loc['name']) ?> — <?= esc($loc['city']) ?> <?= esc($loc['postal_code']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?= $msg('pickup_location_id') ?>
                        </div>
                        <div class="col-md-6">
                            <label for="courier_partner_id" class="form-label">Courier <span class="text-muted fw-normal">(optional, can be assigned later)</span></label>
                            <select name="courier_partner_id" id="courier_partner_id" class="form-select<?= $err('courier_partner_id') ? ' is-invalid' : '' ?>">
                                <option value="">Assign later</option>
                                <?php foreach ($couriers as $cp): ?>
                                    <option value="<?= (int) $cp['id'] ?>" data-cod="<?= (int) $cp['supports_cod'] ?>"
                                        <?= (int) old('courier_partner_id') === (int) $cp['id'] ? 'selected' : '' ?>>
                                        <?= esc($cp['name']) ?><?= (int) $cp['supports_cod'] === 1 ? '' : ' (no COD)' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?= $msg('courier_partner_id') ?>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 2. Sender -->
            <section class="ts-card mb-3">
                <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-box-arrow-up-right"></i> Sender address</h3></div>
                <div class="ts-card__body">
                    <div class="ts-mode" role="radiogroup" aria-label="Sender address source">
                        <input type="radio" name="sender_mode" id="sm_pickup" value="pickup" <?= $senderMode === 'pickup' ? 'checked' : '' ?>><label for="sm_pickup">Use pickup location address</label>
                        <input type="radio" name="sender_mode" id="sm_existing" value="existing" <?= $senderMode === 'existing' ? 'checked' : '' ?>><label for="sm_existing">Select saved address</label>
                        <input type="radio" name="sender_mode" id="sm_new" value="new" <?= $senderMode === 'new' ? 'checked' : '' ?>><label for="sm_new">Enter new address</label>
                    </div>

                    <div data-panel="sender" data-mode="pickup">
                        <p class="text-muted mb-0"><i class="bi bi-info-circle me-1"></i> The address of the selected pickup location will be used as the sender.</p>
                    </div>
                    <div data-panel="sender" data-mode="existing" hidden>
                        <label for="sender_address_id" class="form-label">Saved address</label>
                        <select name="sender_address_id" id="sender_address_id" class="form-select addr-select<?= $err('sender_address_id') ? ' is-invalid' : '' ?>">
                            <option value="">Select address…</option>
                            <?php foreach ($addresses as $a): ?>
                                <option value="<?= (int) $a['id'] ?>" data-customer="<?= esc((string) $a['customer_id'], 'attr') ?>"
                                    <?= (int) old('sender_address_id') === (int) $a['id'] ? 'selected' : '' ?>><?= esc($addrLabel($a)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= $msg('sender_address_id') ?>
                    </div>
                    <div data-panel="sender" data-mode="new" hidden>
                        <?= $addressFields('sender_new', $errors) ?>
                    </div>
                </div>
            </section>

            <!-- 3. Receiver -->
            <section class="ts-card mb-3">
                <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-geo-alt"></i> Receiver address</h3></div>
                <div class="ts-card__body">
                    <div class="ts-mode" role="radiogroup" aria-label="Receiver address source">
                        <input type="radio" name="receiver_mode" id="rm_existing" value="existing" <?= $receiverMode === 'existing' ? 'checked' : '' ?>><label for="rm_existing">Select saved address</label>
                        <input type="radio" name="receiver_mode" id="rm_new" value="new" <?= $receiverMode === 'new' ? 'checked' : '' ?>><label for="rm_new">Enter new address</label>
                    </div>

                    <div data-panel="receiver" data-mode="existing">
                        <label for="receiver_address_id" class="form-label">Saved address</label>
                        <select name="receiver_address_id" id="receiver_address_id" class="form-select addr-select<?= $err('receiver_address_id') ? ' is-invalid' : '' ?>">
                            <option value="">Select address…</option>
                            <?php foreach ($addresses as $a): ?>
                                <option value="<?= (int) $a['id'] ?>" data-customer="<?= esc((string) $a['customer_id'], 'attr') ?>"
                                    <?= (int) old('receiver_address_id') === (int) $a['id'] ? 'selected' : '' ?>><?= esc($addrLabel($a)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= $msg('receiver_address_id') ?>
                    </div>
                    <div data-panel="receiver" data-mode="new" hidden>
                        <?= $addressFields('receiver_new', $errors) ?>
                    </div>
                </div>
            </section>

            <!-- 4. Items -->
            <section class="ts-card mb-3">
                <div class="ts-card__head">
                    <h3 class="ts-card__title"><i class="bi bi-boxes"></i> Items</h3>
                    <button type="button" class="btn btn-sm btn-soft" id="addItem"><i class="bi bi-plus-lg me-1"></i> Add item</button>
                </div>
                <div class="ts-card__body ts-card__body--flush">
                    <?php if ($err('items')): ?><div class="p-3 pb-0"><?= $msg('items') ?></div><?php endif; ?>
                    <div class="ts-table-wrap">
                        <table class="ts-table" id="itemsTable">
                            <thead>
                                <tr>
                                    <th style="min-width:220px">Item name</th>
                                    <th>SKU</th>
                                    <th style="width:90px">Qty</th>
                                    <th style="width:130px">Unit value (₹)</th>
                                    <th>HSN</th>
                                    <th class="text-end">Total</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="itemsBody">
                            <?php foreach ($oldItems as $i => $row): ?>
                                <tr>
                                    <td><input type="text" class="form-control<?= $err("items.$i.item_name") ? ' is-invalid' : '' ?>" name="items[<?= $i ?>][item_name]" value="<?= $row['item_name'] ?? '' ?>" maxlength="180" placeholder="e.g. Cotton T-shirt"></td>
                                    <td><input type="text" class="form-control" name="items[<?= $i ?>][sku]" value="<?= $row['sku'] ?? '' ?>" maxlength="80"></td>
                                    <td><input type="number" class="form-control<?= $err("items.$i.quantity") ? ' is-invalid' : '' ?> js-qty" name="items[<?= $i ?>][quantity]" value="<?= $row['quantity'] ?? 1 ?>" min="1" step="1"></td>
                                    <td><input type="number" class="form-control<?= $err("items.$i.unit_value") ? ' is-invalid' : '' ?> js-unit" name="items[<?= $i ?>][unit_value]" value="<?= $row['unit_value'] ?? '' ?>" min="0" step="0.01"></td>
                                    <td><input type="text" class="form-control" name="items[<?= $i ?>][hsn_code]" value="<?= $row['hsn_code'] ?? '' ?>" maxlength="20"></td>
                                    <td class="text-end ts-cell-strong js-line">₹0.00</td>
                                    <td class="ts-actions"><button type="button" class="ts-icon-btn ts-icon-btn--danger js-remove" title="Remove item"><i class="bi bi-trash"></i></button></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="5" class="text-end text-muted pe-3">Items total</td>
                                    <td class="text-end ts-cell-strong" id="itemsTotal">₹0.00</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </section>
        </div>

        <!-- Sidebar column -->
        <div class="col-xl-4">
            <!-- 5. Package -->
            <section class="ts-card mb-3">
                <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-rulers"></i> Package &amp; weight</h3></div>
                <div class="ts-card__body">
                    <?php if ($packages !== []): ?>
                        <div class="mb-3">
                            <label for="package_preset" class="form-label">Standard package</label>
                            <select id="package_preset" class="form-select">
                                <option value="">Custom size</option>
                                <?php foreach ($packages as $p): ?>
                                    <option value="<?= (int) $p['id'] ?>"
                                            data-l="<?= esc((string) $p['length_cm'], 'attr') ?>" data-w="<?= esc((string) $p['width_cm'], 'attr') ?>"
                                            data-h="<?= esc((string) $p['height_cm'], 'attr') ?>" data-kg="<?= esc((string) $p['default_weight_kg'], 'attr') ?>">
                                        <?= esc($p['package_name']) ?> (<?= esc($p['package_code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="row g-2 mb-3">
                        <?php foreach (['length_cm' => 'Length (cm)', 'width_cm' => 'Width (cm)', 'height_cm' => 'Height (cm)'] as $field => $label): ?>
                            <div class="col-4">
                                <label for="<?= $field ?>" class="form-label ts-required"><?= $label ?></label>
                                <input type="number" step="0.01" min="0.01" class="form-control js-dim<?= $err($field) ? ' is-invalid' : '' ?>" id="<?= $field ?>" name="<?= $field ?>" value="<?= old($field) ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?= $msg('length_cm') . $msg('width_cm') . $msg('height_cm') ?>

                    <div class="row g-2 mb-3">
                        <div class="col-7">
                            <label for="actual_weight_kg" class="form-label ts-required">Actual weight (kg)</label>
                            <input type="number" step="0.001" min="0.001" class="form-control js-dim<?= $err('actual_weight_kg') ? ' is-invalid' : '' ?>" id="actual_weight_kg" name="actual_weight_kg" value="<?= old('actual_weight_kg') ?>">
                            <?= $msg('actual_weight_kg') ?>
                        </div>
                        <div class="col-5">
                            <label for="total_packages" class="form-label">Packages</label>
                            <input type="number" min="1" max="100" class="form-control<?= $err('total_packages') ? ' is-invalid' : '' ?>" id="total_packages" name="total_packages" value="<?= old('total_packages') ?: 1 ?>">
                        </div>
                    </div>

                    <div class="ts-weight-preview" aria-live="polite">
                        <div><small>Actual</small><strong id="pvActual">0</strong> kg</div>
                        <div><small>Volumetric</small><strong id="pvVol">0</strong> kg</div>
                        <div id="pvChargeableBox"><small>Chargeable</small><strong id="pvCharge">0</strong> kg</div>
                    </div>
                    <p class="ts-hint mb-0">Volumetric = L × W × H ÷ 5000. The higher of actual and volumetric weight is billed.</p>
                </div>
            </section>

            <!-- 6. Payment -->
            <section class="ts-card mb-3">
                <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-wallet2"></i> Payment &amp; value</h3></div>
                <div class="ts-card__body">
                    <div class="ts-mode" role="radiogroup" aria-label="Payment mode">
                        <input type="radio" name="payment_mode" id="pm_prepaid" value="prepaid" <?= $payment === 'prepaid' ? 'checked' : '' ?>><label for="pm_prepaid">Prepaid</label>
                        <input type="radio" name="payment_mode" id="pm_cod" value="cod" <?= $payment === 'cod' ? 'checked' : '' ?>><label for="pm_cod">Cash on delivery</label>
                    </div>

                    <div id="codBox" class="mb-3" <?= $payment === 'cod' ? '' : 'hidden' ?>>
                        <label for="cod_amount" class="form-label ts-required">COD amount to collect (₹)</label>
                        <input type="number" step="0.01" min="0" class="form-control<?= $err('cod_amount') ? ' is-invalid' : '' ?>" id="cod_amount" name="cod_amount" value="<?= old('cod_amount') ?>">
                        <?= $msg('cod_amount') ?>
                        <div class="ts-hint" id="codCourierHint" hidden>Couriers without COD support are disabled.</div>
                    </div>

                    <div class="mb-3">
                        <label for="declared_value" class="form-label">Declared value (₹)</label>
                        <input type="number" step="0.01" min="0" class="form-control<?= $err('declared_value') ? ' is-invalid' : '' ?>" id="declared_value" name="declared_value" value="<?= old('declared_value') ?>" placeholder="Defaults to items total">
                        <?= $msg('declared_value') ?>
                    </div>

                    <div>
                        <label for="special_instructions" class="form-label">Special instructions</label>
                        <textarea class="form-control<?= $err('special_instructions') ? ' is-invalid' : '' ?>" id="special_instructions" name="special_instructions" rows="3" maxlength="500" placeholder="Fragile, call before delivery…"><?= old('special_instructions') ?></textarea>
                        <?= $msg('special_instructions') ?>
                    </div>
                </div>
            </section>

            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-accent btn-lg"><i class="bi bi-check2-circle me-1"></i> Create shipment</button>
                <a href="<?= site_url('shipments') ?>" class="btn btn-soft">Cancel</a>
            </div>
        </div>
    </div>
</form>

<!-- Item row template -->
<template id="itemRowTpl">
    <tr>
        <td><input type="text" class="form-control" name="items[__i__][item_name]" maxlength="180" placeholder="e.g. Cotton T-shirt"></td>
        <td><input type="text" class="form-control" name="items[__i__][sku]" maxlength="80"></td>
        <td><input type="number" class="form-control js-qty" name="items[__i__][quantity]" value="1" min="1" step="1"></td>
        <td><input type="number" class="form-control js-unit" name="items[__i__][unit_value]" min="0" step="0.01"></td>
        <td><input type="text" class="form-control" name="items[__i__][hsn_code]" maxlength="20"></td>
        <td class="text-end ts-cell-strong js-line">₹0.00</td>
        <td class="ts-actions"><button type="button" class="ts-icon-btn ts-icon-btn--danger js-remove" title="Remove item"><i class="bi bi-trash"></i></button></td>
    </tr>
</template>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    'use strict';
    var $ = function (s, c) { return (c || document).querySelector(s); };
    var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };

    /* ---- Address mode panels ------------------------------------------ */
    function bindMode(group) {
        function sync() {
            var checked = $('input[name="' + group + '_mode"]:checked');
            var mode = checked ? checked.value : '';
            $$('[data-panel="' + group + '"]').forEach(function (p) { p.hidden = p.getAttribute('data-mode') !== mode; });
        }
        $$('input[name="' + group + '_mode"]').forEach(function (r) { r.addEventListener('change', sync); });
        sync();
    }
    bindMode('sender');
    bindMode('receiver');

    /* ---- Filter saved addresses by customer ----------------------------- */
    var customer = $('#customer_id');
    function filterAddresses() {
        var cid = customer.value;
        $$('.addr-select').forEach(function (sel) {
            $$('option', sel).forEach(function (opt) {
                var owner = opt.getAttribute('data-customer');
                if (owner === null) { return; }
                var show = owner === '' || cid === '' || owner === cid;
                opt.hidden = !show;
                opt.disabled = !show;
            });
            var cur = sel.options[sel.selectedIndex];
            if (cur && cur.disabled) { sel.value = ''; }
        });
    }
    customer.addEventListener('change', filterAddresses);
    filterAddresses();

    /* ---- Weight preview --------------------------------------------------- */
    function num(id) { var v = parseFloat($('#' + id).value); return isNaN(v) ? 0 : v; }
    function fmt(n) { return (Math.round(n * 1000) / 1000).toString(); }
    function weights() {
        var l = num('length_cm'), w = num('width_cm'), h = num('height_cm'), a = num('actual_weight_kg');
        var vol = (l > 0 && w > 0 && h > 0) ? (l * w * h) / 5000 : 0;
        var ch = Math.max(a, vol);
        $('#pvActual').textContent = fmt(a);
        $('#pvVol').textContent = fmt(vol);
        $('#pvCharge').textContent = fmt(ch);
        $('#pvChargeableBox').classList.toggle('is-max', ch > 0);
    }
    $$('.js-dim').forEach(function (el) { el.addEventListener('input', weights); });
    weights();

    var preset = $('#package_preset');
    if (preset) {
        preset.addEventListener('change', function () {
            var o = preset.options[preset.selectedIndex];
            if (!o || !o.value) { return; }
            $('#length_cm').value = o.getAttribute('data-l') || '';
            $('#width_cm').value = o.getAttribute('data-w') || '';
            $('#height_cm').value = o.getAttribute('data-h') || '';
            if (o.getAttribute('data-kg')) { $('#actual_weight_kg').value = o.getAttribute('data-kg'); }
            weights();
        });
    }

    /* ---- Payment mode / COD ------------------------------------------------ */
    var courier = $('#courier_partner_id');
    function syncPayment() {
        var cod = $('#pm_cod').checked;
        $('#codBox').hidden = !cod;
        $('#codCourierHint').hidden = !cod;
        $$('option', courier).forEach(function (o) {
            if (o.value === '') { return; }
            o.disabled = cod && o.getAttribute('data-cod') !== '1';
        });
        if (courier.options[courier.selectedIndex] && courier.options[courier.selectedIndex].disabled) { courier.value = ''; }
    }
    $$('input[name="payment_mode"]').forEach(function (r) { r.addEventListener('change', syncPayment); });
    syncPayment();

    /* ---- Items ---------------------------------------------------------------- */
    var body = $('#itemsBody');
    var tpl = $('#itemRowTpl').innerHTML;
    var nextIndex = body.children.length + 100;

    function money(n) { return '₹' + n.toFixed(2).replace(/\B(?=(\d{2})*\d{3}(?!\d))/g, ','); }
    function totals() {
        var sum = 0;
        $$('tr', body).forEach(function (tr) {
            var q = parseFloat($('.js-qty', tr).value) || 0;
            var u = parseFloat($('.js-unit', tr).value) || 0;
            var line = q * u;
            $('.js-line', tr).textContent = money(line);
            sum += line;
        });
        $('#itemsTotal').textContent = money(sum);
    }
    $('#addItem').addEventListener('click', function () {
        body.insertAdjacentHTML('beforeend', tpl.replace(/__i__/g, String(nextIndex++)));
        totals();
        var last = body.lastElementChild; if (last) { $('input', last).focus(); }
    });
    body.addEventListener('input', totals);
    body.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-remove');
        if (!btn) { return; }
        if (body.children.length > 1) { btn.closest('tr').remove(); totals(); }
    });
    totals();
})();
</script>
<?= $this->endSection() ?>
