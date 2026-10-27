<?php
/** @var array<int,array<string,mixed>> $foremen */
$showMoney = $foremen !== [] && array_key_exists('total_expenses', $foremen[0]);
?>
<div class="jt-page-actions">
    <p class="text-body-secondary mb-0 me-auto"><?= e(count($foremen)) ?> foreman<?= count($foremen) === 1 ? '' : 'men' ?>. A foreman is deactivated, never deleted, so old trips keep the name.</p>
    <?php if (can('trips.view')): ?><a class="btn btn-outline-secondary" href="<?= e(url('/trips')) ?>"><?= icon('truck') ?> Trips</a><?php endif; ?>
    <a class="btn btn-primary" href="<?= e(url('/foremen/create')) ?>"><?= icon('plus') ?> New foreman</a>
</div>

<?php if ($foremen === []): ?>
    <div class="card jt-card">
        <?= \App\Core\View::partial('partials/empty-state', [
            'emptyIcon' => 'users', 'heading' => 'No foremen yet', 'message' => 'Add the foremen who pick up jobs, then record their trips.',
            'actionUrl' => '/foremen/create', 'actionLabel' => 'New foreman', 'actionIcon' => 'plus',
        ]) ?>
    </div>
<?php else: ?>
    <div class="card jt-card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 jt-table table-cards">
                <thead>
                <tr>
                    <th scope="col">Name</th><th scope="col">Phone</th><th scope="col" class="text-md-end">Trips</th>
                    <?php if ($showMoney): ?><th scope="col" class="text-md-end">Total expenses</th><?php endif; ?>
                    <th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($foremen as $f): ?>
                    <tr>
                        <td data-label="Name"><?= e($f['name']) ?></td>
                        <td data-label="Phone"><?= tel_link($f['phone']) ?></td>
                        <td data-label="Trips" class="text-md-end"><?= e($f['trip_count']) ?></td>
                        <?php if ($showMoney): ?><td data-label="Total expenses" class="text-md-end jt-money"><?= e(fmt_money($f['total_expenses'])) ?></td><?php endif; ?>
                        <td data-label="Status"><?= (int) $f['active'] === 1 ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>' ?></td>
                        <td class="text-md-end">
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/foremen/' . $f['id'] . '/edit')) ?>">Edit</a>
                            <?php if (can('trips.view') && (int) $f['trip_count'] > 0): ?>
                                <a class="btn btn-sm btn-outline-secondary" href="<?= e(list_url('/trips', ['foreman' => $f['id']])) ?>">Trips</a>
                            <?php endif; ?>
                            <form class="d-inline" method="post" action="<?= e(url('/foremen/' . $f['id'] . '/toggle')) ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-<?= (int) $f['active'] === 1 ? 'danger' : 'success' ?>"<?= (int) $f['active'] === 1 ? ' data-confirm="' . e('Deactivate ' . $f['name'] . '? Their trips are kept.') . '"' : '' ?>><?= (int) $f['active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
