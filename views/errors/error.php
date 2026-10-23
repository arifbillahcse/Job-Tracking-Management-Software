<div class="text-center py-3">
    <div class="jt-error-code"><?= e($status) ?></div>
    <h2 class="h4"><?= e($heading) ?></h2>
    <p class="text-body-secondary"><?= e($message) ?></p>

    <?php if (!empty($debug)): ?>
        <pre class="text-start small bg-body-tertiary border rounded p-2 overflow-auto"><?= e(get_class($debug) . ': ' . $debug->getMessage() . "\n" . $debug->getFile() . ':' . $debug->getLine()) ?></pre>
    <?php endif; ?>

    <a class="btn btn-primary mt-2" href="<?= e(url(\App\Core\Auth::check() ? '/dashboard' : '/login')) ?>">
        <?= \App\Core\Auth::check() ? 'Back to dashboard' : 'Go to sign in' ?>
    </a>
</div>
