<?php
/**
 * Import preview (status "previewed") and result (status "committed").
 * @var array<string,mixed> $batch  @var array<int,array<string,mixed>> $errors  @var array<string,array<string,mixed>> $fields
 */
$status = (string) $batch['status'];
$done = $status === 'committed';
$total = (int) $batch['total_rows'];
$valid = (int) $batch['valid_rows'];
$invalid = (int) $batch['invalid_rows'];
$shownErrors = count($errors);
?>
<div class="jt-page-actions">
    <p class="text-body-secondary mb-0 me-auto text-break"><?= icon('file-text') ?> <?= e($batch['original_name']) ?> &middot; <?= e(strtoupper($batch['file_format'])) ?> &middot; uploaded <?= e(fmt_datetime($batch['created_at'])) ?></p>
    <a class="btn btn-outline-secondary" href="<?= e(url('/jobs/import')) ?>">Back to import</a>
</div>

<?php if (in_array($status, ['cancelled', 'expired'], true)): ?>
    <div class="card jt-card">
        <?= \App\Core\View::partial('partials/empty-state', [
            'emptyIcon' => 'file-text',
            'heading' => $status === 'cancelled' ? 'This import was cancelled' : 'This preview expired',
            'message' => 'Nothing was imported and the uploaded file has been deleted.',
            'actionUrl' => '/jobs/import', 'actionLabel' => 'Upload a file', 'actionIcon' => 'upload',
        ]) ?>
    </div>
