<?php
/**
 * Edit / deactivate / activate buttons for a master-data table row.
 *
 * Expects:
 *   $base    string  route path, e.g. 'customers'
 *   $id      int     record id
 *   $status  string  current status (active|inactive)
 *   $label   string  singular noun for the confirm text, e.g. 'customer'
 *   $name    string  record display name (confirm text)
 *   $canEdit bool    (optional, default true)  false hides every button (read-only rows)
 *   $canToggle bool  (optional, default true)  false hides deactivate/activate (e.g. own account)
 */
$canEdit   = $canEdit ?? true;
$canToggle = $canToggle ?? true;

if (! $canEdit) {
    echo '<span class="text-muted small">Read-only</span>';

    return;
}
?>
<a href="<?= site_url($base . '/' . (int) $id . '/edit') ?>" class="ts-icon-btn" title="Edit" aria-label="Edit <?= esc($name, 'attr') ?>">
    <i class="bi bi-pencil-square"></i>
</a>
<?php if ($canToggle): ?>
    <?php if (($status ?? 'active') === 'active'): ?>
        <form action="<?= site_url($base . '/' . (int) $id . '/delete') ?>" method="post" class="d-inline"
              data-confirm="Deactivate <?= esc($label, 'attr') ?> &quot;<?= esc($name, 'attr') ?>&quot;? Existing history is kept.">
            <?= csrf_field() ?>
            <button type="submit" class="ts-icon-btn ts-icon-btn--danger" title="Deactivate" aria-label="Deactivate <?= esc($name, 'attr') ?>">
                <i class="bi bi-slash-circle"></i>
            </button>
        </form>
    <?php else: ?>
        <form action="<?= site_url($base . '/' . (int) $id . '/activate') ?>" method="post" class="d-inline">
            <?= csrf_field() ?>
            <button type="submit" class="ts-icon-btn" title="Activate" aria-label="Activate <?= esc($name, 'attr') ?>">
                <i class="bi bi-check-circle"></i>
            </button>
        </form>
    <?php endif; ?>
<?php endif; ?>
