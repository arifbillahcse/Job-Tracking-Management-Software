<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e(($title ?? 'Home') . ' · ' . app_name()) ?></title>
<?= \App\Core\View::partial('partials/head-assets') ?>
</head>
<body class="jt-app">
<div class="jt-shell">
    <?= \App\Core\View::partial('partials/sidebar') ?>
    <div class="jt-main">
        <?= \App\Core\View::partial('partials/topbar', ['title' => $title ?? '']) ?>
        <main class="jt-content">
            <?= \App\Core\View::partial('partials/flash') ?>
            <?= $content ?>
        </main>
        <footer class="jt-footer">&copy; <?= e(gmdate('Y')) ?> <?= e(app_name()) ?></footer>
    </div>
</div>
<?= \App\Core\View::partial('partials/foot-scripts') ?>
</body>
</html>
