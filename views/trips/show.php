<?php
/** @var array<string,mixed> $trip  @var bool $showMoney */
$sheet = ['addio' => 'Addio', 'total_to_bh' => 'Total to BH'];
$cols = ['diesel' => 'Diesel', 'hotels' => 'Hotels', 'helper' => 'Helpers', 'misc' => 'MISC', 'commission' => 'Commission', 'extra' => 'Extra'];
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
            <?php if (!empty($trip['notes'])): ?><?= $dl('Notes', nl2br(e($trip['notes']))) ?><?php endif; ?>
        </dl>
    </div>
</div>

<?php if (can('trips.manage')): ?>
    <details class="card jt-card mb-4" id="add-entry"<?= $trip['rows'] === [] ? ' open' : '' ?>>
        <summary class="card-header bg-transparent fw-semibold d-flex align-items-center gap-2"><?= icon('plus') ?> Add daily entry
            <span class="jt-subtle fw-normal">an existing job, one day, with that day's expenses</span></summary>
        <form class="card-body" method="post" action="<?= e(url('/trips/' . $trip['id'] . '/jobs')) ?>">
            <?= csrf_field() ?>
            <div class="row g-2">
                <div class="col-6 col-md-3"><label class="form-label small" for="add-job-number">Job #</label><input type="text" class="form-control form-control-sm" id="add-job-number" name="job_number" maxlength="40" required></div>
                <div class="col-6 col-md-3"><label class="form-label small" for="add-job-date">Date</label><input type="date" class="form-control form-control-sm" id="add-job-date" name="job_date" value="<?= e(\App\Core\Clock::today()) ?>"></div>
                <div class="col-6 col-md-3">
                    <label class="form-label small" for="add-job-activity">Activity</label>
                    <select class="form-select form-select-sm" id="add-job-activity" name="activity">
                        <option value="">&mdash;</option>
                        <?php foreach (\App\Services\TripService::ACTIVITIES as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3"><label class="form-label small" for="add-from">From</label><input type="text" class="form-control form-control-sm" id="add-from" name="from_location" maxlength="190" placeholder="job's From"></div>
                <div class="col-6 col-md-3"><label class="form-label small" for="add-to">To</label><input type="text" class="form-control form-control-sm" id="add-to" name="to_location" maxlength="190" placeholder="job's To"></div>
                <div class="col-6 col-md-3"><label class="form-label small" for="add-bh">BH</label><input type="text" class="form-control form-control-sm" id="add-bh" name="bh" maxlength="100"></div>
                <?php if ($showMoney): ?><div class="col-6 col-md-3"><label class="form-label small" for="add-foreman_pay">Foreman</label><input type="number" class="form-control form-control-sm" id="add-foreman_pay" name="foreman_pay" step="0.01" min="0" inputmode="decimal"></div><?php endif; ?>
                <?php if ($showMoney): ?>
                    <?php foreach (['addio' => 'Addio', 'total_to_bh' => 'Total to BH', 'diesel' => 'Diesel', 'hotels' => 'Hotels', 'helper' => 'Helpers', 'misc' => 'MISC', 'commission' => 'Commission', 'extra' => 'Extra'] as $name => $label): ?>
                        <div class="col-6 col-md-3">
                            <label class="form-label small" for="add-<?= e($name) ?>"><?= e($label) ?></label>
                            <input type="number" class="form-control form-control-sm" id="add-<?= e($name) ?>" name="<?= e($name) ?>" step="0.01" min="0" inputmode="decimal"<?= $name === 'total_to_bh' ? ' placeholder="from the job"' : '' ?>>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <div class="col-12 col-md-6"><label class="form-label small" for="add-remark">Remarks</label><input type="text" class="form-control form-control-sm" id="add-remark" name="remark" maxlength="500"></div>
                <div class="col-12 col-md-3 d-flex align-items-end"><button type="submit" class="btn btn-primary w-100">Add daily entry</button></div>
            </div>
            <div class="form-text mt-2">One entry per job per day: the same Job # can be added again on another date. Each entry keeps its own expenses, and all of them are in the trip's total. This never creates a job.</div>
        </form>
    </details>
<?php endif; ?>

<div class="card jt-card">
    <div class="card-header bg-transparent fw-semibold d-flex flex-wrap align-items-center gap-2">
        <span>Jobs on this trip (<?= e(count($trip['rows'])) ?>)</span>
        <?php if (can('trips.manage')): ?>
            <a class="ms-auto btn btn-sm btn-primary fw-normal" href="#add-entry" data-open-details="#add-entry"><?= icon('plus') ?> Add daily entry</a>
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
                <th scope="col">Job #</th><th scope="col">Customer</th><th scope="col">Date</th><th scope="col">Activity</th><th scope="col">From</th><th scope="col">To</th>
                <?php if ($showMoney): foreach ($sheet as $label): ?><th scope="col" class="text-end"><?= e($label) ?></th><?php endforeach; endif; ?>
                <th scope="col">BH</th><th scope="col">Foreman</th>
                <?php if ($showMoney): foreach ($cols as $label): ?><th scope="col" class="text-end"><?= e($label) ?></th><?php endforeach; endif; ?>
                <th scope="col">Remarks</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($trip['rows'] as $row): ?>
                <tr>
                    <td><?= can('jobs.view') && empty($row['job_deleted']) ? '<a class="jt-jobno" href="' . e(url('/jobs/' . $row['job_id'])) . '">' . e($row['job_number']) . '</a>' : e($row['job_number']) . (!empty($row['job_deleted']) ? ' <span class="badge text-bg-secondary">job deleted</span>' : '') ?></td>
                    <td><?= e($row['customer_name']) ?><?php if (!empty($row['customer_phone'])): ?><div class="jt-subtle"><?= tel_link($row['customer_phone']) ?></div><?php endif; ?></td>
                    <td><?= e(fmt_date($row['job_date']) ?: '—') ?></td>
                    <td><?= e(\App\Services\TripService::ACTIVITIES[$row['activity'] ?? ''] ?? '—') ?></td>
                    <td><?= e($row['from_location'] ?: '—') ?></td>
                    <td><?= e($row['to_location'] ?: '—') ?></td>
                    <?php if ($showMoney): foreach ($sheet as $field => $label): ?>
                        <td class="text-end jt-money"<?= $field === 'total_to_bh' && !empty($row['total_to_bh_from_job']) ? ' title="From the job\'s Total to BH"' : '' ?>><?= ($row[$field] ?? null) === null || $row[$field] === '' ? '—' : e(fmt_money($row[$field])) ?></td>
                    <?php endforeach; endif; ?>
                    <td><?= e($row['bh'] ?: '—') ?></td>
                    <td class="text-end jt-money"><?= !$showMoney || ($row['foreman_pay'] ?? null) === null || $row['foreman_pay'] === '' ? '—' : e(fmt_money($row['foreman_pay'])) ?></td>
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
                    <td colspan="6" class="text-end">Totals</td>
                    <?php $st = $trip['sheet_totals'] ?? []; foreach ($sheet as $field => $label): ?><td class="text-end jt-money"><?= e(fmt_money($st[$field] ?? '0')) ?></td><?php endforeach; ?>
                    <td class="text-end jt-money"><?= e(fmt_money($st['bh'] ?? '0')) ?></td>
                    <td class="text-end jt-money"><?= e(fmt_money($st['foreman_pay'] ?? '0')) ?></td>
                    <?php foreach ($cols as $field => $label): ?><td class="text-end jt-money"><?= e(fmt_money($trip['totals'][$field])) ?></td><?php endforeach; ?>
                    <td></td>
                </tr>
                <tr class="fw-semibold">
                    <td colspan="<?= e(8 + ($showMoney ? count($sheet) : 0)) ?>" class="text-end">Total expenses <span class="jt-subtle fw-normal">(sum of the six columns)</span></td>
                    <td colspan="<?= e(count($cols)) ?>" class="text-end jt-money"><?= e(fmt_money($trip['totals']['total'])) ?></td>
                    <td></td>
                </tr>
                <?php if (!empty($trip['sheet_totals'])): ?>
                <tr>
                    <td colspan="<?= e(8 + count($sheet) + count($cols)) ?>" class="text-end jt-subtle">All expenses as on the closing sheet (Diesel + Hotels + Helpers + MISC): <strong><?= e(fmt_money($trip['sheet_totals']['all_exp'])) ?></strong> &middot; Paid to foreman (Foreman column): <strong><?= e(fmt_money($trip['sheet_totals']['foreman_pay'])) ?></strong></td>
                    <td></td>
                </tr>
                <?php endif; ?>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
    <?php endif; ?>
</div>
