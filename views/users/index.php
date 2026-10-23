<?php
/**
 * @var array<int,array<string,mixed>> $users  @var \App\Services\UserCriteria $criteria  @var \App\Helpers\Paginator $paginator
 * @var array<int,array<string,mixed>> $roles
 */
$now = gmdate('Y-m-d H:i:s');
$sort = static function (string $label, string $key) use ($criteria): string {
    $active = $criteria->sort === $key;
    $icon = $active ? ($criteria->dir === 'asc' ? 'chevron-up' : 'chevron-down') : 'chevrons';
    return '<a class="jt-sort' . ($active ? ' active' : '') . '" href="' . e(list_url('/users', $criteria->sortParams($key))) . '">' . e($label) . ' ' . icon($icon) . '</a>';
};
?>
<div class="jt-page-actions">
    <p class="text-body-secondary mb-0 me-auto"><?= e($paginator->total) ?> user<?= $paginator->total === 1 ? '' : 's' ?><?= $criteria->hasFilters() ? ' (filtered)' : '' ?></p>
    <?php if (can('roles.manage')): ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('/roles')) ?>"><?= icon('shield') ?> Roles</a>
    <?php endif; ?>
    <a class="btn btn-primary" href="<?= e(url('/users/create')) ?>"><?= icon('plus') ?> New user</a>
</div>

<form class="card jt-card mb-3" method="get" action="<?= e(url('/users')) ?>" role="search" aria-label="Search and filter users">
    <div class="card-body">
        <?php if ($criteria->sort !== 'name' || $criteria->dir !== 'asc'): ?>
            <input type="hidden" name="sort" value="<?= e($criteria->sort) ?>"><input type="hidden" name="dir" value="<?= e($criteria->dir) ?>">
        <?php endif; ?>
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-5">
                <label class="form-label" for="u-q">Search</label>
                <input type="search" class="form-control" id="u-q" name="q" value="<?= e($criteria->q) ?>" maxlength="100" placeholder="Name or email">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label" for="u-role">Role</label>
                <select class="form-select" id="u-role" name="role">
                    <option value="">Any role</option>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= e($role['id']) ?>"<?= $criteria->roleId === (int) $role['id'] ? ' selected' : '' ?>><?= e($role['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label" for="u-status">Status</label>
                <select class="form-select" id="u-status" name="status">
                    <option value="">Any</option>
                    <?php foreach (\App\Services\UserCriteria::STATUSES as $status): ?>
                        <option value="<?= e($status) ?>"<?= $criteria->status === $status ? ' selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1">Search</button>
                <?php if ($criteria->hasFilters()): ?><a class="btn btn-outline-secondary" href="<?= e(url('/users')) ?>" aria-label="Reset filters"><?= icon('x') ?></a><?php endif; ?>
            </div>
        </div>
    </div>
</form>

<?php if ($users === []): ?>
    <div class="card jt-card">
        <?= \App\Core\View::partial('partials/empty-state', [
            'emptyIcon' => 'users',
            'heading' => $criteria->hasFilters() ? 'No users match your search' : 'No users',
            'message' => $criteria->hasFilters() ? 'Try different keywords or clear the filters.' : 'Create the first user.',
            'actionUrl' => $criteria->hasFilters() ? '/users' : '/users/create',
            'actionLabel' => $criteria->hasFilters() ? 'Clear filters' : 'New user',
            'actionIcon' => $criteria->hasFilters() ? 'x' : 'plus',
        ]) ?>
    </div>
<?php else: ?>
    <div class="card jt-card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 jt-table table-cards">
                <thead>
                <tr>
                    <th scope="col"><?= $sort('Name', 'name') ?></th>
                    <th scope="col"><?= $sort('Email', 'email') ?></th>
                    <th scope="col"><?= $sort('Role', 'role') ?></th>
                    <th scope="col">Status</th>
                    <th scope="col"><?= $sort('Last sign-in', 'last_login') ?></th>
                    <th scope="col"><span class="visually-hidden">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $user):
                    $locked = !empty($user['locked_until']) && (string) $user['locked_until'] > $now;
                    ?>
                    <tr>
                        <td data-label="Name">
                            <?php if ($user['manageable']): ?>
                                <a class="jt-jobno" href="<?= e(url('/users/' . $user['id'] . '/edit')) ?>"><?= e($user['name']) ?></a>
                            <?php else: ?>
                                <span class="jt-jobno"><?= e($user['name']) ?></span>
                            <?php endif; ?>
                            <?= $user['self'] ? ' <span class="badge text-bg-light border fw-normal">you</span>' : '' ?>
                        </td>
                        <td data-label="Email" class="text-break"><?= e($user['email']) ?></td>
                        <td data-label="Role"><span class="badge text-bg-light border"><?= e($user['role_name']) ?></span></td>
                        <td data-label="Status">
                            <?= (int) $user['is_active'] === 1 ? '<span class="jt-badge jt-badge-transit">Active</span>' : '<span class="jt-badge jt-badge-neutral">Inactive</span>' ?>
                            <?= $locked ? '<span class="jt-badge jt-badge-later">Locked</span>' : '' ?>
                            <?= (int) $user['must_change_password'] === 1 ? '<span class="jt-badge jt-badge-hold">Must change password</span>' : '' ?>
                        </td>
                        <td data-label="Last sign-in"><?= e(fmt_datetime($user['last_login_at']) ?: 'Never') ?></td>
                        <td data-label="" class="jt-actions text-md-end">
                            <?php if ($user['manageable']): ?>
                                <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/users/' . $user['id'] . '/edit')) ?>" aria-label="Edit <?= e($user['name']) ?>"><?= icon('edit') ?> <span class="d-md-none">Edit</span></a>
                            <?php else: ?>
                                <span class="jt-subtle" title="Its permissions exceed yours">Restricted</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?= \App\Core\View::partial('partials/pagination', ['paginator' => $paginator, 'basePath' => '/users', 'linkParams' => $criteria->linkParams()]) ?>
<?php endif; ?>
