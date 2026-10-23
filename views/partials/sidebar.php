<?php
$current = \App\Core\Request::current()?->path() ?? '/';
$items = array_filter(
    (array) \App\Core\Config::get('navigation', []),
    static fn (array $item): bool => $item['permission'] === null || can($item['permission'])
);
?>
<aside class="jt-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="sidebar" aria-labelledby="sidebarLabel">
    <div class="offcanvas-header d-lg-none">
        <span class="jt-brand" id="sidebarLabel"><?= icon('truck') ?> <?= e(app_name()) ?></span>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#sidebar" aria-label="Close menu"></button>
    </div>
    <div class="offcanvas-body flex-column p-0">
        <a class="jt-brand d-none d-lg-flex" href="<?= e(url('/dashboard')) ?>"><?= icon('truck') ?> <span><?= e(app_name()) ?></span></a>
        <nav class="jt-nav" aria-label="Main">
            <?php foreach ($items as $item):
                $active = $current === $item['path'] || str_starts_with($current, rtrim($item['path'], '/') . '/');
                ?>
                <a class="jt-nav-link<?= $active ? ' active' : '' ?>" href="<?= e(url($item['path'])) ?>"<?= $active ? ' aria-current="page"' : '' ?>>
                    <?= icon($item['icon']) ?> <span><?= e($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>
</aside>
