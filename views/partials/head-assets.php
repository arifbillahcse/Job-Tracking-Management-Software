<?php
/** Stylesheets. Bootstrap is served from public/assets/vendor when present; otherwise a pinned CDN copy is used (development only). */
if (has_vendored_bootstrap()): ?>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/css/bootstrap.min.css')) ?>">
<?php elseif (\App\Core\Config::get('app.allow_cdn_fallback')): ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<?php endif; ?>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
