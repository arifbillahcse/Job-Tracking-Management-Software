<?php
/**
 * Confirmation dialog for a destructive POST action (needs Bootstrap's JS).
 * @var string $modalId  @var string $heading  @var string $message  @var string $action  @var string $confirmLabel
 */
?>
<div class="modal fade" id="<?= e($modalId) ?>" tabindex="-1" aria-labelledby="<?= e($modalId) ?>Label" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?= e(url($action)) ?>" data-loading>
            <?= csrf_field() ?>
            <div class="modal-header">
                <h2 class="modal-title h5" id="<?= e($modalId) ?>Label"><?= e($heading) ?></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body"><?= e($message) ?></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger"><?= e($confirmLabel) ?></button>
            </div>
        </form>
    </div>
</div>
