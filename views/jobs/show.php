<?php
/**
 * @var array<string,mixed> $job  @var bool $showFinancials  @var array<int,array<string,mixed>> $transitions
 * @var array<int,array<string,mixed>> $timeline  @var string|null $autoOpen
 */
$dispatched = !empty($job['carrier_name']) || !empty($job['driver_name']) || !empty($job['driver_phone']);
$dl = static function (string $label, string $valueHtml): string {
    return '<dt class="col-5 col-sm-4 text-body-secondary fw-normal">' . e($label) . '</dt><dd class="col-7 col-sm-8">' . $valueHtml . '</dd>';
};
?>
<div class="card jt-card mb-4">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
        <div class="me-auto">
            <div class="text-body-secondary small">Job #</div>
            <div class="h3 mb-0 jt-jobno"><?= e($job['job_number']) ?></div>
        </div>
        <?= status_badge((string) $job['status']) ?>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-secondary" href="<?= e(url('/jobs')) ?>">Back to jobs</a>
            <?php if (can('jobs.edit')): ?>
                <a class="btn btn-primary" href="<?= e(url('/jobs/' . $job['id'] . '/edit')) ?>"><?= icon('edit') ?> Edit</a>
            <?php endif; ?>
            <?php if (can('jobs.delete')): ?>
                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteJobModal"><?= icon('trash') ?> Delete</button>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($transitions !== []): ?>
    <div class="card jt-card mb-4">
        <div class="card-body d-flex flex-wrap align-items-center gap-2">
            <span class="fw-semibold me-2">Update status</span>
            <?php foreach ($transitions as $t): ?>
                <button type="button" class="btn btn-<?= e($t['style']) ?>" data-bs-toggle="modal" data-bs-target="#statusModal-<?= e($t['to']) ?>"><?= e($t['label']) ?></button>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-12 col-lg-6">
        <div class="card jt-card h-100">
            <div class="card-header bg-transparent fw-semibold">Customer &amp; route</div>
            <div class="card-body">
                <dl class="row mb-0 gy-2">
                    <?= $dl('Customer', e($job['customer_first_name'] . ' ' . $job['customer_last_name'])) ?>
                    <?= $dl('From', e($job['from_location'])) ?>
                    <?= $dl('To', e($job['to_location'])) ?>
                    <?= $dl('Job location', e($job['job_location'] ?: '—')) ?>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card jt-card h-100">
            <div class="card-header bg-transparent fw-semibold">Dates &amp; cargo</div>
            <div class="card-body">
                <dl class="row mb-0 gy-2">
                    <?= $dl('Pickup date', e(fmt_date($job['pickup_date']) ?: '—')) ?>
                    <?= $dl('1st available', e(fmt_date($job['first_available_date'])) . ' ' . availability_chip($job)) ?>
                    <?php if ((string) $job['status'] === 'delivered' && !empty($job['delivered_at'])): ?>
                        <?= $dl('Delivered', e(fmt_date((string) $job['delivered_at']))) ?>
                    <?php endif; ?>
                    <?= $dl('CF', e(fmt_number($job['cf']) ?: '—')) ?>
                    <?php if ($showFinancials): ?>
                        <?= $dl('Balance', e(fmt_money($job['balance']))) ?>
                        <?= $dl('Storage fee', e(fmt_money($job['storage_fee']))) ?>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card jt-card h-100">
            <div class="card-header bg-transparent fw-semibold">Carrier</div>
            <div class="card-body">
                <?php if ($dispatched): ?>
                    <dl class="row mb-0 gy-2">
                        <?= $dl('Carrier', e($job['carrier_name'] ?: '—')) ?>
                        <?= $dl('Driver', e($job['driver_name'] ?: '—')) ?>
                        <?= $dl('Phone', tel_link($job['driver_phone'])) ?>
                        <?php if (!empty($job['in_transit_at'])): ?>
                            <?= $dl('Dispatched', e(fmt_datetime((string) $job['in_transit_at']))) ?>
                        <?php endif; ?>
                    </dl>
                <?php else: ?>
                    <p class="text-body-secondary mb-0">Not dispatched yet. Carrier details are recorded when the job goes in transit.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card jt-card h-100">
            <div class="card-header bg-transparent fw-semibold">History</div>
            <div class="card-body">
                <?= \App\Core\View::partial('jobs/_timeline', ['timeline' => $timeline, 'job' => $job]) ?>
                <?php if ((string) $job['updated_at'] !== (string) $job['created_at']): ?>
                    <div class="jt-subtle mt-3 pt-3 border-top">
                        Last updated <?= e(fmt_datetime($job['updated_at'])) ?><?= !empty($job['updated_by_name']) ? ' by ' . e($job['updated_by_name']) : '' ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!empty($job['notes'])): ?>
        <div class="col-12">
            <div class="card jt-card">
                <div class="card-header bg-transparent fw-semibold">Notes</div>
                <div class="card-body jt-notes"><?= e($job['notes']) ?></div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if (can('jobs.delete')): ?>
    <?= \App\Core\View::partial('partials/confirm-modal', [
        'modalId' => 'deleteJobModal',
        'heading' => 'Delete job ' . $job['job_number'] . '?',
        'message' => 'The job will be moved to the trash and removed from all lists. You can restore it from the Trash page.',
        'action' => '/jobs/' . $job['id'] . '/delete',
        'confirmLabel' => 'Delete job',
    ]) ?>
<?php endif; ?>

<?php if ($transitions !== []): ?>
    <?= \App\Core\View::partial('jobs/_status_modals', ['job' => $job, 'transitions' => $transitions, 'autoOpen' => $autoOpen]) ?>
<?php endif; ?>
