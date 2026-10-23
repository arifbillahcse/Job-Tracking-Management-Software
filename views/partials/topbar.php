<?php $user = auth_user(); ?>
<header class="jt-topbar">
    <button class="btn btn-icon d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-controls="sidebar" aria-label="Open menu">
        <?= icon('menu') ?>
    </button>
    <h1 class="jt-page-title"><?= e($title ?? '') ?></h1>
    <div class="ms-auto dropdown">
        <button class="btn jt-user-btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <span class="jt-avatar" aria-hidden="true"><?= e(strtoupper(mb_substr((string) ($user['name'] ?? '?'), 0, 1))) ?></span>
            <span class="d-none d-sm-inline"><?= e($user['name'] ?? '') ?></span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
            <li class="px-3 py-2 small text-body-secondary">
                <?= e($user['email'] ?? '') ?><br>
                <span class="badge text-bg-light border"><?= e($user['role_name'] ?? '') ?></span>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item d-flex align-items-center gap-2" href="<?= e(url('/account')) ?>"><?= icon('user') ?> My account</a></li>
            <li>
                <form method="post" action="<?= e(url('/logout')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="dropdown-item d-flex align-items-center gap-2"><?= icon('log-out') ?> Sign out</button>
                </form>
            </li>
        </ul>
    </div>
</header>
