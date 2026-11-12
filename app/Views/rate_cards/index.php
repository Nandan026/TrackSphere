<?php
/**
 * Rate cards list.
 *
 * @var list<array<string, mixed>> $rates
 * @var list<array<string, mixed>> $couriers
 * @var object|null                $pager
 * @var string                     $q
 * @var string                     $status
 * @var int                        $courierId
 * @var string                     $today  Y-m-d (UTC)
 */
$num = static fn ($n, int $dec = 2): string => number_format((float) $n, $dec, '.', '');
$wt  = static fn ($n): string => rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.');
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="ts-page-head">
    <div>
        <h2>Rate cards</h2>
        <p>Courier pricing by weight band, route and validity period.</p>
    </div>
    <a href="<?= site_url('rate-cards/create') ?>" class="btn btn-accent"><i class="bi bi-plus-lg me-1"></i> Add Rate Card</a>
</div>

<section class="ts-card">
    <form method="get" action="<?= site_url('rate-cards') ?>" class="ts-filters">
        <div class="input-group" style="max-width: 320px;">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="search" name="q" value="<?= esc($q, 'attr') ?>" class="form-control"
                   placeholder="Search service, courier, prefix" aria-label="Search rate cards">
        </div>
        <select name="courier" class="form-select" style="max-width: 200px;" aria-label="Courier filter">
            <option value="">All couriers</option>
            <?php foreach ($couriers as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= $courierId === (int) $c['id'] ? 'selected' : '' ?>><?= esc($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" class="form-select" style="max-width: 160px;" aria-label="Status filter">
            <option value="">All statuses</option>
            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
        <button type="submit" class="btn btn-soft"><i class="bi bi-funnel me-1"></i> Filter</button>
        <?php if ($q !== '' || $status !== '' || $courierId > 0): ?>
            <a href="<?= site_url('rate-cards') ?>" class="btn btn-outline-primary">Reset</a>
        <?php endif; ?>
    </form>

    <div class="ts-card__body ts-card__body--flush">
        <?php if (empty($rates)): ?>
            <div class="ts-empty">
                <i class="bi bi-tags"></i>
                <strong>No rate cards found</strong>
                <?= ($q !== '' || $status !== '' || $courierId > 0) ? 'Try a different search or filter.' : 'Add a rate card to price shipments.' ?>
            </div>
        <?php else: ?>
            <div class="ts-table-wrap">
                <table class="ts-table">
                    <thead>
                        <tr>
                            <th>Service</th>
                            <th>Route</th>
                            <th>Weight (kg)</th>
                            <th class="text-end">Base</th>
                            <th class="text-end">Per kg</th>
                            <th class="text-end">COD</th>
                            <th class="text-end">Fuel</th>
                            <th>Validity</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rates as $r): ?>
                            <?php $expired = ! empty($r['effective_to']) && $r['effective_to'] < $today; ?>
                            <tr>
                                <td>
                                    <span class="ts-cell-strong"><?= esc($r['service_name']) ?></span>
                                    <span class="ts-cell-sub"><?= esc($r['courier_name'] ?? 'Any courier') ?></span>
                                </td>
                                <td>
                                    <span class="ts-mono"><?= esc($r['origin_postal_prefix'] ?: 'Any') ?></span>
                                    <i class="bi bi-arrow-right small text-muted"></i>
                                    <span class="ts-mono"><?= esc($r['destination_postal_prefix'] ?: 'Any') ?></span>
                                </td>
                                <td><?= esc($wt($r['min_weight_kg'])) ?> – <?= $r['max_weight_kg'] !== null ? esc($wt($r['max_weight_kg'])) : '∞' ?></td>
                                <td class="text-end"><?= esc(format_money($r['base_charge'])) ?></td>
                                <td class="text-end"><?= esc(format_money($r['per_kg_charge'])) ?></td>
                                <td class="text-end"><?= esc(format_money($r['cod_charge'])) ?></td>
                                <td class="text-end"><?= esc($num($r['fuel_surcharge_percent'])) ?>%</td>
                                <td>
                                    <?= esc(date('d M Y', strtotime((string) $r['effective_from']))) ?>
                                    <span class="ts-cell-sub">
                                        <?= ! empty($r['effective_to']) ? 'to ' . esc(date('d M Y', strtotime((string) $r['effective_to']))) : 'No end date' ?>
                                        <?php if ($expired): ?><span class="ts-badge ts-badge--overdue">Expired</span><?php endif; ?>
                                    </span>
                                </td>
                                <td><span class="ts-badge ts-badge--<?= esc($r['status'], 'attr') ?>"><?= esc(ucfirst((string) $r['status'])) ?></span></td>
                                <td class="ts-actions">
                                    <?= view('partials/master_row_actions', [
                                        'base' => 'rate-cards', 'id' => $r['id'], 'status' => $r['status'],
                                        'label' => 'rate card', 'name' => $r['service_name'],
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
