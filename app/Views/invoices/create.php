<?php
/**
 * New invoice.
 *
 * Controller passes: $customers, $shipments (billable, not yet invoiced), $today, $dueDate.
 * Flash `errors` holds field => message (e.g. customer_id, items.0.description).
 */
$errors = session()->getFlashdata('errors');
$errors = is_array($errors) ? $errors : [];
$err    = static fn (string $k): bool => isset($errors[$k]);
$msg    = static fn (string $k): string => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . esc($errors[$k]) . '</div>' : '';

$oldRaw   = old('items', null, false);
$oldItems = [];
if (is_array($oldRaw)) {
    foreach ($oldRaw as $key => $row) {
        if (is_array($row)) {
            $oldItems[] = ['__index' => (string) $key] + $row;
        }
    }
}

$shipmentData = array_map(static fn (array $s): array => [
    'id'       => (int) $s['id'],
    'customer' => (int) $s['customer_id'],
    'tracking' => (string) $s['tracking_number'],
    'charge'   => (float) $s['shipping_charge'],
    'status'   => ucwords(str_replace('_', ' ', (string) $s['current_status'])),
], $shipments);
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="ts-page-head">
    <div>
        <h2>New invoice</h2>
        <p>Pick a customer, add line items and review the totals before saving as a draft.</p>
    </div>
    <a href="<?= site_url('invoices') ?>" class="btn btn-soft"><i class="bi bi-arrow-left me-1"></i> Back to invoices</a>
</div>