<?php else: ?>
    <div class="row g-3 row-cols-1 row-cols-sm-3 mb-4">
        <div class="col"><div class="jt-stat jt-stat-all"><span class="jt-stat-value"><?= e(number_format($total)) ?></span><span class="jt-stat-label">Rows read</span><span class="jt-stat-hint">excluding the heading row</span></div></div>
        <div class="col"><div class="jt-stat jt-stat-transit"><span class="jt-stat-value"><?= e(number_format($done ? (int) $batch['imported_rows'] : $valid)) ?></span><span class="jt-stat-label"><?= $done ? 'Jobs imported' : 'Ready to import' ?></span><span class="jt-stat-hint"><?= $done ? 'saved as new jobs' : 'valid and not duplicates' ?></span></div></div>
        <div class="col"><div class="jt-stat <?= $invalid > 0 ? 'jt-stat-later' : 'jt-stat-delivered' ?>"><span class="jt-stat-value"><?= e(number_format($invalid)) ?></span><span class="jt-stat-label">Problems</span><span class="jt-stat-hint"><?= $invalid > 0 ? 'rows that will be skipped' : 'nothing to fix' ?></span></div></div>
    </div>

    <?php foreach ($batch['warnings'] as $warning): ?>
        <div class="alert alert-warning d-flex gap-2" role="alert"><?= icon('alert-triangle', 'mt-1 flex-shrink-0') ?><div><?= e($warning) ?></div></div>
    <?php endforeach; ?>

    <?php if (!$done): ?>
        <div class="card jt-card mb-4">
            <div class="card-body d-flex flex-wrap align-items-center gap-3">
                <div class="me-auto">
                    <div class="fw-semibold"><?= $valid > 0 ? 'Import ' . e(number_format($valid)) . ' job' . ($valid === 1 ? '' : 's') . '?' : 'Nothing can be imported from this file' ?></div>
                    <div class="small text-body-secondary">
                        <?= $valid > 0 ? 'Nothing has been saved yet.' . ($invalid > 0 ? ' The ' . e(number_format($invalid)) . ' problem row(s) will be skipped.' : '') : 'Fix the problems below, then upload the file again.' ?>
                        New jobs start as Booked unless the file has a valid Status column and you may set it.
                    </div>
                </div>
                <form method="post" action="<?= e(url('/jobs/import/' . $batch['id'] . '/cancel')) ?>" data-loading><?= csrf_field() ?>
                    <button type="submit" class="btn btn-outline-secondary">Cancel</button>
                </form>
                <form method="post" action="<?= e(url('/jobs/import/' . $batch['id'] . '/commit')) ?>" data-loading><?= csrf_field() ?>
                    <button type="submit" class="btn btn-primary"<?= $valid === 0 ? ' disabled' : '' ?>><?= icon('check-circle') ?> Import <?= e(number_format($valid)) ?> job<?= $valid === 1 ? '' : 's' ?></button>
                </form>
            </div>
        </div>
    <?php else: ?>
        <div class="card jt-card mb-4">
            <div class="card-body d-flex flex-wrap align-items-center gap-2">
                <span class="me-auto fw-semibold">Imported <?= e(fmt_datetime($batch['committed_at'])) ?></span>
                <a class="btn btn-primary" href="<?= e(url('/jobs')) ?>"><?= icon('briefcase') ?> View jobs</a>
                <?php if ($invalid > 0): ?>
                    <a class="btn btn-outline-secondary" href="<?= e(url('/jobs/import/' . $batch['id'] . '/problems')) ?>"><?= icon('download') ?> Problem report (CSV)</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($invalid > 0): ?>
        <div class="card jt-card mb-4">
            <div class="card-header bg-transparent fw-semibold d-flex flex-wrap align-items-center gap-2">
                <?= icon('alert-circle', 'text-danger') ?> Problems
                <span class="badge text-bg-danger"><?= e(number_format($invalid)) ?></span>
                <a class="ms-auto small fw-normal" href="<?= e(url('/jobs/import/' . $batch['id'] . '/problems')) ?>"><?= icon('download') ?> Download all as CSV</a>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0 jt-table table-cards">
                    <thead><tr><th scope="col">Row</th><th scope="col">Job #</th><th scope="col">What is wrong</th></tr></thead>
                    <tbody>
                    <?php foreach ($errors as $error): ?>
                        <tr>
                            <td data-label="Row"><span class="jt-jobno"><?= e($error['row_no']) ?></span></td>
                            <td data-label="Job #"><?= e($error['job_number'] ?: '—') ?></td>
                            <td data-label="What is wrong">
                                <ul class="list-unstyled mb-0">
                                    <?php foreach ($error['messages'] as $message): ?><li><?= e($message) ?></li><?php endforeach; ?>
                                </ul>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($invalid > $shownErrors): ?>
                <div class="card-footer bg-transparent small text-body-secondary">Showing the first <?= e($shownErrors) ?> of <?= e(number_format($invalid)) ?>. The CSV report has them all.</div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!$done && $batch['sample'] !== []): ?>
        <div class="card jt-card mb-4">
            <div class="card-header bg-transparent fw-semibold">Preview of the first <?= e(count($batch['sample'])) ?> valid row<?= count($batch['sample']) === 1 ? '' : 's' ?></div>
            <div class="table-responsive">
                <table class="table align-middle mb-0 jt-table table-cards">
                    <thead><tr><th scope="col">Job #</th><th scope="col">Customer</th><th scope="col">1st available</th><th scope="col">Route</th><th scope="col">Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($batch['sample'] as $row): ?>
                        <tr>
                            <td data-label="Job #"><span class="jt-jobno"><?= e($row['job_number']) ?></span></td>
                            <td data-label="Customer"><?= e($row['customer_first_name'] . ' ' . $row['customer_last_name']) ?></td>
                            <td data-label="1st available"><?= e(fmt_date($row['first_available_date'])) ?></td>
                            <td data-label="Route"><?= e($row['from_location']) ?> <?= icon('arrow-right', 'text-body-secondary') ?> <?= e($row['to_location']) ?></td>
                            <td data-label="Status"><?= status_badge((string) $row['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$done): ?>
        <div class="card jt-card">
            <div class="card-header bg-transparent fw-semibold">How your columns were understood</div>
            <div class="card-body">
                <?php if ($batch['mapping'] === []): ?>
                    <span class="text-body-secondary">No columns recognised.</span>
                <?php else: ?>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($batch['mapping'] as $header => $label): ?>
                            <span class="badge text-bg-light border fw-normal"><?= e($header) ?> <?= icon('arrow-right') ?> <strong><?= e($label) ?></strong></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($batch['ignored'] !== []): ?>
                    <div class="small text-body-secondary mt-3">Not used: <?= e(implode(', ', $batch['ignored'])) ?></div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>
