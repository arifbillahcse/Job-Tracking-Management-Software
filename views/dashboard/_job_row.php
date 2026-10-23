<?php /** @var array<string,mixed> $job  brief job row from DashboardService */ ?>
<li class="list-group-item">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
            <a class="jt-jobno" href="<?= e(url('/jobs/' . $job['id'])) ?>"><?= e($job['job_number']) ?></a>
            <span class="text-body-secondary">&middot; <?= e($job['customer']) ?></span>
            <div class="jt-subtle"><?= e($job['from_location']) ?> <?= icon('arrow-right') ?> <?= e($job['to_location']) ?></div>
        </div>
        <div class="text-end">
            <div class="jt-date"><?= e(fmt_date($job['first_available_date'])) ?></div>
            <?= availability_chip($job) ?>
        </div>
    </div>
</li>
