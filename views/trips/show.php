<?php
/** @var array<string,mixed> $trip  @var bool $showMoney */
$cols = ['diesel' => 'Diesel', 'hotels' => 'Hotels', 'helper' => 'Helper', 'misc' => 'Misc', 'commission' => 'Commission', 'extra' => 'Extra'];
$dl = static fn (string $label, string $html): string => '<dt class="col-sm-4 text-body-secondary fw-normal">' . e($label) . '</dt><dd class="col-sm-8 mb-0">' . $html . '</dd>';
?>
<div class="jt-page-actions">
    <a class="btn btn-outline-secondary" href="<?= e(url('/trips')) ?>"><?= icon('arrow-left') ?> All trips</a>
    <span class="me-auto"></span>
    <?php if (can('trips.manage')): ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('/trips/' . $trip['id'] . '/edit')) ?>"><?= icon('edit') ?> Edit</a>
        <form class="d-inline" method="post" action="<?= e(url('/trips/' . $trip['id'] . '/delete')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-outline-danger" data-confirm="<?= e('Delete trip ' . $trip['trip_number'] . '? The jobs on it are not affected.') ?>"><?= icon('trash') ?> Delete</button>
        </form>
    <?php endif; ?>
</div>

<div class="card jt-card mb-4">
    <div class="card-header bg-transparent fw-semibold">Trip <?= e($trip['trip_number']) ?></div>
    <div class="card-body">
        <dl class="row mb-0 gy-2">
            <?= $dl('Foreman', e($trip['foreman_name'])) ?>
            <?= $dl('Trip begins', e(fmt_date($trip['begins_on']) ?: '—')) ?>
            <?= $dl('Trip ends', e(fmt_date($trip['ends_on']) ?: '—')) ?>
            <?= $dl('Collected by', e($trip['collected_by'] ?: '—')) ?>
            <?php if (!empty($trip['notes'])): ?><?= $dl('Notes', nl2br(e($trip['notes']))) ?><?php endif; ?>
        </dl>
    </div>
</div>

<div class="card jt-card">
    <div class="card-header bg-transparent fw-semibold d-flex flex-wrap align-items-center gap-2">
        <span>Jobs on this trip (<?= e(count($trip['rows'])) ?>)</span>
        <?php if (can('trips.manage')): ?>
            <form class="ms-auto d-flex gap-2 fw-normal" method="post" action="<?= e(url('/trips/' . $trip['id'] . '/jobs')) ?>">
                <?= csrf_field() ?>
                <label class="visually-hidden" for="add-job-number">Job number to add</label>
                <input type="text" class="form-control form-control-sm" id="add-job-number" name="job_number" maxlength="40" placeholder="Job # to add" required>
                <button type="submit" class="btn btn-sm btn-primary">Add job</button>
            </form>
        <?php endif; ?>
    </div>
    <?php if ($trip['rows'] === []): ?>
        <div class="card-body text-body-secondary">
            No jobs on this trip yet.
            <?= can('trips.manage') ? 'Type a job number above, choose this trip when you create or edit a job, or use <strong>Edit</strong> to add several at once.' : '' ?>
        </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 jt-table">
            <thead>
            <tr>
                <th scope="col">Job #</th><th scope="col">Customer</th><th scope="col">Date</th><th scope="col">From</th><th scope="col">BH</th>
                <?php if ($showMoney): foreach ($cols as $label): ?><th scope="col" class="text-end"><?= e($label) ?></th><?php endforeach; endif; ?>
                <th scope="col">Remark</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($trip['rows'] as $row): ?>
                <tr>
                    <td><?= can('jobs.view') && empty($row['job_deleted']) ? '<a class="jt-jobno" href="' . e(url('/jobs/' . $row['job_id'])) . '">' . e($row['job_number']) . '</a>' : e($row['job_number']) . (!empty($row['job_deleted']) ? ' <span class="badge text-bg-secondary">job deleted</span>' : '') ?></td>
                    <td><?= e($row['customer_name']) ?><?php if (!empty($row['customer_phone'])): ?><div class="jt-subtle"><?= tel_link($row['customer_phone']) ?></div><?php endif; ?></td>
                    <td><?= e(fmt_date($row['job_date']) ?: '—') ?></td>
                    <td><?= e($row['from_location'] ?: '—') ?></td>
                    <td><?= e($row['bh'] ?: '—') ?></td>
                    <?php if ($showMoney): foreach ($cols as $field => $label): ?>
                        <td class="text-end jt-money"><?= ($row[$field] ?? null) === null ? '—' : e(fmt_money($row[$field])) ?></td>
                    <?php endforeach; endif; ?>
                    <td><?= e($row['remark'] ?: '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if ($showMoney && $trip['totals'] !== null): ?>
                <tfoot>
                <tr class="fw-semibold">
                    <td colspan="5" class="text-end">Totals</td>
                    <?php foreach ($cols as $field => $label): ?><td class="text-end jt-money"><?= e(fmt_money($trip['totals'][$field])) ?></td><?php endforeach; ?>
                    <td></td>
                </tr>
                <tr class="fw-semibold">
                    <td colspan="5" class="text-end">Total expenses <span class="jt-subtle fw-normal">(sum of the six columns)</span></td>
                    <td colspan="<?= e(count($cols)) ?>" class="text-end jt-money"><?= e(fmt_money($trip['totals']['total'])) ?></td>
                    <td></td>
                </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
    <?php endif; ?>
</div>
