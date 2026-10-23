<?php
/**
 * @var array<string,mixed>|null $data  job figures (null when the user may not view jobs)
 * @var array<int,string> $permissions
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
        [JobBucket::READY, 'ready', 'clock', 'Ready for delivery', 'within ' . $threshold . ' days' . ($data['overdue_count'] > 0 ? ' · ' . $data['overdue_count'] . ' overdue' : '')],
        [JobBucket::LATER, 'later', 'calendar', 'More than ' . $threshold . ' days', 'move to Ready automatically'],
        [JobBucket::TRANSIT, 'transit', 'truck', 'In transit', 'on the road now'],
        [JobBucket::DELIVERED, 'delivered', 'check-circle', 'Delivered', 'completed deliveries'],
    ];
    ?>
    <div class="row g-3 row-cols-2 row-cols-md-3 row-cols-xl-5 mb-4">
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
                    <a class="ms-auto small fw-normal" href="<?= e(list_url('/jobs/ready', ['sort' => 'available', 'dir' => 'asc'])) ?>">View all ready</a>
                </div>
                <?php if ($data['overdue'] === []): ?>
                    <div class="card-body text-body-secondary">Nothing is overdue. Every booked job is still within its available date.</div>
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
                    <a class="ms-auto small fw-normal" href="<?= e(url('/jobs/ready')) ?>">View ready for delivery</a>
                </div>
                <?php if ($data['upcoming'] === []): ?>
                    <div class="card-body text-body-secondary">No booked jobs are due in the next <?= e($threshold) ?> days.</div>
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
