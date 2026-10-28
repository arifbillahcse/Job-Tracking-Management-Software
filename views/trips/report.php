<?php
/**
 * @var array{rows:array<int,array<string,mixed>>,totals:array<string,string|int>,money:bool} $report
 * @var \App\Services\TripCriteria $criteria  @var array<int,array<string,mixed>> $foremen
 */
$money = $report['money'];
$cols = ['diesel' => 'Diesel', 'hotels' => 'Hotels', 'helper' => 'Helper', 'misc' => 'Misc', 'commission' => 'Commission', 'extra' => 'Extra'];
$params = ['foreman' => $criteria->foremanId > 0 ? $criteria->foremanId : '', 'from' => $criteria->from, 'to' => $criteria->to];
?>
<div class="jt-page-actions">
    <a class="btn btn-outline-secondary" href="<?= e(url('/trips')) ?>"><?= icon('arrow-left') ?> All trips</a>
    <span class="me-auto"></span>
    <?php if (can('trips.export') && $report['rows'] !== []): ?><a class="btn btn-outline-secondary" href="<?= e(list_url('/trips/report/export', $params)) ?>"><?= icon('download') ?> Export CSV</a><?php endif; ?>
</div>

<form class="card jt-card mb-3" method="get" action="<?= e(url('/trips/report')) ?>" role="search" aria-label="Report period">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label" for="r-foreman">Foreman</label>
                <select class="form-select" id="r-foreman" name="foreman">
                    <option value="">All foremen</option>
                    <?php foreach ($foremen as $f): ?>
                        <option value="<?= e($f['id']) ?>"<?= $criteria->foremanId === (int) $f['id'] ? ' selected' : '' ?>><?= e($f['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3"><label class="form-label" for="r-from">Trips beginning from</label><input type="date" class="form-control" id="r-from" name="from" value="<?= e($criteria->from) ?>"></div>
            <div class="col-6 col-md-3"><label class="form-label" for="r-to">to</label><input type="date" class="form-control" id="r-to" name="to" value="<?= e($criteria->to) ?>"></div>
            <div class="col-12 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1">Show</button>
                <?php if ($criteria->hasFilters()): ?><a class="btn btn-outline-secondary" href="<?= e(url('/trips/report')) ?>" aria-label="Reset"><?= icon('x') ?></a><?php endif; ?>
            </div>
        </div>
        <p class="jt-subtle mb-0 mt-2">One line per foreman for the trips that begin in the period (a trip with no begin date is left out when a period is chosen). Deleted trips are not counted.</p>
    </div>
</form>

<?php if ($report['rows'] === []): ?>
    <div class="card jt-card">
        <?= \App\Core\View::partial('partials/empty-state', [
            'emptyIcon' => 'truck', 'heading' => 'No trips in this period', 'message' => 'Choose a different period or foreman.',
            'actionUrl' => '/trips/report', 'actionLabel' => 'Clear filters', 'actionIcon' => 'x',
        ]) ?>
    </div>
<?php else: ?>
    <div class="card jt-card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 jt-table">
                <thead>
                <tr>
                    <th scope="col">Foreman</th><th scope="col" class="text-end">Trips</th><th scope="col" class="text-end">Jobs</th>
                    <?php if ($money): foreach ($cols as $label): ?><th scope="col" class="text-end"><?= e($label) ?></th><?php endforeach; ?><th scope="col" class="text-end">Total expenses</th><?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($report['rows'] as $r): ?>
                    <tr>
                        <td><a href="<?= e(list_url('/trips', ['foreman' => $r['id'], 'from' => $criteria->from, 'to' => $criteria->to])) ?>"><?= e($r['name']) ?></a><?= (int) $r['active'] === 1 ? '' : ' <span class="badge text-bg-secondary">inactive</span>' ?></td>
                        <td class="text-end"><?= e($r['trips']) ?></td>
                        <td class="text-end"><?= e($r['jobs']) ?></td>
                        <?php if ($money): foreach ($cols as $field => $label): ?><td class="text-end jt-money"><?= e(fmt_money($r[$field])) ?></td><?php endforeach; ?><td class="text-end jt-money fw-semibold"><?= e(fmt_money($r['total'])) ?></td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr class="fw-semibold">
                    <td>Total</td><td class="text-end"><?= e($report['totals']['trips']) ?></td><td class="text-end"><?= e($report['totals']['jobs']) ?></td>
                    <?php if ($money): foreach ($cols as $field => $label): ?><td class="text-end jt-money"><?= e(fmt_money($report['totals'][$field])) ?></td><?php endforeach; ?><td class="text-end jt-money"><?= e(fmt_money($report['totals']['total'])) ?></td><?php endif; ?>
                </tr>
                </tfoot>
            </table>
        </div>
    </div>
<?php endif; ?>
