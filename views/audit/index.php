<?php
/**
 * @var array<int,array<string,mixed>> $entries  @var \App\Services\AuditCriteria $criteria  @var \App\Helpers\Paginator $paginator
 * @var array<int,string> $actions  @var array<int,string> $entities  @var array<int,array<string,mixed>> $actors
 */
$link = static function (array $e): string {
    $label = e($e['entity']) . ($e['entity_id'] !== null ? ' #' . e($e['entity_id']) : '');
    if ($e['entity_id'] === null) {
        return $label;
    }
    $target = match ($e['entity']) {
        'job' => can('jobs.view') ? '/jobs/' . (int) $e['entity_id'] : null,
        'user' => can('users.manage') ? '/users/' . (int) $e['entity_id'] . '/edit' : null,
        'role' => can('roles.manage') ? '/roles/' . (int) $e['entity_id'] . '/edit' : null,
        default => null,
    };
    return $target === null ? $label : '<a href="' . e(url($target)) . '">' . $label . '</a>';
};
?>
<div class="jt-page-actions">
    <p class="text-body-secondary mb-0 me-auto"><?= e($paginator->total) ?> entr<?= $paginator->total === 1 ? 'y' : 'ies' ?><?= $criteria->hasFilters() ? ' (filtered)' : '' ?>. Entries cannot be edited or deleted from the application. Times are shown in <?= e(setting('timezone', 'UTC')) ?>.</p>
</div>

<form class="card jt-card mb-3" method="get" action="<?= e(url('/audit')) ?>" role="search" aria-label="Filter the audit log">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-4 col-xl-3">
                <label class="form-label" for="a-action">Action</label>
                <select class="form-select" id="a-action" name="action">
                    <option value="">Any action</option>
                    <?php foreach ($actions as $action): ?>
                        <option value="<?= e($action) ?>"<?= $criteria->action === $action ? ' selected' : '' ?>><?= e(\App\Services\AuditService::label($action)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <label class="form-label" for="a-entity">Record type</label>
                <select class="form-select" id="a-entity" name="entity">
                    <option value="">Any</option>
                    <?php foreach ($entities as $entity): ?>
                        <option value="<?= e($entity) ?>"<?= $criteria->entity === $entity ? ' selected' : '' ?>><?= e($entity) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <label class="form-label" for="a-user">User</label>
                <select class="form-select" id="a-user" name="user">
                    <option value="">Anyone</option>
                    <?php foreach ($actors as $actor): ?>
                        <option value="<?= e($actor['id']) ?>"<?= $criteria->userId === (int) $actor['id'] ? ' selected' : '' ?>><?= e($actor['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label" for="a-from">From</label>
                <input type="date" class="form-control" id="a-from" name="from" value="<?= e($criteria->from) ?>">
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label" for="a-to">To</label>
                <input type="date" class="form-control" id="a-to" name="to" value="<?= e($criteria->to) ?>">
            </div>
            <div class="col-12 col-md-6 col-xl-1 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1">Filter</button>
                <?php if ($criteria->hasFilters()): ?><a class="btn btn-outline-secondary" href="<?= e(url('/audit')) ?>" aria-label="Reset filters"><?= icon('x') ?></a><?php endif; ?>
            </div>
        </div>
        <?php if ($criteria->entityId > 0): ?>
            <input type="hidden" name="entity_id" value="<?= e($criteria->entityId) ?>">
            <p class="small text-body-secondary mt-2 mb-0">Showing only record #<?= e($criteria->entityId) ?>.</p>
        <?php endif; ?>
    </div>
</form>

<?php if ($entries === []): ?>
    <div class="card jt-card">
        <?= \App\Core\View::partial('partials/empty-state', [
            'emptyIcon' => 'file-text',
            'heading' => $criteria->hasFilters() ? 'No entries match those filters' : 'Nothing recorded yet',
            'message' => $criteria->hasFilters() ? 'Try a wider date range or remove a filter.' : 'Sign-ins, job changes and administration will appear here.',
            'actionUrl' => $criteria->hasFilters() ? '/audit' : null,
            'actionLabel' => 'Clear filters',
            'actionIcon' => 'x',
        ]) ?>
    </div>
<?php else: ?>
    <div class="card jt-card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 jt-table table-cards">
                <thead>
                <tr>
                    <th scope="col">When</th>
                    <th scope="col">Who</th>
                    <th scope="col">Action</th>
                    <th scope="col">Record</th>
                    <th scope="col">IP address</th>
                    <th scope="col"><span class="visually-hidden">Details</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($entries as $entry): ?>
                    <tr>
                        <td data-label="When" class="text-nowrap"><?= e(fmt_datetime($entry['created_at'])) ?></td>
                        <td data-label="Who"><?= $entry['user_name'] !== null ? e($entry['user_name']) : '<span class="text-body-secondary">Not signed in</span>' ?></td>
                        <td data-label="Action"><?= e(\App\Services\AuditService::label((string) $entry['action'])) ?></td>
                        <td data-label="Record"><?= $entry['entity'] === null ? '<span class="text-body-secondary">—</span>' : $link($entry) ?></td>
                        <td data-label="IP address" class="text-body-secondary"><?= e($entry['ip']) ?></td>
                        <td data-label="" class="jt-actions text-md-end">
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/audit/' . (int) $entry['id'])) ?>" aria-label="Details of entry <?= e($entry['id']) ?>"><?= icon('eye') ?> <span class="d-md-none">Details</span></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?= \App\Core\View::partial('partials/pagination', ['paginator' => $paginator, 'basePath' => '/audit', 'linkParams' => $criteria->linkParams()]) ?>
<?php endif; ?>