<form method="post" action="<?= site_url('invoices') ?>" data-ts-once novalidate id="invoiceForm">
    <?= csrf_field() ?>

    <div class="row g-3">
        <div class="col-lg-8">
            <section class="ts-card mb-3">
                <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-person"></i> Invoice details</h3></div>
                <div class="ts-card__body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label ts-required" for="customer_id">Customer</label>
                            <select name="customer_id" id="customer_id" class="form-select<?= $err('customer_id') ? ' is-invalid' : '' ?>" required>
                                <option value="">Select customer…</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= (int) $c['id'] ?>" <?= (string) old('customer_id') === (string) $c['id'] ? 'selected' : '' ?>>
                                        <?= esc($c['name']) ?> (<?= esc($c['customer_code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?= $msg('customer_id') ?>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label ts-required" for="invoice_date">Invoice date</label>
                            <input type="date" name="invoice_date" id="invoice_date" class="form-control<?= $err('invoice_date') ? ' is-invalid' : '' ?>"
                                   value="<?= esc(old('invoice_date') ?: $today, 'attr') ?>" required>
                            <?= $msg('invoice_date') ?>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="due_date">Due date</label>
                            <input type="date" name="due_date" id="due_date" class="form-control<?= $err('due_date') ? ' is-invalid' : '' ?>"
                                   value="<?= esc(old('due_date') ?: $dueDate, 'attr') ?>">
                            <?= $msg('due_date') ?>
                        </div>
                    </div>
                </div>
            </section>

            <section class="ts-card">
                <div class="ts-card__head d-flex justify-content-between align-items-center">
                    <h3 class="ts-card__title mb-0"><i class="bi bi-list-check"></i> Line items</h3>
                    <button type="button" class="btn btn-sm btn-soft" id="addLine"><i class="bi bi-plus-lg me-1"></i> Add line</button>
                </div>
                <div class="ts-card__body ts-card__body--flush">
                    <?php if ($err('items')): ?><div class="p-3 pb-0"><?= $msg('items') ?></div><?php endif; ?>
                    <div class="ts-table-wrap">
                        <table class="ts-table" id="lineTable">
                            <thead>
                                <tr>
                                    <th style="min-width:200px">Shipment <span class="text-muted fw-normal">(optional)</span></th>
                                    <th style="min-width:220px">Description</th>
                                    <th style="width:90px">Qty</th>
                                    <th style="width:120px">Unit price</th>
                                    <th style="width:90px">Tax %</th>
                                    <th class="text-end" style="width:120px">Amount</th>
                                    <th style="width:44px"></th>
                                </tr>
                            </thead>
                            <tbody id="lineBody"></tbody>
                        </table>
                    </div>
                    <div class="p-3 text-muted small"><i class="bi bi-info-circle me-1"></i> Choose a customer first; shipments of that customer that are not yet invoiced become selectable. Selecting one pre-fills the description and its freight charge.</div>
                </div>
            </section>
        </div>

        <div class="col-lg-4">
            <section class="ts-card">
                <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-calculator"></i> Totals</h3></div>
                <div class="ts-card__body">
                    <div class="d-flex justify-content-between mb-2"><span class="text-muted">Subtotal</span><span id="tSubtotal">₹0.00</span></div>
                    <div class="d-flex justify-content-between mb-2"><span class="text-muted">Tax</span><span id="tTax">₹0.00</span></div>
                    <div class="mb-3">
                        <label class="form-label mb-1" for="discount_amount">Discount (₹)</label>
                        <input type="number" step="0.01" min="0" name="discount_amount" id="discount_amount"
                               class="form-control<?= $err('discount_amount') ? ' is-invalid' : '' ?>" value="<?= esc(old('discount_amount') ?: '0', 'attr') ?>">
                        <?= $msg('discount_amount') ?>
                    </div>
                    <hr class="my-2">
                    <div class="d-flex justify-content-between fs-5 fw-bold"><span>Total</span><span id="tTotal">₹0.00</span></div>
                </div>
                <div class="ts-card__foot d-flex justify-content-end gap-2">
                    <a href="<?= site_url('invoices') ?>" class="btn btn-soft">Cancel</a>
                    <button type="submit" class="btn btn-accent"><i class="bi bi-save me-1"></i> Save draft</button>
                </div>
            </section>
        </div>
    </div>
</form>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var SHIPMENTS = <?= json_encode($shipmentData, $jsonFlags) ?>;
    var OLD_ITEMS = <?= json_encode($oldItems, $jsonFlags) ?>;
    var LINE_ERRORS = <?= json_encode(array_filter($errors, static fn ($k) => str_starts_with((string) $k, 'items.'), ARRAY_FILTER_USE_KEY), $jsonFlags) ?>;

    var body = document.getElementById('lineBody');
    var customer = document.getElementById('customer_id');
    var discount = document.getElementById('discount_amount');
    var counter = 0;

    function money(n) { return '₹' + n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function r2(n) { return Math.round((n + Number.EPSILON) * 100) / 100; }
    function num(v) { var n = parseFloat(v); return isNaN(n) ? 0 : n; }
    function esc(s) {
        var d = document.createElement('div');
        d.textContent = String(s == null ? '' : s);
        return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function shipmentOptions(selected, usedElsewhere) {
        var cid = parseInt(customer.value, 10) || 0;
        var html = '<option value="">— none —</option>';
        SHIPMENTS.forEach(function (s) {
            if (cid && s.customer !== cid) { return; }
            if (!cid) { return; }
            if (usedElsewhere.indexOf(s.id) !== -1 && String(s.id) !== String(selected)) { return; }
            html += '<option value="' + s.id + '"' + (String(s.id) === String(selected) ? ' selected' : '') + '>'
                + esc(s.tracking) + ' · ' + esc(s.status) + '</option>';
        });
        return html;
    }

    function selectedShipments(except) {
        var used = [];
        body.querySelectorAll('select[data-f="shipment_id"]').forEach(function (sel) {
            if (sel !== except && sel.value) { used.push(parseInt(sel.value, 10)); }
        });
        return used;
    }

    function refreshShipmentSelects() {
        body.querySelectorAll('select[data-f="shipment_id"]').forEach(function (sel) {
            var cur = sel.value;
            sel.innerHTML = shipmentOptions(cur, selectedShipments(sel));
            sel.disabled = !(parseInt(customer.value, 10) > 0);
        });
    }

    function lineErrorsFor(index) {
        var out = [];
        Object.keys(LINE_ERRORS).forEach(function (k) {
            if (k.indexOf('items.' + index + '.') === 0) { out.push(LINE_ERRORS[k]); }
        });
        return out;
    }

    function addLine(data) {
        data = data || {};
        var i = counter++;
        var errs = lineErrorsFor(data.__index !== undefined ? data.__index : -1);
        var tr = document.createElement('tr');
        tr.innerHTML =
            '<td><select class="form-select form-select-sm" name="items[' + i + '][shipment_id]" data-f="shipment_id"></select></td>' +
            '<td><input type="text" class="form-control form-control-sm" name="items[' + i + '][description]" data-f="description" maxlength="255" placeholder="e.g. Shipping charge" value="' + esc(data.description || '') + '">' +
                (errs.length ? '<div class="invalid-feedback d-block">' + esc(errs.join(' ')) + '</div>' : '') + '</td>' +
            '<td><input type="number" class="form-control form-control-sm" name="items[' + i + '][quantity]" data-f="quantity" min="0.01" step="0.01" value="' + esc(data.quantity || '1') + '"></td>' +
            '<td><input type="number" class="form-control form-control-sm" name="items[' + i + '][unit_price]" data-f="unit_price" min="0" step="0.01" value="' + esc(data.unit_price || '') + '"></td>' +
            '<td><input type="number" class="form-control form-control-sm" name="items[' + i + '][tax_percent]" data-f="tax_percent" min="0" max="100" step="0.01" value="' + esc(data.tax_percent !== undefined && data.tax_percent !== '' ? data.tax_percent : '18') + '"></td>' +
            '<td class="text-end ts-cell-strong" data-f="amount">₹0.00</td>' +
            '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-remove title="Remove line"><i class="bi bi-trash"></i></button></td>';
        body.appendChild(tr);

        var sel = tr.querySelector('select[data-f="shipment_id"]');
        sel.innerHTML = shipmentOptions(data.shipment_id || '', selectedShipments(sel));
        sel.disabled = !(parseInt(customer.value, 10) > 0);
        recalc();
    }

    function recalc() {
        var sub = 0, tax = 0;
        body.querySelectorAll('tr').forEach(function (tr) {
            var q = num(tr.querySelector('[data-f="quantity"]').value);
            var p = num(tr.querySelector('[data-f="unit_price"]').value);
            var t = num(tr.querySelector('[data-f="tax_percent"]').value);
            var net = r2(q * p);
            sub += net;
            tax += r2(net * t / 100);
            tr.querySelector('[data-f="amount"]').textContent = money(net);
        });
        sub = r2(sub); tax = r2(tax);
        var total = Math.max(0, r2(sub + tax - num(discount.value)));
        document.getElementById('tSubtotal').textContent = money(sub);
        document.getElementById('tTax').textContent = money(tax);
        document.getElementById('tTotal').textContent = money(total);
    }

    body.addEventListener('input', recalc);
    body.addEventListener('change', function (e) {
        if (e.target.matches('select[data-f="shipment_id"]')) {
            var s = SHIPMENTS.filter(function (x) { return String(x.id) === e.target.value; })[0];
            var tr = e.target.closest('tr');
            if (s) {
                var desc = tr.querySelector('[data-f="description"]');
                var price = tr.querySelector('[data-f="unit_price"]');
                if (!desc.value) { desc.value = 'Shipping charge - ' + s.tracking; }
                if (!price.value || num(price.value) === 0) { price.value = s.charge.toFixed(2); }
            }
            refreshShipmentSelects();
            recalc();
        }
    });
    body.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-remove]');
        if (!btn) { return; }
        btn.closest('tr').remove();
        if (!body.children.length) { addLine(); }
        refreshShipmentSelects();
        recalc();
    });

    customer.addEventListener('change', function () {
        // Shipments of another customer cannot stay selected.
        body.querySelectorAll('select[data-f="shipment_id"]').forEach(function (sel) { sel.value = ''; });
        refreshShipmentSelects();
    });
    discount.addEventListener('input', recalc);
    document.getElementById('addLine').addEventListener('click', function () { addLine(); });

    if (OLD_ITEMS.length) {
        OLD_ITEMS.forEach(function (row) { addLine(row); });
    } else {
        addLine();
    }
});
</script>
<?= $this->endSection() ?>
