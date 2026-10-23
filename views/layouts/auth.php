<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e(($title ?? 'Sign in') . ' · ' . app_name()) ?></title>
<?= \App\Core\View::partial('partials/head-assets') ?>
</head>
<body class="jt-auth">
<main class="jt-auth-wrap">
    <div class="jt-auth-brand"><?= icon('truck') ?> <span><?= e(app_name()) ?></span></div>
    <div class="card jt-auth-card shadow-sm">
        <div class="card-body p-4">
            <?= \App\Core\View::partial('partials/flash') ?>
            <?= $content ?>
        </div>
    </div>
</main>
<?= \App\Core\View::partial('partials/foot-scripts') ?>
</body>
</html>
