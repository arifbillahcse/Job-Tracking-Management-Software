<?php /** @var array<int,array<string,mixed>> $roles */ ?>
<div class="jt-page-actions">
    <p class="text-body-secondary mb-0 me-auto">Roles group permissions. Choose what each role can do in the permission matrix.</p>
    <?php if (can('users.manage')): ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('/users')) ?>"><?= icon('users') ?> Users</a>
    <?php endif; ?>
    <a class="btn btn-outline-primary" href="<?= e(url('/roles/matrix')) ?>"><?= icon('sliders') ?> Permission matrix</a>
    <a class="btn btn-primary" href="<?= e(url('/roles/create')) ?>"><?= icon('plus') ?> New role</a>
</div>

<div class="card jt-card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 jt-table table-cards">
            <thead>
            <tr>
                <th scope="col">Role</th>
                <th scope="col">Description</th>
                <th scope="col" class="text-md-end">Users</th>
                <th scope="col" class="text-md-end">Permissions</th>
                <th scope="col"><span class="visually-hidden">Actions</span></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($roles as $role): ?>
                <tr>
                    <td data-label="Role">
                        <span class="jt-jobno"><?= e($role['name']) ?></span>
                        <?= (int) $role['is_system'] === 1 ? ' <span class="badge text-bg-light border fw-normal">built in</span>' : '' ?>
                    </td>
                    <td data-label="Description" class="text-body-secondary"><?= e($role['description'] ?: '—') ?></td>
                    <td data-label="Users" class="text-md-end">
                        <?php if (can('users.manage') && (int) $role['user_count'] > 0): ?>
                            <a href="<?= e(list_url('/users', ['role' => $role['id']])) ?>"><?= e($role['user_count']) ?></a>
                        <?php else: ?>
                            <?= e($role['user_count']) ?>
                        <?php endif; ?>
                    </td>
                    <td data-label="Permissions" class="text-md-end"><?= e($role['permission_count']) ?></td>
                    <td data-label="" class="jt-actions text-md-end">
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/roles/' . $role['id'] . '/edit')) ?>" aria-label="Edit role <?= e($role['name']) ?>"><?= icon('edit') ?> <span class="d-md-none">Edit</span></a>
                        <?php if ((int) $role['is_system'] === 0): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteRole<?= e($role['id']) ?>" aria-label="Delete role <?= e($role['name']) ?>"><?= icon('trash') ?> <span class="d-md-none">Delete</span></button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php foreach ($roles as $role): if ((int) $role['is_system'] === 1) { continue; } ?>
    <?= \App\Core\View::partial('partials/confirm-modal', [
        'modalId' => 'deleteRole' . $role['id'],
        'heading' => 'Delete the ' . $role['name'] . ' role?',
        'message' => (int) $role['user_count_all'] > 0
            ? 'This role still has ' . $role['user_count_all'] . ' user(s) and cannot be deleted until they are moved to another role.'
            : 'The role and its permissions are removed. This cannot be undone.',
        'action' => '/roles/' . $role['id'] . '/delete',
        'confirmLabel' => 'Delete role',
    ]) ?>
<?php endforeach; ?>
