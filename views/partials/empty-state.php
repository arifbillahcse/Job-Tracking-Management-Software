<?php
/** @var string $heading  @var string $message  @var string|null $actionUrl  @var string|null $actionLabel */
?>
<div class="jt-empty">
    <?= icon($emptyIcon ?? 'inbox', 'jt-empty-icon') ?>
    <h2 class="h5 mb-1"><?= e($heading) ?></h2>
    <p class="text-body-secondary mb-3"><?= e($message) ?></p>
    <?php if (!empty($actionUrl)): ?>
        <a class="btn btn-primary" href="<?= e(url($actionUrl)) ?>"><?= icon('plus') ?> <?= e($actionLabel ?? 'Add') ?></a>
    <?php endif; ?>
</div>
