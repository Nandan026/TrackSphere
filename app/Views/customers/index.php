<?php
/**
 * Customers list.
 *
 * @var list<array<string, mixed>> $customers
 * @var object|null                $pager
 * @var string                     $q
 * @var string                     $status
 */
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="ts-page-head">
    <div>
        <h2>Customers</h2>
        <p>Businesses and individuals you ship for.</p>
    </div>
    <a href="<?= site_url('customers/create') ?>" class="btn btn-accent"><i class="bi bi-plus-lg me-1"></i> Add Customer</a>
</div>

<section class="ts-card">
    <form method="get" action="<?= site_url('customers') ?>" class="ts-filters">
        <div class="input-group" style="max-width: 360px;">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="search" name="q" value="<?= esc($q, 'attr') ?>" class="form-control"
                   placeholder="Search name, code, phone, email" aria-label="Search customers">
        </div>
        <select name="status" class="form-select" style="max-width: 160px;" aria-label="Status filter">
            <option value="">All statuses</option>
            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
        <button type="submit" class="btn btn-soft"><i class="bi bi-funnel me-1"></i> Filter</button>
        <?php if ($q !== '' || $status !== ''): ?>
            <a href="<?= site_url('customers') ?>" class="btn btn-outline-primary">Reset</a>
        <?php endif; ?>
    </form>

    <div class="ts-card__body ts-card__body--flush">
        <?php if (empty($customers)): ?>
            <div class="ts-empty">
                <i class="bi bi-people"></i>
                <strong>No customers found</strong>
                <?= ($q !== '' || $status !== '') ? 'Try a different search or filter.' : 'Add your first customer to start creating shipments.' ?>
            </div>
        <?php else: ?>
            <div class="ts-table-wrap">
                <table class="ts-table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Customer</th>
                            <th>Contact</th>
                            <th>Type</th>
                            <th>City</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customers as $c): ?>
                            <tr>
                                <td><span class="ts-mono"><?= esc($c['customer_code']) ?></span></td>
                                <td>
                                    <span class="ts-cell-strong"><?= esc($c['name']) ?></span>
                                    <?php if (! empty($c['gstin'])): ?>
                                        <span class="ts-cell-sub">GSTIN <?= esc($c['gstin']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= esc($c['phone']) ?>
                                    <?php if (! empty($c['email'])): ?>
                                        <span class="ts-cell-sub"><?= esc($c['email']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= esc(ucfirst((string) $c['customer_type'])) ?></td>
                                <td><?= esc($c['primary_city'] ?? '—') ?></td>
                                <td><span class="ts-badge ts-badge--<?= esc($c['status'], 'attr') ?>"><?= esc(ucfirst((string) $c['status'])) ?></span></td>
                                <td class="ts-actions">
                                    <?= view('partials/master_row_actions', [
                                        'base' => 'customers', 'id' => $c['id'], 'status' => $c['status'],
                                        'label' => 'customer', 'name' => $c['name'],
                                    ]) ?>
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
