<?php
/**
 * @var array<int,array<string,mixed>> $recent  @var array<string,array<string,mixed>> $fields  @var bool $financials  @var bool $workflow
 * @var array<int,string> $formats  @var int $maxBytes  @var int $maxRows
 */
$accept = implode(',', array_map(static fn (string $f): string => '.' . $f, $formats));
$statusBadge = [
    'previewed' => '<span class="jt-badge jt-badge-hold">Waiting for confirmation</span>',
    'importing' => '<span class="jt-badge jt-badge-hold">Importing</span>',
    'committed' => '<span class="jt-badge jt-badge-transit">Imported</span>',
    'cancelled' => '<span class="jt-badge jt-badge-neutral">Cancelled</span>',
    'expired' => '<span class="jt-badge jt-badge-neutral">Expired</span>',
];
?>
<div class="row g-4">
    <div class="col-12 col-xl-7">
        <div class="card jt-card">
            <div class="card-header bg-transparent fw-semibold d-flex align-items-center gap-2"><?= icon('upload') ?> Upload a file</div>
            <div class="card-body">
                <ol class="jt-steps">
                    <li><strong>Upload</strong> a <?= e(implode(' or ', array_map('strtoupper', $formats))) ?> file. Your own sheet works as long as the headings are recognisable.</li>
                    <li><strong>Review</strong> the preview. Nothing is saved yet, and every problem row is listed.</li>
                    <li><strong>Confirm</strong> to import the valid rows. Problem rows are skipped and kept in a report you can fix and upload again.</li>
                </ol>

                <form method="post" action="<?= e(url('/jobs/import')) ?>" enctype="multipart/form-data" novalidate data-loading>
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label for="file" class="form-label">File</label>
                        <input type="file" class="form-control" id="file" name="file" accept="<?= e($accept) ?>" required aria-describedby="fileHelp">
                        <div id="fileHelp" class="form-text">
                            <?= e(implode(', ', array_map(static fn (string $f): string => '.' . $f, $formats))) ?> up to <?= e(round($maxBytes / 1048576, 1)) ?> MB and <?= e(number_format($maxRows)) ?> rows.
                            The first row must contain the column headings.
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary"><?= icon('upload') ?> Upload and preview</button>
                </form>

                <hr class="my-4">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span class="fw-semibold me-1">Need a starting point?</span>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/jobs/import/template/csv')) ?>"><?= icon('download') ?> CSV template</a>
                    <?php if (in_array('xlsx', $formats, true)): ?>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/jobs/import/template/xlsx')) ?>"><?= icon('download') ?> Excel template</a>
                    <?php endif; ?>
                </div>
                <p class="small text-body-secondary mt-3 mb-0">
                    Tip: the file you get from <strong>Export</strong> on All Jobs uses the same columns, so you can export, edit and import again.
                </p>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-5">
        <div class="card jt-card">
            <div class="card-header bg-transparent fw-semibold">Recent imports</div>
            <?php if ($recent === []): ?>
                <div class="card-body text-body-secondary">Your imports will be listed here.</div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($recent as $batch): ?>
                        <li class="list-group-item">
                            <div class="d-flex justify-content-between gap-2 flex-wrap">
                                <a class="jt-jobno text-break" href="<?= e(url('/jobs/import/' . $batch['id'])) ?>"><?= e($batch['original_name']) ?></a>
                                <?= $statusBadge[$batch['status']] ?? '' ?>
                            </div>
                            <div class="jt-subtle">
                                <?= e(fmt_datetime($batch['created_at'])) ?> &middot;
                                <?= $batch['status'] === 'committed'
                                    ? e(number_format((int) $batch['imported_rows'])) . ' imported, ' . e(number_format((int) $batch['skipped_rows'])) . ' skipped'
                                    : e(number_format((int) $batch['total_rows'])) . ' rows' ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-12">
        <div class="card jt-card">
            <div class="card-header bg-transparent fw-semibold">Columns</div>
            <div class="card-body pb-0">
                <p class="text-body-secondary">
                    Headings are matched loosely (<code>Job #</code>, <code>job number</code> and <code>JOB#</code> all work, and so do the headings in your existing sheet).
                    Columns that match nothing are ignored. Dates can be written <code>2026-11-01</code> or <code>11/01/2026</code>; money may include $ and commas.
                </p>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0 jt-table table-cards">
                    <thead>
                    <tr><th scope="col">Column</th><th scope="col">Required</th><th scope="col">Example</th><th scope="col">Notes</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($fields as $key => $field):
                        $blocked = ($field['financial'] && !$financials) || ($field['workflow'] && !$workflow);
                        ?>
                        <tr<?= $blocked ? ' class="text-body-secondary"' : '' ?>>
                            <td data-label="Column"><span class="fw-semibold"><?= e($field['label']) ?></span></td>
                            <td data-label="Required"><?= $field['required'] ? '<span class="badge text-bg-primary">Required</span>' : 'Optional' ?></td>
                            <td data-label="Example"><code><?= e($field['example']) ?></code></td>
                            <td data-label="Notes"><?= e($field['help']) ?><?= $blocked ? ' <span class="jt-chip jt-chip-later">not available to you</span>' : '' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
