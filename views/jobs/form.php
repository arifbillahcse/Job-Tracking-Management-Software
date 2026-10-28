<?php
/**
 * Shared create / edit form.
 * @var array<string,mixed>|null $job  @var string $action  @var string $cancelTo  @var string $submitLabel  @var bool $editFinancials
 */
$val = static fn (string $key): string => old_or($key, $job[$key] ?? '');
$input = static function (string $name, string $label, array $opts = []) use ($val): string {
    $type = $opts['type'] ?? 'text';
    $attrs = '';
    foreach (['maxlength', 'min', 'max', 'step', 'inputmode', 'placeholder'] as $attr) {
        if (isset($opts[$attr])) {
            $attrs .= ' ' . $attr . '="' . e($opts[$attr]) . '"';
        }
    }
    $required = !empty($opts['required']);
    $help = isset($opts['help']) ? '<div class="form-text">' . e($opts['help']) . '</div>' : '';

    return '<label for="' . e($name) . '" class="form-label">' . e($label) . ($required ? ' <span class="text-danger" aria-hidden="true">*</span>' : '') . '</label>'
        . '<input type="' . e($type) . '" class="form-control' . (has_error($name) ? ' is-invalid' : '') . '" id="' . e($name) . '" name="' . e($name) . '"'
        . ' value="' . e($val($name)) . '"' . $attrs . ($required ? ' required' : '') . '>'
        . $help . error_text($name);
};
?>
<form method="post" action="<?= e(url($action)) ?>" novalidate data-loading data-unsaved>
    <?= csrf_field() ?>
    <?php if ($job !== null): ?>
        <input type="hidden" name="loaded_at" value="<?= e($job['updated_at']) ?>">
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-12 col-xl-8">
            <div class="card jt-card mb-4">
                <div class="card-header bg-transparent fw-semibold">Job &amp; customer</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-4"><?= $input('job_number', 'Job #', ['required' => true, 'maxlength' => 40, 'help' => 'Letters, numbers and - _ / . only. Must be unique.']) ?></div>
                        <div class="col-12 col-md-4"><?= $input('customer_first_name', 'Customer first name', ['required' => true, 'maxlength' => 100]) ?></div>
                        <div class="col-12 col-md-4"><?= $input('customer_last_name', 'Customer last name', ['required' => true, 'maxlength' => 100]) ?></div>
                        <?php if (!empty($canAssign)): ?>
                            <div class="col-12 col-md-4">
                                <label for="trip_id" class="form-label">Trip</label>
                                <select class="form-select<?= has_error('trip_id') ? ' is-invalid' : '' ?>" id="trip_id" name="trip_id" aria-describedby="trip_id-help">
                                    <option value="">No trip</option>
                                    <?php foreach ($trips as $t): ?>
                                        <option value="<?= e($t['id']) ?>"<?= $val('trip_id') === (string) $t['id'] ? ' selected' : '' ?>><?= e($t['trip_number']) ?> &middot; <?= e($t['foreman_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text" id="trip_id-help">The job is added to the trip and gets its foreman.</div>
                                <?= error_text('trip_id') ?>
                            </div>
                            <div class="col-12 col-md-4">
                                <label for="foreman_id" class="form-label">Foreman</label>
                                <select class="form-select<?= has_error('foreman_id') ? ' is-invalid' : '' ?>" id="foreman_id" name="foreman_id" aria-describedby="foreman_id-help">
                                    <option value="">No foreman</option>
                                    <?php foreach ($foremen as $f): ?>
                                        <option value="<?= e($f['id']) ?>"<?= $val('foreman_id') === (string) $f['id'] ? ' selected' : '' ?>><?= e($f['name']) ?><?= (int) $f['active'] === 1 ? '' : ' (inactive)' ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text" id="foreman_id-help">If a trip is chosen, its foreman is used.</div>
                                <?= error_text('foreman_id') ?>
                            </div>
                        <?php elseif ($job !== null && (!empty($job['foreman']) || !empty($job['trip_number']))): ?>
                            <div class="col-12 col-md-8">
                                <div class="form-label">Foreman and trip</div>
                                <div class="form-control-plaintext"><?= e(trim(($job['foreman'] ?? '') . (!empty($job['trip_number']) ? ' · trip ' . $job['trip_number'] : ''))) ?></div>
                                <div class="form-text">Changed under Trips (you are not allowed to manage trips).</div>
                            </div>
                        <?php endif; ?>
                        <div class="col-12 col-md-4"><?= $input('job_lot', 'Job lot', ['maxlength' => 60]) ?></div>
                        <div class="col-12 col-md-4"><?= $input('color', 'Color', ['maxlength' => 60]) ?></div>
                        <div class="col-12 col-md-4"><?= $input('customer_phone', 'Customer phone number', ['type' => 'tel', 'maxlength' => 30, 'inputmode' => 'tel', 'placeholder' => '(555) 010-2231', 'help' => 'Shown as a tap-to-call link on phones.']) ?></div>
                    </div>
                </div>
            </div>

            <div class="card jt-card mb-4">
                <div class="card-header bg-transparent fw-semibold">Route &amp; dates</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6"><?= $input('from_location', 'From', ['required' => true, 'maxlength' => 190]) ?></div>
                        <div class="col-12 col-md-6"><?= $input('to_location', 'To', ['required' => true, 'maxlength' => 190]) ?></div>
                        <div class="col-12"><?= $input('job_location', 'Job location', ['maxlength' => 190, 'help' => 'Where the job is currently held, e.g. a warehouse or storage site.']) ?></div>
                        <div class="col-12 col-md-6"><?= $input('pickup_date', 'Pickup date', ['type' => 'date']) ?></div>
                        <div class="col-12 col-md-6"><?= $input('first_available_date', '1st available date', ['type' => 'date', 'required' => true, 'help' => 'The first day the customer can accept delivery.']) ?></div>
                    </div>
                </div>
            </div>

            <div class="card jt-card mb-4">
                <div class="card-header bg-transparent fw-semibold">Notes</div>
                <div class="card-body">
                    <label for="notes" class="form-label visually-hidden">Notes</label>
                    <textarea class="form-control<?= has_error('notes') ? ' is-invalid' : '' ?>" id="notes" name="notes" rows="4" maxlength="5000"><?= e($val('notes')) ?></textarea>
                    <?= error_text('notes') ?>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-4">
            <div class="card jt-card mb-4">
                <div class="card-header bg-transparent fw-semibold">Cargo<?= $editFinancials ? ' &amp; money' : '' ?></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12"><?= $input('estimated_cf', 'E-CF (estimated cubic feet)', ['type' => 'number', 'step' => '0.01', 'min' => '0', 'inputmode' => 'decimal']) ?></div>
                        <div class="col-12"><?= $input('cf', 'A-CF (actual cubic feet)', ['type' => 'number', 'step' => '0.01', 'min' => '0', 'inputmode' => 'decimal', 'help' => 'The storage fee is calculated from this.']) ?></div>
                        <?php if ($editFinancials): ?>
                            <?php foreach (['estimated_total' => 'E-Total', 'deposit' => 'Deposit', 'grand_total' => 'Grand total', 'bh_total' => 'BH total', 'paid' => 'Paid'] as $name => $label): ?>
                                <div class="col-12 col-sm-6 col-xl-12"><?= $input($name, $label, ['type' => 'number', 'step' => '0.01', 'min' => '0', 'inputmode' => 'decimal']) ?></div>
                            <?php endforeach; ?>
                            <div class="col-12 col-sm-6 col-xl-12"><?= $input('balance', 'Balance', ['type' => 'number', 'step' => '0.01', 'min' => '0', 'inputmode' => 'decimal', 'help' => 'Amount the customer still owes.']) ?></div>
                            <div class="col-12 col-sm-6 col-xl-12">
                                <?= $input('storage_fee_override', 'Storage fee override', ['type' => 'number', 'step' => '0.01', 'min' => '0', 'inputmode' => 'decimal', 'help' => 'Leave empty for the automatic fee (CF × rate after ' . \App\Services\StorageFeeService::days() . ' days). A number here replaces it.']) ?>
                                <?php if ($job !== null): ?>
                                    <div class="jt-subtle mt-1" aria-live="polite">Now: <?= e(fmt_money($job['storage_fee'] ?? 0)) ?>. <?= e(storage_fee_note($job)) ?>.</div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($job !== null && (string) $job['status'] === 'in_transit'): ?>
                <div class="card jt-card mb-4">
                    <div class="card-header bg-transparent fw-semibold">Carrier</div>
                    <div class="card-body">
                        <p class="small text-body-secondary">Optional. Record who is carrying the job, or correct it.</p>
                        <div class="row g-3">
                            <div class="col-12"><?= $input('carrier_name', 'Carrier name', ['maxlength' => 150]) ?></div>
                            <div class="col-12 col-sm-6 col-xl-12"><?= $input('driver_name', 'Driver name', ['maxlength' => 150]) ?></div>
                            <div class="col-12 col-sm-6 col-xl-12"><?= $input('driver_phone', 'Driver phone number', ['type' => 'tel', 'maxlength' => 30, 'inputmode' => 'tel']) ?></div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($job !== null && in_array((string) $job['status'], ['in_transit', 'delivered'], true)): ?>
                <div class="card jt-card mb-4">
                    <div class="card-header bg-transparent fw-semibold">Dates</div>
                    <div class="card-body">
                        <p class="small text-body-secondary">Set automatically to the day of the click. Correct them here if the click was late.</p>
                        <div class="row g-3">
                            <div class="col-12 col-sm-6 col-xl-12"><?= $input('loaded_date', 'Loaded date', ['type' => 'date', 'max' => \App\Core\Clock::today()]) ?></div>
                            <?php if ((string) $job['status'] === 'delivered'): ?>
                                <div class="col-12 col-sm-6 col-xl-12"><?= $input('delivery_date', 'Delivery date', ['type' => 'date', 'required' => true, 'max' => \App\Core\Clock::today()]) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="d-flex gap-2 jt-form-actions">
                <a class="btn btn-outline-secondary" href="<?= e(url($cancelTo)) ?>">Cancel</a>
                <button type="submit" class="btn btn-primary flex-grow-1 flex-md-grow-0"><?= e($submitLabel) ?></button>
            </div>
        </div>
    </div>
</form>
