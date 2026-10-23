<?php /** @var array<int,array<string,mixed>> $jobs */ ?>
<div class="jt-page-actions">
    <p class="text-body-secondary mb-0 me-auto">Deleted jobs stay here until restored. Their job numbers remain reserved.</p>
    <a class="btn btn-outline-secondary" href="<?= e(url('/jobs')) ?>">Back to jobs</a>
</div>

<?php if ($jobs === []): ?>
    <div class="card jt-card">
        <?= \App\Core\View::partial('partials/empty-state', [
            'emptyIcon' => 'trash',
            'heading' => 'The trash is empty',
            'message' => 'Deleted jobs will appear here so they can be restored.',
        ]) ?>
    </div>
<?php else: ?>
    <div class="card jt-card">
        <div class="table-responsive">
            <table class="table align-middle mb-0 jt-table table-cards">
                <thead>
                <tr>
                    <th scope="col">Job #</th>
                    <th scope="col">Customer</th>
                    <th scope="col">Status</th>
                    <th scope="col">Deleted</th>
                    <th scope="col">By</th>
                    <th scope="col"><span class="visually-hidden">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($jobs as $job): ?>
                    <tr>
                        <td data-label="Job #"><span class="jt-jobno"><?= e($job['job_number']) ?></span></td>
                        <td data-label="Customer"><?= e($job['customer_first_name'] . ' ' . $job['customer_last_name']) ?></td>
                        <td data-label="Status"><?= status_badge((string) $job['status']) ?></td>
                        <td data-label="Deleted"><?= e(fmt_datetime($job['deleted_at'])) ?></td>
                        <td data-label="By"><?= e($job['updated_by_name'] ?? '—') ?></td>
                        <td data-label="" class="jt-actions text-md-end">
                            <form method="post" action="<?= e(url('/jobs/' . $job['id'] . '/restore')) ?>" data-loading>
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-primary"><?= icon('rotate-ccw') ?> Restore</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
