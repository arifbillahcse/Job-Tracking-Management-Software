<?php
/**
 * One template for all five job lists; the section decides the columns.
 * @var string $bucket  @var \App\Services\JobCriteria $criteria  @var array<int,array<string,mixed>> $jobs
 * @var \App\Helpers\Paginator $paginator  @var array<string,int> $tabCounts  @var array<int,array{to:string,label:string}> $shortcuts
 * @var bool $showFinancials  @var array<int,array<string,mixed>> $foremen
 */
use App\Services\JobBucket;

$definition = JobBucket::definitions()[$bucket];
$path = $definition['path'];
$threshold = JobBucket::threshold();

// column key => [heading, sort key (null = not sortable), cell CSS class]
$all = [
    'job' => ['Job #', 'job', ''],
    'customer' => ['Customer', 'customer', ''],
    'brokerlot' => ['Broker / lot / color', [['Broker', 'broker'], ['Lot', 'lot'], ['Color', 'color']], ''],
    'dates' => ['Pickup / 1st available', [['Pickup', 'pickup'], ['1st available', 'available']], ''],
    'routeloc' => ['Route / job location', [['Route', 'from'], ['Job location', 'location']], ''],
    'assign' => ['Foreman / trip', 'assign', ''],
    'cfs' => ['Cubic feet', [['E-CF', 'ecf'], ['A-CF', 'cf']], 'text-md-end'],
    'money1' => ['Estimate / deposit / grand', [['E-Total', 'etotal'], ['Deposit', 'deposit'], ['Grand total', 'grand']], 'text-md-end'],
    'money2' => ['Total to BH / paid', [['Total to BH', 'tobh'], ['Paid', 'paid']], 'text-md-end'],
    'balfee' => ['Balance due / fee', [['Balance due', 'balance'], ['Storage fee', 'storage']], 'text-md-end'],
    'status' => ['Status', 'status', ''],
    'carrierblock' => ['Carrier / driver', 'carrier', ''],
    'dispatched' => ['Loaded', 'dispatched', ''],
    'delivered' => ['Delivered on', 'delivered', ''],
    // building blocks of the stacked columns above (never a column on their own)
    'broker' => ['Broker', 'broker', ''], 'pickup' => ['Pickup', 'pickup', ''], 'available' => ['1st available', 'available', ''], 'route' => ['Route', 'from', ''],
    'lot' => ['Job lot', 'lot', ''], 'color' => ['Color', 'color', ''], 'location' => ['Job location', 'location', ''],
    'ecf' => ['E-CF', 'ecf', ''], 'cf' => ['A-CF', 'cf', ''], 'etotal' => ['E-Total', 'etotal', ''], 'deposit' => ['Deposit', 'deposit', ''], 'grand' => ['Grand total', 'grand', ''],
    'tobh' => ['Total to BH', 'tobh', ''], 'paid' => ['Paid', 'paid', ''], 'balance' => ['Balance due', 'balance', ''], 'storage' => ['Storage fee', 'storage', ''],
];
$core = ['job', 'customer', 'brokerlot'];
$money = ['cfs', 'money1', 'money2', 'balfee'];
$layouts = [
    JobBucket::ALL => [...$core, 'dates', 'routeloc', 'assign', ...$money, 'status'],
    JobBucket::READY => [...$core, 'dates', 'routeloc', 'assign', ...$money],
    JobBucket::LATER => [...$core, 'dates', 'routeloc', 'assign', ...$money],
    JobBucket::TRANSIT => [...$core, 'routeloc', 'assign', ...$money, 'dispatched', 'carrierblock'],
    JobBucket::DELIVERED => [...$core, 'dates', 'routeloc', 'assign', ...$money, 'delivered'],
];
$columns = array_values(array_filter(
    $layouts[$bucket],
    static fn (string $key): bool => $showFinancials || !in_array($key, ['money1', 'money2', 'balfee'], true)
));

