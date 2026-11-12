<?php
/**
 * Reports: shipment summary, delivery success, COD summary.
 *
 * Controller passes: $range (from, to, error), $courierId, $couriers, $summary,
 * $performance, $cod, $rangeError.
 */
$exportQuery = http_build_query(array_filter([
    'from'       => $range['from'],
    'to'         => $range['to'],
    'courier_id' => $courierId > 0 ? $courierId : null,
]));
$pct = static fn (?float $v): string => $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.') . '%';
$barColor = [
    'delivered' => '#22a06b', 'out_for_delivery' => '#e36414', 'in_transit' => '#1d7a90', 'picked_up' => '#14b8a6',
    'reached_destination_hub' => '#6b7bd1', 'pickup_scheduled' => '#3b82f6', 'ready_to_ship' => '#22a3c4', 'draft' => '#94a3b8',
    'delivery_failed' => '#d6455a', 'pickup_failed' => '#d6455a', 'cancelled' => '#8b94a3',
    'rto_initiated' => '#e0a100', 'rto_in_transit' => '#e08a00', 'rto_delivered' => '#a98542',
];
$maxCount = 0;
foreach ($summary['rows'] as $row) {
    $maxCount = max($maxCount, $row['count']);
}
$collectedPct = $cod['total'] > 0 ? round($cod['collected'] / $cod['total'] * 100, 1) : 0;
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('styles') ?>
<style>
    .ts-bars { display: grid; gap: .7rem; }
    .ts-bar-row { display: grid; grid-template-columns: 170px 1fr 90px; align-items: center; gap: .75rem; font-size: .88rem; }
    .ts-bar-track { background: var(--ts-slate-50, #f1f5f9); border: 1px solid var(--ts-border, #e2e8f0); border-radius: 999px; height: .85rem; overflow: hidden; }
    .ts-bar-fill { height: 100%; border-radius: 999px; min-width: 2px; transition: width .4s ease; }
    .ts-bar-label { font-weight: 600; color: var(--ts-slate-950, #0f172a); }
    .ts-bar-value { text-align: right; font-variant-numeric: tabular-nums; }
    .ts-bar-value small { color: var(--ts-text-muted, #64748b); }
    .ts-rate { font-size: 2.4rem; font-weight: 800; line-height: 1; font-family: 'Plus Jakarta Sans', 'DM Sans', sans-serif; }
    .ts-stack { display: flex; height: 1rem; border-radius: 999px; overflow: hidden; background: var(--ts-slate-50, #f1f5f9); border: 1px solid var(--ts-border, #e2e8f0); }
    .ts-stack > span { display: block; height: 100%; }
    .ts-legend { display: flex; flex-wrap: wrap; gap: .4rem 1rem; font-size: .8rem; margin-top: .6rem; }
    .ts-legend i { display: inline-block; width: .65rem; height: .65rem; border-radius: 50%; margin-right: .35rem; vertical-align: baseline; }
    @media (max-width: 575.98px) { .ts-bar-row { grid-template-columns: 1fr 70px; } .ts-bar-track { grid-column: 1 / -1; order: 3; } }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<div class="ts-page-head">
    <div>
        <h2>Reports</h2>
        <p>Shipment volumes, delivery performance and COD collections for <?= esc(format_datetime($range['from'] . ' 12:00:00', 'd M Y', 'UTC')) ?> – <?= esc(format_datetime($range['to'] . ' 12:00:00', 'd M Y', 'UTC')) ?>.</p>
    </div>
    <a href="<?= site_url('reports/export/shipment-summary?' . $exportQuery) ?>" class="btn btn-accent"><i class="bi bi-download me-1"></i> Export shipment summary (CSV)</a>
</div>

<?php if (! empty($rangeError)): ?>
    <div class="ts-flash ts-flash--warning" role="alert">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div class="ts-flash__body"><?= esc($rangeError) ?></div>
    </div>
<?php endif; ?>

<section class="ts-card mb-4">
    <form method="get" action="<?= site_url('reports') ?>" class="ts-filters">
        <div style="min-width:160px">
            <label class="form-label small mb-1" for="from">From</label>
            <input type="date" name="from" id="from" class="form-control" value="<?= esc($range['from'], 'attr') ?>">
        </div>
        <div style="min-width:160px">
            <label class="form-label small mb-1" for="to">To</label>
            <input type="date" name="to" id="to" class="form-control" value="<?= esc($range['to'], 'attr') ?>">
        </div>
        <div style="min-width:200px">
            <label class="form-label small mb-1" for="courier_id">Courier</label>
            <select name="courier_id" id="courier_id" class="form-select">
                <option value="">All couriers</option>
                <?php foreach ($couriers as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $courierId === (int) $c['id'] ? 'selected' : '' ?>><?= esc($c['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="d-flex gap-2 align-items-end">
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i> Apply</button>
            <a href="<?= site_url('reports') ?>" class="btn btn-soft">Last 30 days</a>
        </div>
    </form>
</section>

<!-- KPIs -->
<div class="row g-3 mb-4 ts-stagger">
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="ts-card ts-kpi ts-kpi--teal">
            <span class="ts-kpi__icon"><i class="bi bi-boxes"></i></span>
            <span class="ts-kpi__body">
                <div class="ts-kpi__label">Shipments created</div>
                <div class="ts-kpi__value"><?= esc(number_format($summary['total'])) ?></div>
                <div class="ts-kpi__hint">In the selected period</div>
            </span>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="ts-card ts-kpi ts-kpi--green">
            <span class="ts-kpi__icon"><i class="bi bi-check2-circle"></i></span>
            <span class="ts-kpi__body">
                <div class="ts-kpi__label">Delivery success</div>
                <div class="ts-kpi__value"><?= esc($pct($performance['success_rate'])) ?></div>
                <div class="ts-kpi__hint"><?= esc($performance['delivered']) ?> delivered of <?= esc($performance['delivered'] + $performance['failed'] + $performance['rto']) ?> concluded</div>
            </span>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="ts-card ts-kpi ts-kpi--blue">
            <span class="ts-kpi__icon"><i class="bi bi-cash-stack"></i></span>
            <span class="ts-kpi__body">
                <div class="ts-kpi__label">COD collected</div>
                <div class="ts-kpi__value"><?= esc(format_money($cod['collected'], 'INR', 0)) ?></div>
                <div class="ts-kpi__hint">Of <?= esc(format_money($cod['total'], 'INR', 0)) ?> COD value</div>
            </span>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="ts-card ts-kpi ts-kpi--coral">
            <span class="ts-kpi__icon"><i class="bi bi-hourglass-split"></i></span>
            <span class="ts-kpi__body">
                <div class="ts-kpi__label">Awaiting remittance</div>
                <div class="ts-kpi__value"><?= esc(format_money($cod['awaiting_net'], 'INR', 0)) ?></div>
                <div class="ts-kpi__hint"><?= esc($cod['awaiting_count']) ?> pending batch(es)</div>
            </span>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Shipment summary -->
    <div class="col-xl-7">
        <section class="ts-card h-100">
            <div class="ts-card__head d-flex justify-content-between align-items-center">
                <h3 class="ts-card__title mb-0"><i class="bi bi-bar-chart-line"></i> Shipments by status</h3>
                <a href="<?= site_url('reports/export/shipment-summary?' . $exportQuery) ?>" class="btn btn-sm btn-soft"><i class="bi bi-filetype-csv me-1"></i> CSV</a>
            </div>
            <div class="ts-card__body">
                <?php if ($summary['total'] === 0): ?>
                    <div class="ts-empty">
                        <i class="bi bi-bar-chart"></i>
                        <strong>No shipments in this period</strong>
                        Try a wider date range or another courier.
                    </div>
                <?php else: ?>
                    <div class="ts-bars">
                        <?php foreach ($summary['rows'] as $row): ?>
                            <?php if ($row['count'] === 0) { continue; } ?>
                            <?php $width = $maxCount > 0 ? max(1, round($row['count'] / $maxCount * 100)) : 0; ?>
                            <div class="ts-bar-row">
                                <a class="ts-bar-label text-decoration-none" href="<?= site_url('shipments?status=' . rawurlencode($row['status'])) ?>"><?= esc($row['label']) ?></a>
                                <div class="ts-bar-track" role="img" aria-label="<?= esc($row['label'] . ': ' . $row['count'], 'attr') ?>">
                                    <div class="ts-bar-fill" style="width:<?= (int) $width ?>%;background:<?= esc($barColor[$row['status']] ?? '#64748b', 'attr') ?>"></div>
                                </div>
                                <div class="ts-bar-value"><strong><?= esc(number_format($row['count'])) ?></strong> <small><?= esc($pct($row['percent'])) ?></small></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($summary['by_courier'] !== []): ?>
                <div class="ts-card__body ts-card__body--flush border-top">
                    <div class="ts-table-wrap">
                        <table class="ts-table">
                            <thead><tr><th>Courier</th><th class="text-end">Shipments</th><th class="text-end">Delivered</th><th class="text-end">Delivered %</th></tr></thead>
                            <tbody>
                            <?php foreach ($summary['by_courier'] as $c): ?>
                                <tr>
                                    <td class="ts-cell-strong"><?= esc($c['courier']) ?></td>
                                    <td class="text-end"><?= esc(number_format($c['total'])) ?></td>
                                    <td class="text-end"><?= esc(number_format($c['delivered'])) ?></td>
                                    <td class="text-end"><?= esc($pct($c['total'] > 0 ? round($c['delivered'] / $c['total'] * 100, 1) : null)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div class="col-xl-5">
        <!-- Delivery success -->
        <section class="ts-card mb-3">
            <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-bullseye"></i> Delivery success rate</h3></div>
            <div class="ts-card__body">
                <div class="d-flex align-items-end gap-3 mb-3">
                    <div class="ts-rate text-success"><?= esc($pct($performance['success_rate'])) ?></div>
                    <div class="text-muted small pb-1">delivered ÷ (delivered + failed + RTO)</div>
                </div>
                <?php
                $seg = [
                    ['Delivered', $performance['delivered'], '#22a06b'],
                    ['Delivery failed', $performance['failed'], '#d6455a'],
                    ['RTO', $performance['rto'], '#e0a100'],
                    ['In progress', $performance['in_progress'], '#94a3b8'],
                    ['Cancelled', $performance['cancelled'], '#8b94a3'],
                ];
                $segTotal = max(1, array_sum(array_column($seg, 1)));
                ?>
                <div class="ts-stack" role="img" aria-label="Shipment outcomes">
                    <?php foreach ($seg as [$name, $value, $color]): ?>
                        <?php if ($value > 0): ?><span title="<?= esc($name . ': ' . $value, 'attr') ?>" style="width:<?= esc(round($value / $segTotal * 100, 2), 'attr') ?>%;background:<?= esc($color, 'attr') ?>"></span><?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <div class="ts-legend">
                    <?php foreach ($seg as [$name, $value, $color]): ?>
                        <span><i style="background:<?= esc($color, 'attr') ?>"></i><?= esc($name) ?> <strong><?= esc(number_format($value)) ?></strong></span>
                    <?php endforeach; ?>
                </div>
                <hr>
                <dl class="ts-kv mb-0">
                    <div><dt>On-time deliveries</dt><dd><?= esc($pct($performance['on_time_rate'])) ?></dd></div>
                    <div><dt>Delivery attempts</dt><dd><?= esc(number_format($performance['attempts'])) ?></dd></div>
                    <div><dt>Attempt success</dt><dd><?= esc($pct($performance['attempt_rate'])) ?></dd></div>
                    <div><dt>Failed attempts</dt><dd><?= esc(number_format($performance['attempts_failed'])) ?></dd></div>
                </dl>
            </div>
        </section>

        <!-- COD -->
        <section class="ts-card">
            <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-cash-coin"></i> COD summary</h3></div>
            <div class="ts-card__body">
                <div class="d-flex justify-content-between mb-1"><span class="text-muted">COD shipments</span><strong><?= esc(number_format($cod['shipments'])) ?></strong></div>
                <div class="d-flex justify-content-between mb-1"><span class="text-muted">Total COD value</span><strong><?= esc(format_money($cod['total'])) ?></strong></div>
                <div class="d-flex justify-content-between mb-1"><span class="text-muted">Collected (<?= esc($cod['collected_count']) ?> delivered)</span><strong class="text-success"><?= esc(format_money($cod['collected'])) ?></strong></div>
                <div class="d-flex justify-content-between mb-1"><span class="text-muted">Still in the pipeline</span><strong><?= esc(format_money($cod['pending'])) ?></strong></div>
                <div class="d-flex justify-content-between mb-3"><span class="text-muted">Returned (RTO)</span><strong class="text-danger"><?= esc(format_money($cod['returned'])) ?></strong></div>

                <div class="ts-stack mb-1" role="img" aria-label="COD collected share">
                    <span style="width:<?= esc($collectedPct, 'attr') ?>%;background:#22a06b"></span>
                </div>
                <div class="small text-muted mb-3"><?= esc($pct((float) $collectedPct)) ?> of COD value collected</div>

                <hr>
                <div class="d-flex justify-content-between mb-1"><span class="text-muted">Remitted in period (<?= esc($cod['remitted_count']) ?>)</span><strong><?= esc(format_money($cod['remitted_net'])) ?></strong></div>
                <div class="d-flex justify-content-between"><span class="text-muted">Awaiting remittance (<?= esc($cod['awaiting_count']) ?>)</span><strong class="text-warning"><?= esc(format_money($cod['awaiting_net'])) ?></strong></div>
            </div>
            <?php if ($cod['by_courier'] !== []): ?>
                <div class="ts-card__body ts-card__body--flush border-top">
                    <div class="ts-table-wrap">
                        <table class="ts-table">
                            <thead><tr><th>Courier</th><th class="text-end">COD shipments</th><th class="text-end">Collected</th></tr></thead>
                            <tbody>
                            <?php foreach ($cod['by_courier'] as $c): ?>
                                <tr>
                                    <td class="ts-cell-strong"><?= esc($c['courier']) ?></td>
                                    <td class="text-end"><?= esc(number_format($c['shipments'])) ?></td>
                                    <td class="text-end"><?= esc(format_money($c['collected'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<?= $this->endSection() ?>
