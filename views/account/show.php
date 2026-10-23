<?php $user = auth_user(); ?>
<div class="row g-4">
    <div class="col-12 col-lg-5">
        <div class="card jt-card h-100">
            <div class="card-header bg-transparent fw-semibold">Profile</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-4 text-body-secondary fw-normal">Name</dt>
                    <dd class="col-8"><?= e($user['name']) ?></dd>
                    <dt class="col-4 text-body-secondary fw-normal">Email</dt>
                    <dd class="col-8 text-break"><?= e($user['email']) ?></dd>
                    <dt class="col-4 text-body-secondary fw-normal">Role</dt>
                    <dd class="col-8"><span class="badge text-bg-light border"><?= e($user['role_name']) ?></span></dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-7">
        <div class="card jt-card">
            <div class="card-header bg-transparent fw-semibold d-flex align-items-center gap-2"><?= icon('lock') ?> Change password</div>
            <div class="card-body">
                <?php if ((int) $user['must_change_password'] === 1): ?>
                    <div class="alert alert-warning">You must choose a new password before you can continue.</div>
                <?php endif; ?>
                <form method="post" action="<?= e(url('/account/password')) ?>" novalidate data-loading>
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label for="current_password" class="form-label">Current password</label>
                        <input type="password" class="form-control<?= has_error('current_password') ? ' is-invalid' : '' ?>"
                               id="current_password" name="current_password" autocomplete="current-password" required>
                        <?= error_text('current_password') ?>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">New password</label>
                        <input type="password" class="form-control<?= has_error('password') ? ' is-invalid' : '' ?>"
                               id="password" name="password" autocomplete="new-password" minlength="10" required>
                        <div class="form-text">At least 10 characters. A long phrase works well.</div>
                        <?= error_text('password') ?>
                    </div>
                    <div class="mb-4">
                        <label for="password_confirmation" class="form-label">Confirm new password</label>
                        <input type="password" class="form-control" id="password_confirmation" name="password_confirmation"
                               autocomplete="new-password" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Update password</button>
                </form>
            </div>
        </div>
    </div>
</div>