$descriptions = [
    JobBucket::ALL => 'Every job, including those on hold or cancelled.',
    JobBucket::READY => JobBucket::basis() === 'pickup'
        ? "Booked jobs picked up within the last {$threshold} days, or not picked up yet. Jobs with no pickup date are counted from their 1st available date."
        : "Booked jobs whose 1st available date is within {$threshold} days of today (including up to {$threshold} days overdue).",
    JobBucket::LATER => JobBucket::basis() === 'pickup'
        ? "Booked jobs picked up more than {$threshold} days ago (the storage fee applies to those). Jobs with no pickup date are counted from their 1st available date."
        : "Booked jobs whose 1st available date is more than {$threshold} days away, or that have already waited more than {$threshold} days past it (the storage fee applies to those). Jobs ahead of time move to Ready for Delivery by themselves as the date approaches.",
    JobBucket::TRANSIT => 'Jobs on the road, with the loaded date, carrier and driver. Use Mark as Delivered when a job arrives.',
    JobBucket::DELIVERED => 'Completed deliveries.',
];

$emptyBucket = [
    JobBucket::ALL => ['briefcase', 'No jobs yet', 'Jobs you create will be listed here.'],
    JobBucket::READY => ['clock', 'Nothing is ready for delivery', "Booked jobs with a 1st available date in the next {$threshold} days will appear here."],
    JobBucket::LATER => ['calendar', 'Nothing more than ' . $threshold . ' days away', 'Booked jobs with a later available date will appear here.'],
    JobBucket::TRANSIT => ['truck', 'No jobs in transit', 'Jobs appear here when you press Move to In Transit.'],
    JobBucket::DELIVERED => ['check-circle', 'No deliveries yet', 'Completed deliveries will appear here.'],
];

