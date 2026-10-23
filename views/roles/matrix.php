<?php
/**
 * Role x permission grid, grouped by area.
 * @var array<int,array<string,mixed>> $roles  @var array<string,array<int,array<string,mixed>>> $groups
 * @var array<int,array<int,bool>> $granted  @var string $version
 */
?>
<div class="jt-page-actions">
    <p class="text-body-secondary mb-0 me-auto">Tick what each role can do. Changes apply on each user's next request, with no need to sign out and in. The built-in Admin role always has everything.</p>
    <a class="btn btn-outline-secondary" href="<?= e(url('/roles')) ?>">Back to roles</a>
</div>

<form method="post" action="<?= e(url('/roles/matrix')) ?>" data-loading>
    <?= csrf_field() ?>
    <input type="hidden" name="version" value="<?= e($version) ?>">

    <div class="card jt-card">
        <div class="table-responsive jt-matrix-wrap">
            <table class="table jt-matrix mb-0">
                <thead>
                <tr>
                    <th scope="col" class="jt-matrix-perm">Permission</th>
                    <?php foreach ($roles as $role): ?>
                        <th scope="col" class="text-center">
                            <?= e($role['name']) ?>
                            <?= (int) $role['is_system'] === 1 ? '<div class="jt-subtle fw-normal">built in</div>' : '' ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <?php foreach ($groups as $group => $permissions): ?>
                    <tbody>
                    <tr class="jt-matrix-group"><th colspan="<?= e(count($roles) + 1) ?>" scope="colgroup"><?= e($group) ?></th></tr>
                    <?php foreach ($permissions as $permission): ?>
                        <tr>
                            <th scope="row" class="jt-matrix-perm fw-normal">
                                <div><?= e($permission['description'] ?: $permission['name']) ?></div>
                                <code class="jt-subtle"><?= e($permission['name']) ?></code>
                            </th>
                            <?php foreach ($roles as $role):
                                $system = (int) $role['is_system'] === 1;
                                $checked = $system || isset($granted[(int) $role['id']][(int) $permission['id']]);
                                $locked = $system || !$permission['can_edit'];
                                $inputId = 'p-' . $role['id'] . '-' . $permission['id'];
                                ?>
                                <td class="text-center">
                                    <input class="form-check-input" type="checkbox" id="<?= e($inputId) ?>"
                                           name="perm[<?= e($role['id']) ?>][]" value="<?= e($permission['id']) ?>"
                                           <?= $checked ? 'checked' : '' ?> <?= $locked ? 'disabled' : '' ?>
                                           aria-label="<?= e($role['name'] . ': ' . ($permission['description'] ?: $permission['name'])) ?>"
                                           <?= !$system && !$permission['can_edit'] ? 'title="You can only change permissions you hold yourself"' : '' ?>>
                                    <?php if ($locked && !$system && $checked): ?>
                                        <input type="hidden" name="perm[<?= e($role['id']) ?>][]" value="<?= e($permission['id']) ?>">
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                <?php endforeach; ?>
            </table>
        </div>
        <div class="card-footer bg-transparent d-flex flex-wrap gap-2 align-items-center">
            <span class="jt-subtle me-auto d-lg-none">Scroll sideways to see every role.</span>
            <button type="submit" class="btn btn-primary">Save permissions</button>
        </div>
    </div>
</form>
