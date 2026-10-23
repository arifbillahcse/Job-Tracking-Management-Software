<?php
/** @var array<int,array<string,mixed>> $jobs  @var \App\Helpers\Paginator $paginator  @var bool $showFinancials */
?>
<div class="jt-page-actions">
    <p class="text-body-secondary mb-0 me-auto">
        <?= e($paginator->total) ?> job<?= $paginator->total === 1 ? '' : 's' ?>
    </p>
    <?php if (can('jobs.delete')): ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('/jobs/trash')) ?>"><?= icon('trash') ?> Trash</a>
    <?php endif; ?>
    <?php if (can('jobs.create')): ?>
        <a class="btn btn-primary" href="<?= e(url('/jobs/create')) ?>"><?= icon('plus') ?> New job</a>
    <?php endif; ?>
</div>

<?php if ($jobs === []): ?>
    <div class="card jt-card">
        <?= \App\Core\View::partial('partials/empty-state', [
            'emptyIcon' => 'briefcase',
            'heading' => 'No jobs yet',
            'message' => 'Jobs you create will be listed here.',
            'actionUrl' => can('jobs.create') ? '/jobs/create' : null,
            'actionLabel' => 'Create the first job',
        ]) ?>
    </div>
<?php else: ?>
    <div class="card jt-card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 jt-table table-cards">
                <thead>
                <tr>
                    <th scope="col">Job #</th>
                    <th scope="col">Customer</th>
                    <th scope="col">Pickup</th>
                    <th scope="col">1st available</th>
                    <th scope="col">Route</th>
                    <th scope="col" class="text-end">CF</th>
                    <?php if ($showFinancials): ?><th scope="col" class="text-end">Balance</th><?php endif; ?>
                    <th scope="col">Status</th>
                    <th scope="col"><span class="visually-hidden">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($jobs as $job): ?>
                    <tr>
                        <td data-label="Job #"><a class="jt-jobno" href="<?= e(url('/jobs/' . $job['id'])) ?>"><?= e($job['job_number']) ?></a></td>
                        <td data-label="Customer"><?= e($job['customer_first_name'] . ' ' . $job['customer_last_name']) ?></td>
                        <td data-label="Pickup"><?= e(fmt_date($job['pickup_date']) ?: '—') ?></td>
                        <td data-label="1st available"><?= e(fmt_date($job['first_available_date'])) ?></td>
                        <td data-label="Route"><?= e($job['from_location']) ?> <?= icon('arrow-right', 'text-body-secondary') ?> <?= e($job['to_location']) ?></td>
                        <td data-label="CF" class="text-md-end"><?= e(fmt_number($job['cf']) ?: '—') ?></td>
                        <?php if ($showFinancials): ?>
                            <td data-label="Balance" class="text-md-end"><?= e(fmt_money($job['balance'])) ?></td>
                        <?php endif; ?>
                        <td data-label="Status"><?= status_badge((string) $job['status']) ?></td>
                        <td data-label="" class="jt-actions text-md-end">
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
    <?= \App\Core\View::partial('partials/pagination', ['paginator' => $paginator, 'basePath' => '/jobs']) ?>
<?php endif; ?>
