<?php
/**
 * @var array<string,mixed>|null $data  job figures (null when the user may not view jobs)
 * @var array<int,string> $permissions  @var array{trips:int,jobs:int,expenses:?string}|null $trips  trips that began in the last 30 days (null without trips.view)
 */
use App\Services\JobBucket;

$user = auth_user();
$threshold = JobBucket::threshold();
?>
<div class="mb-4">
    <h2 class="h4 mb-1">Welcome, <?= e($user['name']) ?></h2>
    <p class="text-body-secondary mb-0">
        Signed in as <span class="badge text-bg-light border"><?= e($user['role_name']) ?></span>
        <?php if (!empty($user['last_login_at'])): ?>
            &middot; previous sign-in <?= e(fmt_datetime($user['last_login_at'])) ?>
        <?php endif; ?>
    </p>
</div>

<?php if ($data === null): ?>
    <div class="card jt-card">
        <div class="card-header bg-transparent fw-semibold d-flex align-items-center gap-2"><?= icon('shield') ?> Your permissions</div>
        <div class="card-body">
            <p class="text-body-secondary">Your role cannot view jobs, so no job figures are shown here.</p>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($permissions as $permission): ?>
                    <span class="badge text-bg-light border fw-normal"><?= e($permission) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php else:
    $counts = $data['counts'];
    $statuses = $data['statuses'];
    $cards = [
        [JobBucket::ALL, 'all', 'briefcase', 'Total jobs', ($statuses['on_hold'] ?? 0) . ' on hold · ' . ($statuses['cancelled'] ?? 0) . ' cancelled'],
        [JobBucket::BOOKED, 'booked', 'inbox', 'Booked jobs', 'not delivered yet'],
        [JobBucket::FUTURE, 'future', 'calendar', 'Future jobs', 'pickup date after today'],
        [JobBucket::READY, 'ready', 'clock', 'Ready for delivery', JobBucket::basis() === 'pickup' ? 'picked up in the last ' . $threshold . ' days' : 'within ' . $threshold . ' days of today'],
        [JobBucket::LATER, 'later', 'calendar', 'More than ' . $threshold . ' days', JobBucket::basis() === 'pickup' ? 'picked up over ' . $threshold . ' days ago' : 'ahead, or already waiting longer'],
        [JobBucket::TRANSIT, 'transit', 'truck', 'In transit', 'on the road now'],
        [JobBucket::DELIVERED, 'delivered', 'check-circle', 'Delivered', 'completed deliveries'],
    ];
    ?>
    <div class="row g-3 row-cols-2 row-cols-md-4 mb-4">
        <?php foreach ($cards as [$bucket, $tone, $icon, $label, $hint]): ?>
            <div class="col">
                <a class="jt-stat jt-stat-<?= e($tone) ?>" href="<?= e(url(JobBucket::definitions()[$bucket]['path'])) ?>">
                    <span class="jt-stat-icon"><?= icon($icon) ?></span>
                    <span class="jt-stat-value"><?= e($counts[$bucket]) ?></span>
                    <span class="jt-stat-label"><?= e($label) ?></span>
                    <span class="jt-stat-hint"><?= e($hint) ?></span>
                </a>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-4">
        <div class="col-12 col-xl-7">
            <div class="card jt-card mb-4">
                <div class="card-header bg-transparent fw-semibold d-flex align-items-center gap-2">
                    <?= icon('alert-circle', 'text-danger') ?> Overdue
                    <?php if ($data['overdue_count'] > 0): ?><span class="badge text-bg-danger"><?= e($data['overdue_count']) ?></span><?php endif; ?>
                    <a class="ms-auto small fw-normal" href="<?= e(list_url('/jobs/booked', ['overdue' => '1', 'sort' => 'available', 'dir' => 'asc'])) ?>">View all overdue</a>
                </div>
                <?php if ($data['overdue'] === []): ?>
                    <div class="card-body text-body-secondary">Nothing is overdue. No booked job has passed its 1st available date.</div>
                <?php else: ?>
                    <ul class="list-group list-group-flush jt-joblist">
                        <?php foreach ($data['overdue'] as $job): ?>
                            <?= \App\Core\View::partial('dashboard/_job_row', ['job' => $job]) ?>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="card jt-card">
                <div class="card-header bg-transparent fw-semibold d-flex align-items-center gap-2">
                    <?= icon('clock', 'text-warning') ?> Coming up next
                    <a class="ms-auto small fw-normal" href="<?= e(list_url('/jobs/booked', ['sort' => 'available', 'dir' => 'asc'])) ?>">View booked jobs</a>
                </div>
                <?php if ($data['upcoming'] === []): ?>
                    <div class="card-body text-body-secondary">No booked job has a 1st available date from today on.</div>
                <?php else: ?>
                    <ul class="list-group list-group-flush jt-joblist">
                        <?php foreach ($data['upcoming'] as $job): ?>
                            <?= \App\Core\View::partial('dashboard/_job_row', ['job' => $job]) ?>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-12 col-xl-5">
            <?php if ($data['money'] !== null): ?>
                <div class="card jt-card mb-4">
                    <div class="card-header bg-transparent fw-semibold d-flex align-items-center gap-2"><?= icon('dollar') ?> Money</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="text-body-secondary small">Outstanding balance</div>
                                <div class="h4 mb-0 jt-money"><?= e(fmt_money($data['money']['balance'])) ?></div>
                                <div class="jt-subtle">all jobs except cancelled</div>
                            </div>
                            <div class="col-6">
                                <div class="text-body-secondary small">Storage fees</div>
                                <div class="h4 mb-0 jt-money"><?= e(fmt_money($data['money']['storage_fee'])) ?></div>
                                <div class="jt-subtle">on undelivered jobs</div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (($invoices ?? null) !== null): ?>
                <div class="card jt-card mb-4">
                    <div class="card-header bg-transparent fw-semibold d-flex align-items-center gap-2"><?= icon('file-text') ?> Invoices
                        <a class="ms-auto small fw-normal" href="<?= e(url('/invoices')) ?>">All invoices</a></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <?php foreach ($invoices['types'] as $type => $t): ?>
                                <div class="col-6">
                                    <div class="text-body-secondary small"><?= e($t['label']) ?>s open</div>
                                    <div class="h4 mb-0"><?= e($t['open']) ?></div>
                                    <div class="jt-subtle"><?= e(fmt_money($t['open_sum'])) ?> not settled &middot; <?= e($t['paid_30']) ?> paid in 30 days</div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php if ($invoices['recent'] !== []): ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($invoices['recent'] as $inv): $st = \App\Services\InvoiceService::status($inv); ?>
                                <li class="list-group-item d-flex flex-wrap gap-2 align-items-center">
                                    <a class="jt-jobno" href="<?= e(url('/invoices/' . $inv['id'])) ?>"><?= e(\App\Services\InvoiceService::number($inv)) ?></a>
                                    <span class="text-body-secondary small"><?= e(fmt_date((string) $inv['invoice_date'])) ?> &middot; <?= e((string) ($inv['driver'] ?: ($inv['carrier'] ?: ($inv['broker'] ?: '')))) ?></span>
                                    <span class="ms-auto jt-money"><?= e(fmt_money($inv['total'])) ?></span>
                                    <?= $st === 'paid' ? '<span class="badge text-bg-success">Paid</span>' : '<span class="badge text-bg-warning">Open</span>' ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="card-body border-top text-body-secondary">Saved invoices appear here.</div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($trips !== null): ?>
                <div class="card jt-card mb-4">
                    <div class="card-header bg-transparent fw-semibold d-flex align-items-center gap-2"><?= icon('truck') ?> Foreman trips <span class="jt-subtle fw-normal">last 30 days</span>
                        <a class="ms-auto small fw-normal" href="<?= e(url('/trips/report')) ?>">By foreman</a></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-4"><div class="text-body-secondary small">Trips</div><div class="h4 mb-0"><?= e($trips['trips']) ?></div></div>
                            <div class="col-4"><div class="text-body-secondary small">Jobs</div><div class="h4 mb-0"><?= e($trips['jobs']) ?></div><?php if ((int) $trips['entries'] !== (int) $trips['jobs']): ?><div class="jt-subtle"><?= e($trips['entries']) ?> entries</div><?php endif; ?></div>
                            <?php if ($trips['expenses'] !== null): ?>
                                <div class="col-4"><div class="text-body-secondary small">Expenses</div><div class="h4 mb-0 jt-money"><?= e(fmt_money($trips['expenses'])) ?></div></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card jt-card">
                <div class="card-header bg-transparent fw-semibold d-flex align-items-center gap-2"><?= icon('activity') ?> Recent activity</div>
                <?php if ($data['activity'] === []): ?>
                    <div class="card-body text-body-secondary">Activity appears here as jobs are created and moved.</div>
                <?php else: ?>
                    <ul class="list-group list-group-flush jt-activity">
                        <?php foreach ($data['activity'] as $event): ?>
                            <li class="list-group-item">
                                <div class="d-flex justify-content-between gap-2">
                                    <a class="jt-jobno" href="<?= e(url('/jobs/' . $event['job_id'])) ?>"><?= e($event['job_number']) ?></a>
                                    <span class="jt-subtle"><?= e(fmt_datetime($event['at'])) ?></span>
                                </div>
                                <div class="small"><?= e($event['customer']) ?> &middot; <?= e($event['text']) ?><?= !empty($event['by']) ? ' &middot; ' . e($event['by']) : '' ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>
