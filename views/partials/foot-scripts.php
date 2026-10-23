<?php if (has_vendored_bootstrap()): ?>
    <script src="<?= e(asset('vendor/bootstrap/js/bootstrap.bundle.min.js')) ?>"></script>
<?php elseif (\App\Core\Config::get('app.allow_cdn_fallback')): ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php endif; ?>
    <script src="<?= e(asset('js/app.js')) ?>"></script>
