<?php
/**
 * Invoice detail.
 *
 * Controller passes: $invoice (with customer_* columns), $items, $today.
 */
$status  = (string) $invoice['status'];
$overdue = $status === 'sent' && ! empty($invoice['due_date']) && $invoice['due_date'] < $today;
$date    = static fn (?string $d): string => $d ? format_datetime($d . ' 00:00:00', 'd M Y', 'UTC') : '—';
$qty     = static fn ($v): string => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('styles') ?>
<style>
    @media print {
        .ts-sidebar, .ts-topbar, .ts-footer, .ts-backdrop, .ts-no-print, .ts-guide { display: none !important; }
        .ts-main { margin: 0 !important; }
    }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<div class="ts-page-head ts-no-print">
    <div>
        <h2>Invoice <span class="ts-mono"><?= esc($invoice['invoice_number']) ?></span></h2>
        <p>
            <?= format_status_badge($status) ?>
            <?php if ($overdue): ?><span class="badge text-bg-danger ms-1">Overdue</span><?php endif; ?>
            <span class="ms-2">Created <?= esc(format_datetime($invoice['created_at'])) ?>
                <?php if (! empty($invoice['creator_first'])): ?>by <?= esc(trim($invoice['creator_first'] . ' ' . ($invoice['creator_last'] ?? ''))) ?><?php endif; ?></span>
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= site_url('invoices') ?>" class="btn btn-soft"><i class="bi bi-arrow-left me-1"></i> Back to invoices</a>
        <button type="button" class="btn btn-soft" onclick="window.print()"><i class="bi bi-printer me-1"></i> Print</button>
        <?php if ($status === 'draft'): ?>
            <form method="post" action="<?= site_url('invoices/' . (int) $invoice['id'] . '/sent') ?>" class="m-0" data-confirm="Mark this invoice as sent?">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i> Mark sent</button>
            </form>
        <?php endif; ?>
        <?php if (in_array($status, ['draft', 'sent'], true)): ?>
            <form method="post" action="<?= site_url('invoices/' . (int) $invoice['id'] . '/paid') ?>" class="m-0" data-confirm="Mark this invoice as fully paid?">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-accent"><i class="bi bi-check2-circle me-1"></i> Mark paid</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <section class="ts-card mb-3">
            <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-list-check"></i> Line items</h3></div>
            <div class="ts-card__body ts-card__body--flush">
                <div class="ts-table-wrap">
                    <table class="ts-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Description</th>
                                <th class="text-end">Qty</th>
                                <th class="text-end">Unit price</th>
                                <th class="text-end">Tax %</th>
                                <th class="text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($items as $i => $item): ?>
                            <tr>
                                <td class="text-muted"><?= $i + 1 ?></td>
                                <td>
                                    <?= esc($item['description']) ?>
                                    <?php if (! empty($item['shipment_id']) && ! empty($item['tracking_number'])): ?>
                                        <span class="ts-cell-sub"><i class="bi bi-send me-1"></i><a href="<?= site_url('shipments/' . (int) $item['shipment_id']) ?>" class="ts-mono"><?= esc($item['tracking_number']) ?></a></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?= esc($qty($item['quantity'])) ?></td>
                                <td class="text-end"><?= esc(format_money($item['unit_price'])) ?></td>
                                <td class="text-end"><?= esc($qty($item['tax_percent'])) ?>%</td>
                                <td class="text-end ts-cell-strong"><?= esc(format_money($item['line_total'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>

    <div class="col-lg-4">
        <section class="ts-card mb-3">
            <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-person"></i> Bill to</h3></div>
            <div class="ts-card__body">
                <div class="fw-semibold"><?= esc($invoice['customer_name'] ?? '—') ?></div>
                <?php if (! empty($invoice['customer_code'])): ?><div class="text-muted small ts-mono"><?= esc($invoice['customer_code']) ?></div><?php endif; ?>
                <?php if (! empty($invoice['customer_email'])): ?><div class="small mt-2"><i class="bi bi-envelope me-1"></i><?= esc($invoice['customer_email']) ?></div><?php endif; ?>
                <?php if (! empty($invoice['customer_phone'])): ?><div class="small"><i class="bi bi-telephone me-1"></i><?= esc($invoice['customer_phone']) ?></div><?php endif; ?>
                <?php if (! empty($invoice['customer_gstin'])): ?><div class="small"><i class="bi bi-card-text me-1"></i>GSTIN <?= esc($invoice['customer_gstin']) ?></div><?php endif; ?>
                <hr>
                <dl class="ts-kv mb-0">
                    <div><dt>Invoice date</dt><dd><?= esc($date($invoice['invoice_date'])) ?></dd></div>
                    <div><dt>Due date</dt><dd class="<?= $overdue ? 'text-danger' : '' ?>"><?= esc($date($invoice['due_date'])) ?></dd></div>
                </dl>
            </div>
        </section>

        <section class="ts-card">
            <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-calculator"></i> Totals</h3></div>
            <div class="ts-card__body">
                <div class="d-flex justify-content-between mb-2"><span class="text-muted">Subtotal</span><span><?= esc(format_money($invoice['subtotal'])) ?></span></div>
                <div class="d-flex justify-content-between mb-2"><span class="text-muted">Tax</span><span><?= esc(format_money($invoice['tax_amount'])) ?></span></div>
                <div class="d-flex justify-content-between mb-2"><span class="text-muted">Discount</span><span>- <?= esc(format_money($invoice['discount_amount'])) ?></span></div>
                <hr class="my-2">
                <div class="d-flex justify-content-between mb-2 fs-5 fw-bold"><span>Total</span><span><?= esc(format_money($invoice['total_amount'])) ?></span></div>
                <div class="d-flex justify-content-between mb-1"><span class="text-muted">Paid</span><span class="text-success"><?= esc(format_money($invoice['paid_amount'])) ?></span></div>
                <div class="d-flex justify-content-between"><span class="text-muted">Balance due</span><span class="fw-semibold<?= (float) $invoice['balance_amount'] > 0 ? ' text-danger' : '' ?>"><?= esc(format_money($invoice['balance_amount'])) ?></span></div>
            </div>
        </section>
    </div>
</div>

<?= $this->endSection() ?>
