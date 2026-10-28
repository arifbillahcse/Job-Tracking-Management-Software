<?php
/**
 * Create / edit a trip: header plus a table of job rows (five spare blank rows; blank rows are ignored on save).
 * @var array<string,mixed>|null $trip  @var string $action  @var string $cancelTo  @var string $submitLabel
 * @var array<int,array<string,mixed>> $foremen  @var bool $editMoney  @var string $prefillJob job number to put in the first row ("New trip with this job")
 */
$val = static fn (string $key): string => old_or($key, $trip[$key] ?? '');
$money = ['diesel' => 'Diesel', 'hotels' => 'Hotels', 'helper' => 'Helper', 'misc' => 'Misc', 'commission' => 'Commission', 'extra' => 'Extra'];

// Rows: what the user typed (after a validation error), else the saved rows; then spare rows.
$old = \App\Core\Session::flashed('old', []);
$rows = is_array($old) && isset($old['rows']) && is_array($old['rows']) ? array_values(array_filter($old['rows'], 'is_array')) : ($trip['rows'] ?? []);
if ($rows === [] && $prefillJob !== '') {
    $rows[] = ['job_number' => $prefillJob];
}
$existing = count($rows);
for ($i = 0; $i < 5; $i++) {
    $rows[] = [];
}
$cell = static function (int $i, string $field, array $row, array $attrs = []): string {
    $html = '';
    foreach ($attrs as $k => $v) {
        $html .= ' ' . $k . '="' . e($v) . '"';
    }
    $name = 'rows[' . $i . '][' . $field . ']';
    $bad = has_error('rows.' . $i . '.' . $field);
    return '<input class="form-control form-control-sm' . ($bad ? ' is-invalid' : '') . '" name="' . e($name) . '" value="' . e((string) ($row[$field] ?? '')) . '"' . $html . ' aria-label="' . e(ucfirst(str_replace('_', ' ', $field)) . ' row ' . ($i + 1)) . '">';
};
?>
<form method="post" action="<?= e(url($action)) ?>" novalidate data-loading data-unsaved>
    <?= csrf_field() ?>
    <?php if ($trip !== null): ?><input type="hidden" name="loaded_at" value="<?= e($trip['updated_at']) ?>"><?php endif; ?>

    <div class="card jt-card mb-4">
        <div class="card-header bg-transparent fw-semibold">Trip</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label for="trip_number" class="form-label">Trip number <span class="text-danger" aria-hidden="true">*</span></label>
                    <input type="text" class="form-control<?= has_error('trip_number') ? ' is-invalid' : '' ?>" id="trip_number" name="trip_number" value="<?= e($val('trip_number')) ?>" maxlength="40" required>
                    <div class="form-text">Letters, numbers and - _ / . only. Must be unique.</div>
                    <?= error_text('trip_number') ?>
                </div>
                <div class="col-12 col-md-4">
                    <label for="foreman_id" class="form-label">Foreman <span class="text-danger" aria-hidden="true">*</span></label>
                    <select class="form-select<?= has_error('foreman_id') ? ' is-invalid' : '' ?>" id="foreman_id" name="foreman_id" required>
                        <option value="">Choose a foreman</option>
                        <?php foreach ($foremen as $f): ?>
                            <option value="<?= e($f['id']) ?>"<?= $val('foreman_id') === (string) $f['id'] ? ' selected' : '' ?>><?= e($f['name']) ?><?= (int) $f['active'] === 1 ? '' : ' (inactive)' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (can('foremen.manage')): ?><div class="form-text"><a href="<?= e(url('/foremen/create')) ?>">Add a foreman</a> first if he is not listed.</div><?php endif; ?>
                    <?= error_text('foreman_id') ?>
                </div>
                <div class="col-12 col-md-4">
                    <label for="collected_by" class="form-label">Collected by</label>
                    <input type="text" class="form-control<?= has_error('collected_by') ? ' is-invalid' : '' ?>" id="collected_by" name="collected_by" value="<?= e($val('collected_by')) ?>" maxlength="150">
                    <?= error_text('collected_by') ?>
                </div>
                <div class="col-6 col-md-4">
                    <label for="begins_on" class="form-label">Trip begins</label>
                    <input type="date" class="form-control<?= has_error('begins_on') ? ' is-invalid' : '' ?>" id="begins_on" name="begins_on" value="<?= e($val('begins_on')) ?>">
                    <?= error_text('begins_on') ?>
                </div>
                <div class="col-6 col-md-4">
                    <label for="ends_on" class="form-label">Trip ends</label>
                    <input type="date" class="form-control<?= has_error('ends_on') ? ' is-invalid' : '' ?>" id="ends_on" name="ends_on" value="<?= e($val('ends_on')) ?>">
                    <?= error_text('ends_on') ?>
                </div>
                <div class="col-12">
                    <label for="notes" class="form-label">Notes</label>
                    <textarea class="form-control<?= has_error('notes') ? ' is-invalid' : '' ?>" id="notes" name="notes" rows="2" maxlength="5000"><?= e($val('notes')) ?></textarea>
                    <?= error_text('notes') ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card jt-card mb-4">
        <div class="card-header bg-transparent fw-semibold">Jobs on this trip</div>
        <div class="card-body pb-0">
            <p class="small text-body-secondary mb-2">Type the job number of each job the foreman picked up; the customer and &ldquo;from&rdquo; are filled in from the job when you save. Spare rows can stay empty.<?= $editMoney ? ' Amounts are saved exactly as you type them.' : ' You are not allowed to see or change the expense amounts.' ?></p>
            <?= error_text('rows') ?>
        </div>
        <div class="table-responsive">
            <table class="table align-middle mb-0 jt-table">
                <thead>
                <tr>
                    <th scope="col" class="jt-w9">Job #</th><th scope="col" class="jt-w9">Date</th><th scope="col" class="jt-w6">BH</th>
                    <?php if ($editMoney): foreach ($money as $label): ?><th scope="col" class="jt-w65"><?= e($label) ?></th><?php endforeach; endif; ?>
                    <th scope="col" class="jt-w12">Remark</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $i => $row): ?>
                    <tr>
                        <td>
                            <?php if (!empty($row['id'])): ?><input type="hidden" name="rows[<?= e($i) ?>][id]" value="<?= e($row['id']) ?>"><?php endif; ?>
                            <?= $cell($i, 'job_number', $row, ['maxlength' => 40]) ?>
                            <?= error_text('rows.' . $i . '.job_number') ?>
                            <?php if (!empty($row['customer_name'])): ?><div class="jt-subtle"><?= e($row['customer_name']) ?><?= !empty($row['from_location']) ? ' · ' . e($row['from_location']) : '' ?></div><?php endif; ?>
                        </td>
                        <td><?= $cell($i, 'job_date', $row, ['type' => 'date']) ?><?= error_text('rows.' . $i . '.job_date') ?></td>
                        <td><?= $cell($i, 'bh', $row, ['maxlength' => 100]) ?></td>
                        <?php if ($editMoney): foreach ($money as $field => $label): ?>
                            <td><?= $cell($i, $field, $row, ['type' => 'number', 'step' => '0.01', 'min' => '0', 'inputmode' => 'decimal']) ?><?= error_text('rows.' . $i . '.' . $field) ?></td>
                        <?php endforeach; endif; ?>
                        <td><?= $cell($i, 'remark', $row, ['maxlength' => 500]) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="d-flex gap-2 jt-form-actions">
        <a class="btn btn-outline-secondary" href="<?= e(url($cancelTo)) ?>">Cancel</a>
        <button type="submit" class="btn btn-primary"><?= e($submitLabel) ?></button>
    </div>
</form>
