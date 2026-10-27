<?php
/** @var array<string,mixed>|null $foreman  @var string $action */
$val = static fn (string $key): string => old_or($key, $foreman[$key] ?? '');
?>
<div class="row">
    <div class="col-12 col-xl-6">
        <form class="card jt-card" method="post" action="<?= e(url($action)) ?>" novalidate data-loading data-unsaved>
            <?= csrf_field() ?>
            <div class="card-header bg-transparent fw-semibold"><?= $foreman === null ? 'New foreman' : 'Foreman details' ?></div>
            <div class="card-body">
                <div class="mb-3">
                    <label for="name" class="form-label">Name <span class="text-danger" aria-hidden="true">*</span></label>
                    <input type="text" class="form-control<?= has_error('name') ? ' is-invalid' : '' ?>" id="name" name="name" value="<?= e($val('name')) ?>" maxlength="150" required>
                    <?= error_text('name') ?>
                </div>
                <div class="mb-3">
                    <label for="phone" class="form-label">Phone <span class="text-body-secondary fw-normal">(optional)</span></label>
                    <input type="tel" class="form-control<?= has_error('phone') ? ' is-invalid' : '' ?>" id="phone" name="phone" value="<?= e($val('phone')) ?>" maxlength="30" inputmode="tel">
                    <?= error_text('phone') ?>
                </div>
            </div>
            <div class="card-footer bg-transparent d-flex gap-2">
                <a class="btn btn-outline-secondary" href="<?= e(url('/foremen')) ?>">Cancel</a>
                <button type="submit" class="btn btn-primary"><?= $foreman === null ? 'Add foreman' : 'Save changes' ?></button>
            </div>
        </form>
    </div>
</div>
