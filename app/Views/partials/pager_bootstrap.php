<?php
/**
 * Custom pager template registered as "tracksphere" in Config\Pager::$templates.
 *
 * @var \CodeIgniter\Pager\PagerRenderer $pager
 */
$pager->setSurroundCount(2);
?>
<ul class="pagination">
    <?php if ($pager->hasPreviousPage()): ?>
        <li class="page-item">
            <a class="page-link" href="<?= $pager->getFirst() ?>" aria-label="First"><i class="bi bi-chevron-double-left"></i></a>
        </li>
        <li class="page-item">
            <a class="page-link" href="<?= $pager->getPreviousPage() ?>" aria-label="Previous"><i class="bi bi-chevron-left"></i></a>
        </li>
    <?php else: ?>
        <li class="page-item disabled"><span class="page-link"><i class="bi bi-chevron-double-left"></i></span></li>
        <li class="page-item disabled"><span class="page-link"><i class="bi bi-chevron-left"></i></span></li>
    <?php endif; ?>

    <?php foreach ($pager->links() as $link): ?>
        <li class="page-item<?= $link['active'] ? ' active' : '' ?>">
            <a class="page-link" href="<?= $link['uri'] ?>"><?= $link['title'] ?></a>
        </li>
    <?php endforeach; ?>

    <?php if ($pager->hasNextPage()): ?>
        <li class="page-item">
            <a class="page-link" href="<?= $pager->getNextPage() ?>" aria-label="Next"><i class="bi bi-chevron-right"></i></a>
        </li>
        <li class="page-item">
            <a class="page-link" href="<?= $pager->getLast() ?>" aria-label="Last"><i class="bi bi-chevron-double-right"></i></a>
        </li>
    <?php else: ?>
        <li class="page-item disabled"><span class="page-link"><i class="bi bi-chevron-right"></i></span></li>
        <li class="page-item disabled"><span class="page-link"><i class="bi bi-chevron-double-right"></i></span></li>
    <?php endif; ?>
</ul>
