<?php
/** @var \App\Helpers\Paginator $paginator  @var string $basePath  @var array|null $linkParams query parameters to keep on every link */
$pageLink = static fn (int $p): string => isset($linkParams) ? list_url($basePath, $linkParams + ['page' => $p]) : page_url($basePath, $p);
if ($paginator->lastPage > 1):
    $prev = max(1, $paginator->page - 1);
    $next = min($paginator->lastPage, $paginator->page + 1);
    ?>
    <nav class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3" aria-label="Pagination">
        <div class="small text-body-secondary">
            Showing <?= e($paginator->from()) ?>&ndash;<?= e($paginator->to()) ?> of <?= e($paginator->total) ?>
        </div>
        <ul class="pagination pagination-sm mb-0">
            <li class="page-item<?= $paginator->page === 1 ? ' disabled' : '' ?>">
                <a class="page-link" href="<?= e($pageLink($prev)) ?>" aria-label="Previous page">&laquo;</a>
            </li>
            <?php foreach ($paginator->window() as $p): ?>
                <?php if ($p === null): ?>
                    <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                <?php else: ?>
                    <li class="page-item<?= $p === $paginator->page ? ' active' : '' ?>">
                        <a class="page-link" href="<?= e($pageLink($p)) ?>"<?= $p === $paginator->page ? ' aria-current="page"' : '' ?>><?= e($p) ?></a>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>
            <li class="page-item<?= $paginator->page === $paginator->lastPage ? ' disabled' : '' ?>">
                <a class="page-link" href="<?= e($pageLink($next)) ?>" aria-label="Next page">&raquo;</a>
            </li>
        </ul>
    </nav>
<?php endif; ?>
