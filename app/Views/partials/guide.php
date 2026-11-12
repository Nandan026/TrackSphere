<?php
/**
 * In-module guide panel.
 *
 * Expects:
 *   $guide  array{title, summary, steps[], tips[], statuses[]?}  (see Config\Guides)
 *           statuses: list of {code, label, meaning, badge_class, group} shown as "Status meanings"
 *   $module string  Used to build a stable collapse id (persisted in localStorage).
 */
if (empty($guide) || ! is_array($guide)) {
    return;
}

$module  = preg_replace('/[^a-z0-9_-]/i', '', (string) ($module ?? 'general')) ?: 'general';
$panelId = 'guide-' . $module;
$steps   = $guide['steps'] ?? [];
$tips    = $guide['tips'] ?? [];

// Status meanings (from the status glossary), grouped by section title.
$badgeClasses = ['success', 'info', 'primary', 'warning', 'danger', 'secondary', 'dark'];
$statusGroups = [];
foreach ((array) ($guide['statuses'] ?? []) as $statusItem) {
    if (is_array($statusItem) && ! empty($statusItem['code'])) {
        $statusGroups[(string) ($statusItem['group'] ?? 'Statuses')][] = $statusItem;
    }
}
?>
<section class="ts-guide" aria-label="Module guide">
    <div class="ts-guide__bar">
        <span class="ts-guide__badge"><i class="bi bi-lightbulb"></i></span>
        <div class="ts-guide__heading">
            <div class="ts-guide__eyebrow">Module guide</div>
            <h2 class="ts-guide__title"><?= esc($guide['title'] ?? 'Guide') ?></h2>
        </div>
        <button class="ts-guide__toggle" type="button"
                data-bs-toggle="collapse" data-bs-target="#<?= esc($panelId, 'attr') ?>"
                aria-expanded="true" aria-controls="<?= esc($panelId, 'attr') ?>">
            <i class="bi bi-question-circle"></i>
            <span>How this works</span>
            <i class="bi bi-chevron-down"></i>
        </button>
    </div>

    <div class="collapse show" id="<?= esc($panelId, 'attr') ?>">
        <div class="ts-guide__body">
            <?php if (! empty($guide['summary'])): ?>
                <p class="ts-guide__summary"><?= esc($guide['summary']) ?></p>
            <?php endif; ?>

            <div class="row g-3">
                <?php if ($steps !== []): ?>
                    <div class="<?= $tips !== [] ? 'col-lg-8' : 'col-12' ?>">
                        <ol class="ts-steps">
                            <?php foreach ($steps as $step): ?>
                                <li><span><?= esc($step) ?></span></li>
                            <?php endforeach; ?>
                        </ol>
                    </div>
                <?php endif; ?>

                <?php if ($tips !== []): ?>
                    <div class="<?= $steps !== [] ? 'col-lg-4' : 'col-12' ?>">
                        <aside class="ts-tips">
                            <div class="ts-tips__title"><i class="bi bi-stars"></i> Good to know</div>
                            <ul>
                                <?php foreach ($tips as $tip): ?>
                                    <li><?= esc($tip) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </aside>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($statusGroups !== []): ?>
                <div class="ts-status-glossary" aria-label="Status meanings">
                    <div class="ts-status-glossary__title"><i class="bi bi-tags"></i> Status meanings</div>
                    <?php foreach ($statusGroups as $groupTitle => $items): ?>
                        <?php if (count($statusGroups) > 1): ?>
                            <div class="ts-status-glossary__group"><?= esc($groupTitle) ?></div>
                        <?php endif; ?>
                        <dl class="ts-status-glossary__grid">
                            <?php foreach ($items as $item): ?>
                                <?php
                                $badge = in_array($item['badge_class'] ?? '', $badgeClasses, true) ? $item['badge_class'] : 'secondary';
                                ?>
                                <div class="ts-status-glossary__item">
                                    <dt>
                                        <span class="badge text-bg-<?= esc($badge, 'attr') ?>"><?= esc($item['label'] ?? $item['code'] ?? '') ?></span>
                                        <code><?= esc($item['code'] ?? '') ?></code>
                                    </dt>
                                    <dd><?= esc($item['meaning'] ?? '') ?></dd>
                                </div>
                            <?php endforeach; ?>
                        </dl>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
