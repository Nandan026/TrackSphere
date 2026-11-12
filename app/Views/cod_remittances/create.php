<?php
/**
 * New COD remittance batch.
 *
 * Controller passes: $couriers, $periodStart, $periodEnd.
 */
$errors = session()->getFlashdata('errors');
$errors = is_array($errors) ? $errors : [];
$err    = static fn (string $k): bool => isset($errors[$k]);
$msg    = static fn (string $k): string => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . esc($errors[$k]) . '</div>' : '';
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="ts-page-head">
    <div>
        <h2>New COD remittance</h2>
        <p>Record the cash a courier collected on your behalf for a period, then mark it remitted when the money arrives.</p>
    </div>
    <a href="<?= site_url('cod-remittances') ?>" class="btn btn-soft"><i class="bi bi-arrow-left me-1"></i> Back to remittances</a>
</div>

<div class="row g-3">
    <div class="col-lg-7 col-xl-6">
        <form method="post" action="<?= site_url('cod-remittances') ?>" data-ts-once novalidate>
            <?= csrf_field() ?>
            <section class="ts-card">
                <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-cash-coin"></i> Batch details</h3></div>
                <div class="ts-card__body">
                    <div class="mb-3">
                        <label class="form-label ts-required" for="courier_partner_id">Courier</label>
                        <select name="courier_partner_id" id="courier_partner_id" class="form-select<?= $err('courier_partner_id') ? ' is-invalid' : '' ?>" required>
                            <option value="">Select courier…</option>
                            <?php foreach ($couriers as $c): ?>
                                <option value="<?= (int) $c['id'] ?>" <?= (string) old('courier_partner_id') === (string) $c['id'] ? 'selected' : '' ?>>
                                    <?= esc($c['name']) ?><?= (int) $c['supports_cod'] === 1 ? '' : ' (no COD service)' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?= $msg('courier_partner_id') ?>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label ts-required" for="period_start">Period start</label>
                            <input type="date" name="period_start" id="period_start" class="form-control<?= $err('period_start') ? ' is-invalid' : '' ?>"
                                   value="<?= esc(old('period_start') ?: $periodStart, 'attr') ?>" required>
                            <?= $msg('period_start') ?>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label ts-required" for="period_end">Period end</label>
                            <input type="date" name="period_end" id="period_end" class="form-control<?= $err('period_end') ? ' is-invalid' : '' ?>"
                                   value="<?= esc(old('period_end') ?: $periodEnd, 'attr') ?>" required>
                            <?= $msg('period_end') ?>
                        </div>
                    </div>

                    <div class="p-3 rounded mb-3" style="background:var(--ts-teal-50);border:1px solid var(--ts-teal-100)">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div>
                                <strong>Delivered COD shipments</strong>
                                <div class="small text-muted">Sum the COD cash of shipments this courier delivered in the period.</div>
                            </div>
                            <button type="button" class="btn btn-sm btn-primary" id="suggestBtn"><i class="bi bi-calculator me-1"></i> Calculate</button>
                        </div>
                        <div id="suggestResult" class="small mt-2" aria-live="polite"></div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label ts-required" for="total_cod_amount">Total COD collected (₹)</label>
                            <input type="number" step="0.01" min="0.01" name="total_cod_amount" id="total_cod_amount"
                                   class="form-control<?= $err('total_cod_amount') ? ' is-invalid' : '' ?>" value="<?= esc(old('total_cod_amount'), 'attr') ?>" required>
                            <?= $msg('total_cod_amount') ?>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="deductions">Deductions (₹)</label>
                            <input type="number" step="0.01" min="0" name="deductions" id="deductions"
                                   class="form-control<?= $err('deductions') ? ' is-invalid' : '' ?>" value="<?= esc(old('deductions') ?: '0', 'attr') ?>">
                            <?= $msg('deductions') ?>
                            <div class="form-text">Courier fees, COD charges, penalties.</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="reference_number">Reference <span class="text-muted">(optional)</span></label>
                        <input type="text" name="reference_number" id="reference_number" maxlength="100"
                               class="form-control<?= $err('reference_number') ? ' is-invalid' : '' ?>" value="<?= esc((string) old('reference_number', null, false), 'attr') ?>"
                               placeholder="Courier statement / UTR number">
                        <?= $msg('reference_number') ?>
                    </div>

                    <div class="d-flex justify-content-between p-3 rounded" style="background:var(--ts-slate-50);border:1px solid var(--ts-border)">
                        <span class="text-muted">Net remittance</span>
                        <strong id="netPreview">₹0.00</strong>
                    </div>
                </div>
                <div class="ts-card__foot d-flex justify-content-end gap-2">
                    <a href="<?= site_url('cod-remittances') ?>" class="btn btn-soft">Cancel</a>
                    <button type="submit" class="btn btn-accent"><i class="bi bi-save me-1"></i> Create remittance</button>
                </div>
            </section>
        </form>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var SUGGEST_URL = <?= json_encode(site_url('cod-remittances/suggest'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;
    var total = document.getElementById('total_cod_amount');
    var ded = document.getElementById('deductions');
    var out = document.getElementById('suggestResult');

    function money(n) { return '₹' + n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function num(v) { var n = parseFloat(v); return isNaN(n) ? 0 : n; }
    function net() { document.getElementById('netPreview').textContent = money(Math.max(0, num(total.value) - num(ded.value))); }
    function esc(s) { var d = document.createElement('div'); d.textContent = String(s == null ? '' : s); return d.innerHTML; }

    total.addEventListener('input', net);
    ded.addEventListener('input', net);
    net();

    document.getElementById('suggestBtn').addEventListener('click', function () {
        var q = new URLSearchParams({
            courier_id: document.getElementById('courier_partner_id').value,
            from: document.getElementById('period_start').value,
            to: document.getElementById('period_end').value
        });
        out.innerHTML = '<span class="text-muted">Calculating…</span>';
        fetch(SUGGEST_URL + '?' + q.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success) { out.innerHTML = '<span class="text-danger">' + esc(d.message || 'Could not calculate.') + '</span>'; return; }
                if (!d.count) { out.innerHTML = '<span class="text-muted">No delivered COD shipments for this courier in the period.</span>'; return; }
                var html = '<strong>' + d.count + '</strong> shipment(s) · <strong>' + money(d.total) + '</strong> '
                    + '<a href="#" id="useTotal" class="ms-1">Use this total</a><ul class="mb-0 mt-1 ps-3">';
                d.shipments.forEach(function (s) { html += '<li><span class="ts-mono">' + esc(s.tracking_number) + '</span> · ' + money(parseFloat(s.cod_amount)) + '</li>'; });
                if (d.count > d.shipments.length) { html += '<li class="text-muted">…and ' + (d.count - d.shipments.length) + ' more</li>'; }
                out.innerHTML = html + '</ul>';
                document.getElementById('useTotal').addEventListener('click', function (e) {
                    e.preventDefault();
                    total.value = d.total.toFixed(2);
                    net();
                });
            })
            .catch(function () { out.innerHTML = '<span class="text-danger">Could not calculate. Please try again.</span>'; });
    });
});
</script>
<?= $this->endSection() ?>
