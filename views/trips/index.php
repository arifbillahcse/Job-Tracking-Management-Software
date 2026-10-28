<?php
/**
 * @var array<int,array<string,mixed>> $trips  @var \App\Services\TripCriteria $criteria  @var \App\Helpers\Paginator $paginator
 * @var array<int,array<string,mixed>> $foremen  @var bool $showMoney
 */
?>
<div class="jt-page-actions">
    <p class="text-body-secondary mb-0 me-auto"><?= e($paginator->total) ?> trip<?= $paginator->total === 1 ? '' : 's' ?><?= $criteria->hasFilters() ? ' (filtered)' : '' ?></p>
    <a class="btn btn-outline-secondary" href="<?= e(list_url('/trips/report', ['foreman' => $criteria->foremanId > 0 ? $criteria->foremanId : '', 'from' => $criteria->from, 'to' => $criteria->to])) ?>"><?= icon('activity') ?> By foreman</a>
    <?php if (can('trips.export') && $trips !== []): ?><a class="btn btn-outline-secondary" href="<?= e(list_url('/trips/export', $criteria->linkParams())) ?>"><?= icon('download') ?> Export CSV</a><?php endif; ?>
    <?php if (can('foremen.manage')): ?><a class="btn btn-outline-secondary" href="<?= e(url('/foremen')) ?>"><?= icon('users') ?> Foremen</a><?php endif; ?>
    <?php if (can('trips.manage')): ?><a class="btn btn-primary" href="<?= e(url('/trips/create')) ?>"><?= icon('plus') ?> New trip</a><?php endif; ?>
</div>

<form class="card jt-card mb-3" method="get" action="<?= e(url('/trips')) ?>" role="search" aria-label="Search and filter trips">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label" for="t-q">Search</label>
                <input type="search" class="form-control" id="t-q" name="q" value="<?= e($criteria->q) ?>" maxlength="100" placeholder="Trip #, foreman, collected by, job # or customer">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="t-foreman">Foreman</label>
                <select class="form-select" id="t-foreman" name="foreman">
                    <option value="">Any foreman</option>
                    <?php foreach ($foremen as $f): ?>
                        <option value="<?= e($f['id']) ?>"<?= $criteria->foremanId === (int) $f['id'] ? ' selected' : '' ?>><?= e($f['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2"><label class="form-label" for="t-from">Begins from</label><input type="date" class="form-control" id="t-from" name="from" value="<?= e($criteria->from) ?>"></div>
            <div class="col-6 col-md-2"><label class="form-label" for="t-to">Begins to</label><input type="date" class="form-control" id="t-to" name="to" value="<?= e($criteria->to) ?>"></div>
            <div class="col-12 col-md-1 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1">Go</button>
                <?php if ($criteria->hasFilters()): ?><a class="btn btn-outline-secondary" href="<?= e(url('/trips')) ?>" aria-label="Reset filters"><?= icon('x') ?></a><?php endif; ?>
            </div>
        </div>
    </div>
</form>

<?php if ($trips === []): ?>
    <div class="card jt-card">
        <?= \App\Core\View::partial('partials/empty-state', [
            'emptyIcon' => 'truck',
            'heading' => $criteria->hasFilters() ? 'No trips match your search' : 'No trips yet',
            'message' => $criteria->hasFilters() ? 'Try different keywords or clear the filters.' : 'Record a foreman\'s trip with the jobs he picked up and what it cost.',
            'actionUrl' => $criteria->hasFilters() ? '/trips' : (can('trips.manage') ? '/trips/create' : '/trips'),
            'actionLabel' => $criteria->hasFilters() ? 'Clear filters' : (can('trips.manage') ? 'New trip' : 'Refresh'),
            'actionIcon' => $criteria->hasFilters() ? 'x' : 'plus',
        ]) ?>
    </div>
<?php else: ?>
    <div class="card jt-card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 jt-table table-cards">
                <thead>
                <tr>
                    <th scope="col">Trip #</th><th scope="col">Foreman</th><th scope="col">Begins</th><th scope="col">Ends</th><th scope="col">Collected by</th>
                    <th scope="col" class="text-md-end">Jobs</th>
                    <?php if ($showMoney): ?><th scope="col" class="text-md-end">Total expenses</th><?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($trips as $t): ?>
                    <tr>
                        <td data-label="Trip #"><a class="jt-jobno" href="<?= e(url('/trips/' . $t['id'])) ?>"><?= e($t['trip_number']) ?></a></td>
                        <td data-label="Foreman"><?= e($t['foreman_name']) ?></td>
                        <td data-label="Begins"><?= e(fmt_date($t['begins_on']) ?: '—') ?></td>
                        <td data-label="Ends"><?= e(fmt_date($t['ends_on']) ?: '—') ?></td>
                        <td data-label="Collected by"><?= e($t['collected_by'] ?: '—') ?></td>
                        <td data-label="Jobs" class="text-md-end"><?= e($t['job_count']) ?></td>
                        <?php if ($showMoney): ?><td data-label="Total expenses" class="text-md-end jt-money"><?= e(fmt_money($t['total_expenses'])) ?></td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?= \App\Core\View::partial('partials/pagination', ['paginator' => $paginator, 'basePath' => '/trips', 'linkParams' => $criteria->linkParams()]) ?>
<?php endif; ?>
