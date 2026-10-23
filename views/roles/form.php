<?php
/** @var array<string,mixed>|null $role  @var array<int,array<string,mixed>> $copyable */
$editing = $role !== null;
$system = $editing && (int) $role['is_system'] === 1;
$val = static fn (string $key): string => old_or($key, $role[$key] ?? '');
?>
<div class="row">
    <div class="col-12 col-xl-7">
        <form class="card jt-card" method="post" action="<?= e(url($editing ? '/roles/' . $role['id'] : '/roles')) ?>" novalidate data-loading>
            <?= csrf_field() ?>
            <div class="card-header bg-transparent fw-semibold"><?= $editing ? 'Role details' : 'New role' ?></div>
            <div class="card-body">
                <div class="mb-3">
                    <label for="name" class="form-label">Role name <span class="text-danger" aria-hidden="true">*</span></label>
                    <input type="text" class="form-control<?= has_error('name') ? ' is-invalid' : '' ?>" id="name" name="name" value="<?= e($val('name')) ?>" maxlength="60" required<?= $system ? ' readonly' : '' ?>>
                    <?php if ($system): ?><div class="form-text">Built-in roles cannot be renamed. The built-in Admin role always holds every permission.</div><?php endif; ?>
                    <?= error_text('name') ?>
                </div>
                <div class="mb-3">
                    <label for="description" class="form-label">Description</label>
                    <input type="text" class="form-control<?= has_error('description') ? ' is-invalid' : '' ?>" id="description" name="description" value="<?= e($val('description')) ?>" maxlength="255">
                    <?= error_text('description') ?>
                </div>
                <?php if (!$editing): ?>
                    <div class="mb-0">
                        <label for="copy_from" class="form-label">Start from</label>
                        <select class="form-select" id="copy_from" name="copy_from">
                            <option value="">Nothing (no permissions)</option>
                            <?php foreach ($copyable as $source): ?>
                                <option value="<?= e($source['id']) ?>"<?= old_or('copy_from', '') === (string) $source['id'] ? ' selected' : '' ?>>Copy of <?= e($source['name']) ?> (<?= e($source['permission_count']) ?> permissions)</option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Only permissions you hold yourself are copied. Adjust the rest in the permission matrix.</div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="card-footer bg-transparent d-flex gap-2 justify-content-end">
                <a class="btn btn-outline-secondary" href="<?= e(url('/roles')) ?>">Cancel</a>
                <button type="submit" class="btn btn-primary"><?= $editing ? 'Save changes' : 'Create role' ?></button>
            </div>
        </form>
    </div>
</div>
