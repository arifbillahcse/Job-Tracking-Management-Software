<?php $user = auth_user(); ?>
<div class="card jt-card mb-4">
    <div class="card-body p-4">
        <h2 class="h4 mb-1">Welcome, <?= e($user['name']) ?></h2>
        <p class="text-body-secondary mb-3">You are signed in as <span class="badge text-bg-light border"><?= e($user['role_name']) ?></span>
            <?php if (!empty($user['last_login_at'])): ?>
                &middot; previous sign-in <?= e($user['last_login_at']) ?> UTC
            <?php endif; ?>
        </p>
        <p class="mb-0">Your job sections and dashboard figures will appear here as they are built.</p>
    </div>
</div>

<div class="card jt-card">
    <div class="card-header bg-transparent fw-semibold d-flex align-items-center gap-2"><?= icon('shield') ?> Your permissions</div>
    <div class="card-body">
        <?php if ($permissions === []): ?>
            <p class="text-body-secondary mb-0">Your role has no permissions assigned.</p>
        <?php else: ?>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($permissions as $permission): ?>
                    <span class="badge text-bg-light border fw-normal"><?= e($permission) ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