$stack = static function (array $lines): string {
    $html = '';
    foreach ($lines as [$label, $value, $muted]) {
        $html .= '<div' . ($muted ? ' class="jt-subtle"' : '') . '>' . ($label !== '' ? '<span class="jt-k">' . $label . '</span> ' : '') . trim($value) . '</div>';
    }
    return '<div class="jt-stack">' . $html . '</div>';
};
$render = function (string $key, array $job) use ($bucket, $threshold, $stack, &$render): string {
    ob_start(); ?>
                                <?php switch ($key):
                                    case 'job': ?>
                                        <a class="jt-jobno" href="<?= e(url('/jobs/' . $job['id'])) ?>"><?= e($job['job_number']) ?></a>
                                        <?php break;
                                    case 'customer': ?>
                                        <div class="jt-stack">
                                            <?= e($job['customer_first_name'] . ' ' . $job['customer_last_name']) ?>
                                            <?php if (!empty($job['customer_phone'])): ?>
                                                <div class="jt-subtle"><?= tel_link($job['customer_phone']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <?php break;
                                    case 'pickup': ?>
                                        <?= e(fmt_date($job['pickup_date']) ?: '—') ?>
                                        <?php if ($bucket === JobBucket::LATER || $bucket === JobBucket::READY): if (JobBucket::usesFallback($job)): ?><div class="jt-subtle">no pickup date: using 1st available</div><?php endif; endif; ?>
                                        <?php break;
                                    case 'available': ?>
                                        <span class="jt-date"><?= e(fmt_date($job['first_available_date'])) ?></span>
                                        <?= availability_chip($job) ?>
                                        <?php if ($bucket === JobBucket::LATER && JobBucket::basis() === 'first_available' && (string) $job['first_available_date'] > JobBucket::cutoff()): ?>
                                            <div class="jt-subtle">Moves to Ready on <?= e(fmt_date(JobBucket::movesToReadyOn((string) $job['first_available_date']))) ?></div>
                                        <?php elseif ($bucket === JobBucket::LATER): ?>
                                            <div class="jt-subtle">Waiting more than <?= e($threshold) ?> days</div>
                                        <?php endif; ?>
                                        <?php break;
                                    case 'route': ?>
                                        <?= e($job['from_location']) ?> <?= icon('arrow-right', 'text-body-secondary') ?> <?= e($job['to_location']) ?>
                                        <?php break;
                                    case 'assign': ?>
                                        <div class="jt-stack">
                                            <?= !empty($job['foreman']) ? e($job['foreman']) : '<span class="text-body-secondary">—</span>' ?>
                                            <?php if (!empty($job['trip_number'])): ?>
                                                <div class="jt-subtle"><?= can('trips.view') ? '<a href="' . e(url('/trips/' . $job['trip_id'])) . '">' . e($job['trip_number']) . '</a>' : e($job['trip_number']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <?php break;
                                    case 'brokerlot': ?>
                                        <?= $stack([['', $render('broker', $job), false], ['Lot', $render('lot', $job), true], ['Color', $render('color', $job), true]]) ?>
                                        <?php break;
                                    case 'dates': ?>
                                        <?= $stack([['Pick', $render('pickup', $job), true], ['', $render('available', $job), false]]) ?>
                                        <?php break;
                                    case 'routeloc': ?>
                                        <?= $stack([['', $render('route', $job), false], ['', $render('location', $job), true]]) ?>
                                        <?php break;
                                    case 'cfs': ?>
                                        <?= $stack([['E', $render('ecf', $job), true], ['A', $render('cf', $job), false]]) ?>
                                        <?php break;
                                    case 'money1': ?>
                                        <?= $stack([['Est', $render('etotal', $job), true], ['Dep', $render('deposit', $job), true], ['Grand', $render('grand', $job), false]]) ?>
                                        <?php break;
                                    case 'money2': ?>
                                        <?= $stack([['BH', $render('tobh', $job), false], ['Paid', $render('paid', $job), true]]) ?>
                                        <?php break;
                                    case 'balfee': ?>
                                        <?= $stack([['', '<strong>' . $render('balance', $job) . '</strong>', false], ['fee', $render('storage', $job), true]]) ?>
                                        <?php break;
                                    case 'broker': ?>
                                        <?= e($job['broker'] ?? '') ?: '<span class="text-body-secondary">—</span>' ?>
                                        <?php break;
                                    case 'lot': ?>
                                        <?= e($job['job_lot'] ?? '') ?: '<span class="text-body-secondary">—</span>' ?>
                                        <?php break;
                                    case 'color': ?>
                                        <?= e($job['color'] ?? '') ?: '<span class="text-body-secondary">—</span>' ?>
                                        <?php break;
                                    case 'location': ?>
                                        <?= e($job['job_location'] ?? '') ?: '<span class="text-body-secondary">—</span>' ?>
                                        <?php break;
                                    case 'ecf': ?>
                                        <?= e(fmt_number($job['estimated_cf'] ?? null) ?: '—') ?>
                                        <?php break;
                                    case 'etotal': ?>
                                        <?= e(fmt_money($job['estimated_total'] ?? null) ?: '—') ?>
                                        <?php break;
                                    case 'deposit': ?>
                                        <?= e(fmt_money($job['deposit'] ?? null) ?: '—') ?>
                                        <?php break;
                                    case 'grand': ?>
                                        <?= e(fmt_money($job['grand_total'] ?? null) ?: '—') ?>
                                        <?php break;
                                    case 'tobh': ?>
                                        <?= e(fmt_money($job['bh_total'] ?? null) ?: '—') ?>
                                        <?php break;
                                    case 'paid': ?>
                                        <?= e(fmt_money($job['paid'] ?? null) ?: '—') ?>
                                        <?php break;
                                    case 'cf': ?>
                                        <?= e(fmt_number($job['cf']) ?: '—') ?>
                                        <?php break;
                                    case 'balance': ?>
                                        <?= e(fmt_money($job['balance'])) ?>
                                        <?php break;
                                    case 'storage': ?>
                                        <?= e(fmt_money($job['storage_fee'])) ?>
                                        <?php break;
                                    case 'status': ?>
                                        <?= status_badge((string) $job['status']) ?>
                                        <?php break;
                                    case 'carrier': ?>
                                        <?= e($job['carrier_name'] ?: '—') ?>
                                        <?php break;
                                    case 'carrierblock': ?>
                                        <div class="jt-stack">
                                            <?php if (empty($job['carrier_name']) && empty($job['driver_name']) && empty($job['driver_phone'])): ?>
                                                <span class="text-body-secondary">—</span>
                                            <?php else: ?>
                                                <?= e($job['carrier_name'] ?: '—') ?>
                                                <?php if (!empty($job['driver_name'])): ?><div class="jt-subtle"><?= e($job['driver_name']) ?></div><?php endif; ?>
                                                <?php if (!empty($job['driver_phone'])): ?><div class="jt-subtle"><?= tel_link($job['driver_phone']) ?></div><?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php break;
                                    case 'driver': ?>
                                        <?= e($job['driver_name'] ?: '—') ?>
                                        <?php break;
                                    case 'phone': ?>
                                        <?= tel_link($job['driver_phone']) ?>
                                        <?php break;
                                    case 'dispatched': ?>
                                        <?= e(fmt_date($job['loaded_date'] ? (string) $job['loaded_date'] : null) ?: '—') ?>
                                        <?php break;
                                    case 'delivered': ?>
                                        <?= e(fmt_date($job['delivery_date'] ? (string) $job['delivery_date'] : null) ?: '—') ?>
                                        <?php break;
                                endswitch; ?>
<?php return (string) ob_get_clean();
};

$sortHeader = static function (string $label, ?string $sortKey) use ($criteria, $path): string {
    if ($sortKey === null) {
        return e($label);
    }
    $active = $criteria->sort === $sortKey;
    $icon = $active ? ($criteria->dir === 'asc' ? 'chevron-up' : 'chevron-down') : 'chevrons';
    return '<a class="jt-sort' . ($active ? ' active' : '') . '" href="' . e(list_url($path, $criteria->sortParams($sortKey))) . '">'
        . e($label) . ' ' . icon($icon) . '</a>';
};
?>
<?= \App\Core\View::partial('jobs/_tabs', ['bucket' => $bucket, 'tabCounts' => $tabCounts]) ?>

<div class="jt-page-actions">
    <p class="text-body-secondary mb-0 me-auto jt-section-note"><?= e($descriptions[$bucket]) ?></p>
    <?php if (can('jobs.delete') && $bucket === JobBucket::ALL): ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('/jobs/trash')) ?>"><?= icon('trash') ?> Trash</a>
    <?php endif; ?>
    <?php if (can('jobs.import') && $bucket === JobBucket::ALL): ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('/jobs/import')) ?>"><?= icon('upload') ?> Import</a>
    <?php endif; ?>
    <?php if (can('jobs.export') && $paginator->total > 0): ?>
        <div class="dropdown">
            <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><?= icon('download') ?> Export</button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                <li><span class="dropdown-item-text small text-body-secondary">Exports this list<?= $criteria->hasFilters() ? ' with your filters' : '' ?>, all pages.</span></li>
                <li><a class="dropdown-item" href="<?= e(list_url('/jobs/export/' . $bucket, $criteria->linkParams() + ['format' => 'csv'])) ?>">CSV (opens in Excel)</a></li>
                <?php if (\App\Services\Import\XlsxReader::available()): ?>
                    <li><a class="dropdown-item" href="<?= e(list_url('/jobs/export/' . $bucket, $criteria->linkParams() + ['format' => 'xlsx'])) ?>">Excel (.xlsx)</a></li>
                <?php endif; ?>
            </ul>
        </div>
    <?php endif; ?>
    <?php if (can('jobs.create')): ?>
        <a class="btn btn-primary" href="<?= e(url('/jobs/create')) ?>"><?= icon('plus') ?> New job</a>
    <?php endif; ?>
