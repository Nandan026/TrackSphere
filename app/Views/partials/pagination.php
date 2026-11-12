<?php
/**
 * Bootstrap-styled pagination wrapper.
 *
 * Usage in a view:  <?= view('partials/pagination', ['pager' => $pager]) ?>
 * where $pager is a CodeIgniter\Pager\Pager (e.g. $model->pager) and the
 * list was paginated with $model->paginate($perPage).
 *
 * Optional: $pagerGroup (string) for named pager groups.
 */
if (empty($pager) || ! is_object($pager)) {
    return;
}

$group = $pagerGroup ?? 'default';

if (method_exists($pager, 'getPageCount') && $pager->getPageCount($group) <= 1) {
    return;
}
?>
<nav class="ts-pagination" aria-label="Pagination">
    <?= $pager->links($group, 'tracksphere') ?>
</nav>
