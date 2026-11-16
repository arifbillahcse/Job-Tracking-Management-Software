<?php
/**
 * Pickup calendar: Future Jobs on their pickup date, one month at a time.
 * @var int $secondCount  @var array<string,int> $tabCounts  @var array<int,array<int,array<string,mixed>|null>> $weeks  @var int $count  @var string $monthLabel  @var string $month
 * @var string $prevMonth  @var string $nextMonth  @var string $thisMonth  @var int $foremanId  @var array<int,array<string,mixed>> $foremen  @var bool $showFinancials
 */
use App\Services\JobService;

$foremanParam = $foremanId === -1 ? 'none' : ($foremanId > 0 ? (string) $foremanId : '');
$nav = static fn (string $m): string => list_url('/jobs/calendar', ['month' => $m === $thisMonth ? '' : $m, 'foreman' => $foremanParam]);
$dows = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
?>
<?= \App\Core\View::partial('jobs/_tabs', ['bucket' => 'calendar', 'tabCounts' => $tabCounts]) ?>

<div class="jt-page-actions">
    <p class="text-body-secondary mb-0 me-auto jt-section-note">Future Jobs on the day they are picked up: booked jobs with a pickup date after today. Jobs on hold, cancelled, in transit or delivered, and jobs with no pickup date, are not shown. A trip entry with the activity Pickup on a later day shows as a second pickup.</p>
    <a class="btn btn-outline-secondary" href="<?= e(url('/jobs/future')) ?>">Future Jobs list</a>
</div>

<div class="card jt-card mb-3">
    <div class="card-body d-flex flex-wrap align-items-end gap-3">
        <div class="jt-cal-title me-auto">
            <div class="h5 mb-0">Future Jobs (Pick-up): <?= e($count) ?><?php if ($secondCount > 0): ?> <span class="jt-cal-more">+ <?= e($secondCount) ?> second pickup<?= $secondCount === 1 ? '' : 's' ?></span><?php endif; ?></div>
            <div class="text-body-secondary"><?= e($monthLabel) ?></div>
        </div>
        <div class="btn-group" role="group" aria-label="Month">
            <a class="btn btn-outline-secondary" href="<?= e($nav($prevMonth)) ?>">&laquo; Previous Month</a>
            <a class="btn btn-outline-secondary<?= $month === $thisMonth ? ' active' : '' ?>" href="<?= e($nav($thisMonth)) ?>">Today</a>
            <a class="btn btn-outline-secondary" href="<?= e($nav($nextMonth)) ?>">Next Month &raquo;</a>
        </div>
        <form method="get" action="<?= e(url('/jobs/calendar')) ?>" class="d-flex align-items-end gap-2">
            <input type="hidden" name="month" value="<?= e($month) ?>">
            <div>
                <label class="form-label small mb-1" for="cal-foreman">Foreman</label>
                <select class="form-select" id="cal-foreman" name="foreman">
                    <option value="">Any foreman</option>
                    <option value="none"<?= $foremanId === -1 ? ' selected' : '' ?>>No foreman assigned</option>
                    <?php foreach ($foremen as $f): ?>
                        <option value="<?= e($f['id']) ?>"<?= $foremanId === (int) $f['id'] ? ' selected' : '' ?>><?= e($f['name']) ?><?= (int) $f['active'] === 1 ? '' : ' (inactive)' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Show</button>
        </form>
    </div>
</div>

<div class="jt-cal" role="grid" aria-label="Pickup calendar, <?= e($monthLabel) ?>">
    <?php foreach ($dows as $dow): ?><div class="jt-cal-head" role="columnheader"><?= e($dow) ?></div><?php endforeach; ?>
    <?php foreach ($weeks as $week): foreach ($week as $cell): ?>
        <?php if ($cell === null): ?>
            <div class="jt-cal-day jt-cal-blank" role="gridcell" aria-hidden="true"></div>
        <?php else: ?>
            <div class="jt-cal-day<?= $cell['today'] ? ' jt-cal-today' : '' ?><?= $cell['jobs'] === [] ? ' jt-cal-empty' : '' ?>" role="gridcell">
                <div class="jt-cal-date"><span class="jt-cal-num"><?= e($cell['day']) ?></span><span class="jt-cal-dow"><?= e($cell['dow']) ?></span><?php if ($cell['jobs'] !== []): ?><span class="jt-cal-count" title="Pickups this day"><?= e(count($cell['jobs'])) ?></span><?php endif; ?></div>
                <?php foreach ($cell['jobs'] as $i => $job):
                    $checks = array_filter(JobService::CHECKS, static fn (string $k): bool => !empty($job[$k]), ARRAY_FILTER_USE_KEY);
                    $cf = ($job['cf'] ?? null) !== null && (float) $job['cf'] > 0 ? fmt_number($job['cf']) . ' cf' : (($job['estimated_cf'] ?? null) !== null && (float) $job['estimated_cf'] > 0 ? 'E ' . fmt_number($job['estimated_cf']) . ' cf' : '');
                    $miles = ($job['miles'] ?? null) !== null ? fmt_number($job['miles']) . ' mi' : '';
                    ?>
                    <div class="jt-cal-job<?= !empty($job['second']) ? ' jt-cal-job-second' : '' ?>">
                        <div class="jt-cal-job-top">
                            <span class="jt-cal-ord"><?= e($i + 1) ?></span>
                            <a class="jt-jobno" href="<?= e(url('/jobs/' . $job['id'])) ?>"><?= e($job['job_number']) ?></a>
                            <?php if (!empty($job['second'])): ?><span class="jt-cal-2nd" title="A trip entry with the activity Pickup on this day (trip <?= e($job['trip_number']) ?>)">2nd pickup</span><?php endif; ?>
                            <?php if (($job['pickup_time'] ?? '') !== ''): ?><span class="jt-cal-time"><?= e($job['pickup_time']) ?></span><?php endif; ?>
                        </div>
                        <div><?= e($job['customer_first_name'] . ' ' . $job['customer_last_name']) ?></div>
                        <div class="jt-subtle"><?= e($job['from_location']) ?> &rarr; <?= e($job['to_location']) ?></div>
                        <div class="jt-subtle"><?= e(trim($miles . ($miles !== '' && $cf !== '' ? ' / ' : '') . $cf)) ?: '&nbsp;' ?></div>
                        <div class="jt-subtle"><?= e($job['foreman'] ?? '') !== '' ? e($job['foreman']) : 'No foreman' ?><?= $showFinancials ? ' &middot; <span class="jt-money">' . e(fmt_money($job['balance'])) . '</span>' : '' ?></div>
                        <?php if ($checks !== []): ?>
                            <div class="jt-cal-checks"><?php foreach ($checks as $label): ?><span class="jt-cal-check">&#10003; <?= e($label) ?></span><?php endforeach; ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endforeach; endforeach; ?>
</div>