</div>

<?= \App\Core\View::partial('jobs/_filters', ['bucket' => $bucket, 'criteria' => $criteria, 'showFinancials' => $showFinancials, 'foremen' => $foremen, 'brokers' => $brokers ?? []]) ?>

<div class="small text-body-secondary mb-2" aria-live="polite">
    <?php if ($paginator->total === 0): ?>
        No jobs
    <?php else: ?>
        Showing <?= e($paginator->from()) ?>&ndash;<?= e($paginator->to()) ?> of <?= e($paginator->total) ?> job<?= $paginator->total === 1 ? '' : 's' ?>
    <?php endif; ?>
    <?= $criteria->hasFilters() ? '(filtered)' : '' ?>
</div>

<?php if ($jobs === []): ?>
    <div class="card jt-card">
        <?php if ($criteria->hasFilters()): ?>
            <?= \App\Core\View::partial('partials/empty-state', [
                'emptyIcon' => 'search',
                'heading' => 'No jobs match your search',
                'message' => 'Try different keywords or remove some filters.',
                'actionUrl' => $path,
                'actionLabel' => 'Clear search and filters',
                'actionIcon' => 'x',
            ]) ?>
        <?php else: ?>
            <?= \App\Core\View::partial('partials/empty-state', [
                'emptyIcon' => $emptyBucket[$bucket][0],
                'heading' => $emptyBucket[$bucket][1],
                'message' => $emptyBucket[$bucket][2],
                'actionUrl' => $bucket === JobBucket::ALL && can('jobs.create') ? '/jobs/create' : null,
                'actionLabel' => 'Create the first job',
            ]) ?>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="card jt-card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 jt-table jt-compact table-cards">
                <thead>
                <tr>
                    <?php foreach ($columns as $key): [$label, $sortKey, $class] = $all[$key]; ?>
                        <?php $keys = is_array($sortKey) ? array_column($sortKey, 1) : [$sortKey]; ?>
                        <th scope="col" class="<?= e($class) ?>"<?= in_array($criteria->sort, $keys, true) ? ' aria-sort="' . ($criteria->dir === 'asc' ? 'ascending' : 'descending') . '"' : '' ?>><?php if (is_array($sortKey)): ?><?= implode('<span class="jt-sort-sep"> / </span>', array_map(static fn (array $part): string => $sortHeader($part[0], $part[1]), $sortKey)) ?><?php else: ?><?= $sortHeader($label, $sortKey) ?><?php endif; ?></th>
                    <?php endforeach; ?>
                    <th scope="col"><span class="visually-hidden">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($jobs as $job): ?>
                    <tr>
                        <?php foreach ($columns as $key): [$label, , $class] = $all[$key]; ?>
                            <td data-label="<?= e($label) ?>" class="<?= e($class) ?>">
                                <?= $render($key, $job) ?>
                            </td>
                        <?php endforeach; ?>
                        <td data-label="" class="jt-actions text-md-end">
                            <?php if (isset($shortcuts[(int) $job['id']])): $shortcut = $shortcuts[(int) $job['id']]; ?>
                                <?= \App\Core\View::partial('jobs/_quick_move', ['job' => $job, 't' => $shortcut, 'size' => 'btn-sm']) ?>
                            <?php endif; ?>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/jobs/' . $job['id'])) ?>" aria-label="View job <?= e($job['job_number']) ?>"><?= icon('eye') ?> <span class="d-md-none">View</span></a>
                            <?php if (can('jobs.edit')): ?>
                                <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/jobs/' . $job['id'] . '/edit')) ?>" aria-label="Edit job <?= e($job['job_number']) ?>"><?= icon('edit') ?> <span class="d-md-none">Edit</span></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?= \App\Core\View::partial('partials/pagination', ['paginator' => $paginator, 'basePath' => $path, 'linkParams' => $criteria->linkParams()]) ?>
<?php endif; ?>
