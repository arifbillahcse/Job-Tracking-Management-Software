<?php
/**
 * One template for all five job lists; the section decides the columns.
 * @var string $bucket  @var \App\Services\JobCriteria $criteria  @var array<int,array<string,mixed>> $jobs
 * @var \App\Helpers\Paginator $paginator  @var array<string,int> $tabCounts  @var array<int,array{to:string,label:string}> $shortcuts
 * @var bool $showFinancials
 */
use App\Services\JobBucket;

$definition = JobBucket::definitions()[$bucket];
$path = $definition['path'];
$threshold = JobBucket::threshold();

// column key => [heading, sort key (null = not sortable), cell CSS class]
$all = [
    'job' => ['Job #', 'job', ''],
    'customer' => ['Customer', 'customer', ''],
    'pickup' => ['Pickup', 'pickup', ''],
    'available' => ['1st available', 'available', ''],
    'route' => ['Route', 'from', ''],
    'cf' => ['CF', 'cf', 'text-md-end'],
    'balance' => ['Balance', 'balance', 'text-md-end'],
    'storage' => ['Storage fee', 'storage', 'text-md-end'],
    'status' => ['Status', 'status', ''],
    'carrier' => ['Carrier', 'carrier', ''],
    'driver' => ['Driver', 'driver', ''],
    'phone' => ['Phone', null, ''],
    'dispatched' => ['Dispatched', 'dispatched', ''],
    'delivered' => ['Delivered', 'delivered', ''],
];
$layouts = [
    JobBucket::ALL => ['job', 'customer', 'pickup', 'available', 'route', 'cf', 'balance', 'status'],
    JobBucket::READY => ['job', 'customer', 'pickup', 'available', 'route', 'cf', 'balance'],
    JobBucket::LATER => ['job', 'customer', 'pickup', 'available', 'route', 'cf', 'balance', 'storage'],
    JobBucket::TRANSIT => ['job', 'customer', 'available', 'route', 'cf', 'balance', 'carrier', 'driver', 'phone', 'dispatched'],
    JobBucket::DELIVERED => ['job', 'customer', 'pickup', 'available', 'route', 'cf', 'balance', 'delivered'],
];
$columns = array_values(array_filter(
    $layouts[$bucket],
    static fn (string $key): bool => $showFinancials || !in_array($key, ['balance', 'storage'], true)
));

$descriptions = [
    JobBucket::ALL => 'Every job, including those on hold or cancelled.',
    JobBucket::READY => "Booked jobs whose 1st available date is within the next {$threshold} days, plus any that are overdue.",
    JobBucket::LATER => "Booked jobs whose 1st available date is more than {$threshold} days away. They move to Ready for Delivery automatically as the date approaches.",
    JobBucket::TRANSIT => 'Jobs on the road, with carrier, driver and phone number.',
    JobBucket::DELIVERED => 'Completed deliveries.',
];

$emptyBucket = [
    JobBucket::ALL => ['briefcase', 'No jobs yet', 'Jobs you create will be listed here.'],
    JobBucket::READY => ['clock', 'Nothing is ready for delivery', "Booked jobs with a 1st available date in the next {$threshold} days will appear here."],
    JobBucket::LATER => ['calendar', 'Nothing more than ' . $threshold . ' days away', 'Booked jobs with a later available date will appear here.'],
    JobBucket::TRANSIT => ['truck', 'No jobs in transit', 'Jobs appear here once they are dispatched with a carrier.'],
    JobBucket::DELIVERED => ['check-circle', 'No deliveries yet', 'Completed deliveries will appear here.'],
];

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

<?= \App\Core\View::partial('jobs/_filters', ['bucket' => $bucket, 'criteria' => $criteria, 'showFinancials' => $showFinancials]) ?>

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
            <table class="table table-hover align-middle mb-0 jt-table table-cards">
                <thead>
                <tr>
                    <?php foreach ($columns as $key): [$label, $sortKey, $class] = $all[$key]; ?>
                        <th scope="col" class="<?= e($class) ?>"<?= $sortKey !== null && $criteria->sort === $sortKey ? ' aria-sort="' . ($criteria->dir === 'asc' ? 'ascending' : 'descending') . '"' : '' ?>><?= $sortHeader($label, $sortKey) ?></th>
                    <?php endforeach; ?>
                    <th scope="col"><span class="visually-hidden">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($jobs as $job): ?>
                    <tr>
                        <?php foreach ($columns as $key): [$label, , $class] = $all[$key]; ?>
                            <td data-label="<?= e($label) ?>" class="<?= e($class) ?>">
                                <?php switch ($key):
                                    case 'job': ?>
                                        <a class="jt-jobno" href="<?= e(url('/jobs/' . $job['id'])) ?>"><?= e($job['job_number']) ?></a>
                                        <?php break;
                                    case 'customer': ?>
                                        <?= e($job['customer_first_name'] . ' ' . $job['customer_last_name']) ?>
                                        <?php break;
                                    case 'pickup': ?>
                                        <?= e(fmt_date($job['pickup_date']) ?: '—') ?>
                                        <?php break;
                                    case 'available': ?>
                                        <span class="jt-date"><?= e(fmt_date($job['first_available_date'])) ?></span>
                                        <?= availability_chip($job) ?>
                                        <?php if ($bucket === JobBucket::LATER): ?>
                                            <div class="jt-subtle">Moves to Ready on <?= e(fmt_date(JobBucket::movesToReadyOn((string) $job['first_available_date']))) ?></div>
                                        <?php endif; ?>
                                        <?php break;
                                    case 'route': ?>
                                        <?= e($job['from_location']) ?> <?= icon('arrow-right', 'text-body-secondary') ?> <?= e($job['to_location']) ?>
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
                                    case 'driver': ?>
                                        <?= e($job['driver_name'] ?: '—') ?>
                                        <?php break;
                                    case 'phone': ?>
                                        <?= tel_link($job['driver_phone']) ?>
                                        <?php break;
                                    case 'dispatched': ?>
                                        <?= e(fmt_date($job['in_transit_at'] ? (string) $job['in_transit_at'] : null) ?: '—') ?>
                                        <?php break;
                                    case 'delivered': ?>
                                        <?= e(fmt_date($job['delivered_at'] ? (string) $job['delivered_at'] : null) ?: '—') ?>
                                        <?php break;
                                endswitch; ?>
                            </td>
                        <?php endforeach; ?>
                        <td data-label="" class="jt-actions text-md-end">
                            <?php if (isset($shortcuts[(int) $job['id']])): $shortcut = $shortcuts[(int) $job['id']]; ?>
                                <a class="btn btn-sm btn-primary" href="<?= e(url('/jobs/' . $job['id'] . '?do=' . $shortcut['to'])) ?>"><?= e($shortcut['label']) ?></a>
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
