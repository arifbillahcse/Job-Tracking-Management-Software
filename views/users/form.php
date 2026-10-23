<?php
/**
 * Create / edit a user. On edit, the right column holds the account actions.
 * @var array<string,mixed>|null $user  @var array<int,array<string,mixed>> $roles  @var bool $isSelf
 */
$editing = $user !== null;
$val = static fn (string $key): string => old_or($key, $user[$key] ?? '');
$locked = $editing && !empty($user['locked_until']) && (string) $user['locked_until'] > gmdate('Y-m-d H:i:s');
?>
<div class="row g-4">
    <div class="col-12 col-xl-7">
        <form class="card jt-card" method="post" action="<?= e(url($editing ? '/users/' . $user['id'] : '/users')) ?>" novalidate data-loading>
            <?= csrf_field() ?>
            <div class="card-header bg-transparent fw-semibold"><?= $editing ? 'Account details' : 'New user' ?></div>
            <div class="card-body">
                <div class="mb-3">
                    <label for="name" class="form-label">Name <span class="text-danger" aria-hidden="true">*</span></label>
                    <input type="text" class="form-control<?= has_error('name') ? ' is-invalid' : '' ?>" id="name" name="name" value="<?= e($val('name')) ?>" maxlength="120" required autocomplete="off">
                    <?= error_text('name') ?>
                </div>
                <div class="mb-3">
                    <label for="email" class="form-label">Email <span class="text-danger" aria-hidden="true">*</span></label>
                    <input type="email" class="form-control<?= has_error('email') ? ' is-invalid' : '' ?>" id="email" name="email" value="<?= e($val('email')) ?>" maxlength="190" required autocomplete="off">
                    <div class="form-text">Used to sign in.</div>
                    <?= error_text('email') ?>
                </div>
                <div class="mb-3">
                    <label for="role_id" class="form-label">Role <span class="text-danger" aria-hidden="true">*</span></label>
                    <?php $currentRole = (int) old_or('role_id', $user['role_id'] ?? 0); ?>
                    <select class="form-select<?= has_error('role_id') ? ' is-invalid' : '' ?>" id="role_id" name="role_id" required<?= $isSelf ? ' disabled' : '' ?>>
                        <?php if (!$editing): ?><option value="">Choose a role…</option><?php endif; ?>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= e($role['id']) ?>"<?= $currentRole === (int) $role['id'] ? ' selected' : '' ?>><?= e($role['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($isSelf): ?>
                        <input type="hidden" name="role_id" value="<?= e($user['role_id']) ?>">
                        <div class="form-text">You cannot change your own role.</div>
                    <?php else: ?>
                        <div class="form-text">You can only assign roles whose permissions you hold yourself.</div>
                    <?php endif; ?>
                    <?= error_text('role_id') ?>
                </div>
                <?php if (!$editing): ?>
                    <div class="alert alert-info d-flex gap-2 mb-0"><?= icon('info', 'mt-1 flex-shrink-0') ?>
                        <div>A temporary password is generated and shown once after the account is created. The user must choose a new password at first sign-in.</div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="card-footer bg-transparent d-flex gap-2 justify-content-end">
                <a class="btn btn-outline-secondary" href="<?= e(url('/users')) ?>">Cancel</a>
                <button type="submit" class="btn btn-primary"><?= $editing ? 'Save changes' : 'Create user' ?></button>
            </div>
        </form>
    </div>

    <?php if ($editing): ?>
        <div class="col-12 col-xl-5">
            <div class="card jt-card mb-4">
                <div class="card-header bg-transparent fw-semibold">Account status</div>
                <div class="card-body">
                    <dl class="row mb-0 gy-2">
                        <dt class="col-5 text-body-secondary fw-normal">Status</dt>
                        <dd class="col-7">
                            <?= (int) $user['is_active'] === 1 ? '<span class="jt-badge jt-badge-transit">Active</span>' : '<span class="jt-badge jt-badge-neutral">Inactive</span>' ?>
                            <?= $locked ? '<span class="jt-badge jt-badge-later">Locked</span>' : '' ?>
                        </dd>
                        <dt class="col-5 text-body-secondary fw-normal">Password</dt>
                        <dd class="col-7"><?= (int) $user['must_change_password'] === 1 ? 'Must be changed at next sign-in' : 'Set by the user' ?></dd>
                        <dt class="col-5 text-body-secondary fw-normal">Last sign-in</dt>
                        <dd class="col-7"><?= e(fmt_datetime($user['last_login_at']) ?: 'Never') ?></dd>
                        <dt class="col-5 text-body-secondary fw-normal">Failed attempts</dt>
                        <dd class="col-7"><?= e($user['failed_attempts']) ?><?= $locked ? ' &middot; locked until ' . e(fmt_datetime($user['locked_until'])) : '' ?></dd>
                        <dt class="col-5 text-body-secondary fw-normal">Created</dt>
                        <dd class="col-7"><?= e(fmt_datetime($user['created_at'])) ?></dd>
                    </dl>
                </div>
            </div>

            <div class="card jt-card">
                <div class="card-header bg-transparent fw-semibold">Actions</div>
                <div class="card-body d-grid gap-2">
                    <?php if ($isSelf): ?>
                        <p class="small text-body-secondary mb-0">This is your own account. Change your password from <a href="<?= e(url('/account')) ?>">My Account</a>. Another administrator is needed to deactivate or delete you.</p>
                    <?php else: ?>
                        <?php if ($locked): ?>
                            <form method="post" action="<?= e(url('/users/' . $user['id'] . '/unlock')) ?>" data-loading><?= csrf_field() ?>
                                <button type="submit" class="btn btn-outline-primary w-100"><?= icon('unlock') ?> Unlock account</button>
                            </form>
                        <?php endif; ?>
                        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#resetModal"><?= icon('key') ?> Reset password</button>
                        <?php if ((int) $user['is_active'] === 1): ?>
                            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#deactivateModal"><?= icon('x') ?> Deactivate</button>
                        <?php else: ?>
                            <form method="post" action="<?= e(url('/users/' . $user['id'] . '/activate')) ?>" data-loading><?= csrf_field() ?>
                                <button type="submit" class="btn btn-outline-primary w-100"><?= icon('check-circle') ?> Activate</button>
                            </form>
                        <?php endif; ?>
                        <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteUserModal"><?= icon('trash') ?> Delete user</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if ($editing && !$isSelf): ?>
    <?= \App\Core\View::partial('partials/confirm-modal', [
        'modalId' => 'resetModal', 'heading' => 'Reset password for ' . $user['name'] . '?',
        'message' => 'A new temporary password is generated and shown once. The user must change it at next sign-in, and any session they have open is ended immediately.',
        'action' => '/users/' . $user['id'] . '/reset-password', 'confirmLabel' => 'Reset password',
    ]) ?>
    <?= \App\Core\View::partial('partials/confirm-modal', [
        'modalId' => 'deactivateModal', 'heading' => 'Deactivate ' . $user['name'] . '?',
        'message' => 'They are signed out at once and cannot sign in until you activate the account again. Their jobs and history are kept.',
        'action' => '/users/' . $user['id'] . '/deactivate', 'confirmLabel' => 'Deactivate',
    ]) ?>
    <?= \App\Core\View::partial('partials/confirm-modal', [
        'modalId' => 'deleteUserModal', 'heading' => 'Delete ' . $user['name'] . '?',
        'message' => 'The account is removed from the user list and cannot sign in. Their jobs and history are kept, and their email address stays reserved.',
        'action' => '/users/' . $user['id'] . '/delete', 'confirmLabel' => 'Delete user',
    ]) ?>
<?php endif; ?>
