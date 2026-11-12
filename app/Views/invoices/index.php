<?php
/**
 * Invoice list.
 *
 * Controller passes: $invoices, $pager, $status, $q, $counts, $statuses, $today.
 */
$total = array_sum($counts);
$label = static fn (string $s): string => ucwords(str_replace('_', ' ', $s));
$qs    = static fn (array $p): string => http_build_query(array_filter($p, static fn ($v) => $v !== '' && $v !== null));
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="ts-page-head">
    <div>
        <h2>Invoices</h2>
        <p>Bill customers for shipping services and track payment status.</p>
    </div>
    <a href="<?= site_url('invoices/create') ?>" class="btn btn-accent"><i class="bi bi-plus-lg me-1"></i> New invoice</a>
</div>

<div class="ts-chips">
    <a href="<?= site_url('invoices' . ($q !== '' ? '?q=' . rawurlencode($q) : '')) ?>" class="ts-chip<?= $status === '' ? ' active' : '' ?>">All <span class="ts-chip__count"><?= esc($total) ?></span></a>
    <?php foreach ($statuses as $code): ?>
        <a href="<?= site_url('invoices?' . $qs(['status' => $code, 'q' => $q])) ?>" class="ts-chip<?= $status === $code ? ' active' : '' ?>">
            <?= esc($label($code)) ?> <span class="ts-chip__count"><?= esc((int) ($counts[$code] ?? 0)) ?></span>
        </a>
    <?php endforeach; ?>
</div>

<section class="ts-card">
    <form method="get" action="<?= site_url('invoices') ?>" class="ts-filters" role="search">
        <div class="flex-grow-1" style="min-width:240px">
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="search" name="q" value="<?= esc($q, 'attr') ?>" class="form-control" placeholder="Invoice # or customer" maxlength="60">
            </div>
        </div>
        <div style="min-width:170px">
            <select name="status" class="form-select" aria-label="Status">
                <option value="">All statuses</option>
                <?php foreach ($statuses as $code): ?><option value="<?= esc($code, 'attr') ?>" <?= $status === $code ? 'selected' : '' ?>><?= esc($label($code)) ?></option><?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i> Filter</button>
        <?php if ($q !== '' || $status !== ''): ?><a href="<?= site_url('invoices') ?>" class="btn btn-soft">Reset</a><?php endif; ?>
    </form>

    <div class="ts-card__body ts-card__body--flush">
        <?php if ($invoices === []): ?>
            <div class="ts-empty">
                <i class="bi bi-receipt"></i>
                <strong>No invoices found</strong>
                Create an invoice to bill a customer for their shipments.
                <div class="mt-3"><a href="<?= site_url('invoices/create') ?>" class="btn btn-accent btn-sm"><i class="bi bi-plus-lg me-1"></i> New invoice</a></div>
            </div>
        <?php else: ?>
            <div class="ts-table-wrap">
                <table class="ts-table">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Customer</th>
                            <th>Date</th>
                            <th>Due</th>
                            <th class="text-end">Total</th>
                            <th class="text-end">Balance</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($invoices as $inv): ?>
                        <?php $overdue = $inv['status'] === 'sent' && ! empty($inv['due_date']) && $inv['due_date'] < $today; ?>
                        <tr>
                            <td><a href="<?= site_url('invoices/' . (int) $inv['id']) ?>" class="ts-mono ts-cell-strong"><?= esc($inv['invoice_number']) ?></a></td>
                            <td>
                                <?= esc($inv['customer_name'] ?? '—') ?>
                                <?php if (! empty($inv['customer_code'])): ?><span class="ts-cell-sub"><?= esc($inv['customer_code']) ?></span><?php endif; ?>
                            </td>
                            <td class="text-muted text-nowrap"><?= esc(format_datetime($inv['invoice_date'] . ' 00:00:00', 'd M Y', 'UTC')) ?></td>
                            <td class="text-nowrap<?= $overdue ? ' text-danger fw-semibold' : ' text-muted' ?>">
                                <?= ! empty($inv['due_date']) ? esc(format_datetime($inv['due_date'] . ' 00:00:00', 'd M Y', 'UTC')) : '—' ?>
                                <?php if ($overdue): ?><span class="ts-cell-sub text-danger">Overdue</span><?php endif; ?>
                            </td>
                            <td class="text-end ts-cell-strong"><?= esc(format_money($inv['total_amount'])) ?></td>
                            <td class="text-end"><?= esc(format_money($inv['balance_amount'])) ?></td>
                            <td><?= format_status_badge($inv['status']) ?></td>
                            <td class="ts-actions">
                                <a href="<?= site_url('invoices/' . (int) $inv['id']) ?>" class="btn btn-sm btn-soft"><i class="bi bi-eye me-1"></i> View</a>
                                <?php if ($inv['status'] === 'draft'): ?>
                                    <form method="post" action="<?= site_url('invoices/' . (int) $inv['id'] . '/sent') ?>" class="d-inline"
                                          data-confirm="Mark invoice <?= esc($inv['invoice_number'], 'attr') ?> as sent?">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-send me-1"></i> Mark sent</button>
                                    </form>
                                <?php endif; ?>
                                <?php if (in_array($inv['status'], ['draft', 'sent'], true)): ?>
                                    <form method="post" action="<?= site_url('invoices/' . (int) $inv['id'] . '/paid') ?>" class="d-inline"
                                          data-confirm="Mark invoice <?= esc($inv['invoice_number'], 'attr') ?> as fully paid?">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-outline-success"><i class="bi bi-check2-circle me-1"></i> Mark paid</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= view('partials/pagination', ['pager' => $pager]) ?>
        <?php endif; ?>
    </div>
</section>

<?= $this->endSection() ?>
