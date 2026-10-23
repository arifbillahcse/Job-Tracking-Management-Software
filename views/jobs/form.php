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
                        <div class="col-12"><?= $input('cf', 'CF (cubic feet)', ['type' => 'number', 'step' => '0.01', 'min' => '0', 'inputmode' => 'decimal']) ?></div>
                        <?php if ($editFinancials): ?>
                            <div class="col-12 col-sm-6 col-xl-12"><?= $input('balance', 'Balance', ['type' => 'number', 'step' => '0.01', 'min' => '0', 'inputmode' => 'decimal', 'help' => 'Amount the customer still owes.']) ?></div>
                            <div class="col-12 col-sm-6 col-xl-12"><?= $input('storage_fee', 'Storage fee', ['type' => 'number', 'step' => '0.01', 'min' => '0', 'inputmode' => 'decimal', 'help' => 'Entered manually.']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($job !== null && (string) $job['status'] === 'in_transit'): ?>
                <div class="card jt-card mb-4">
                    <div class="card-header bg-transparent fw-semibold">Carrier</div>
                    <div class="card-body">
                        <p class="small text-body-secondary">Correct the carrier details of a job that is in transit.</p>
                        <div class="row g-3">
                            <div class="col-12"><?= $input('carrier_name', 'Carrier name', ['required' => true, 'maxlength' => 150]) ?></div>
                            <div class="col-12 col-sm-6 col-xl-12"><?= $input('driver_name', 'Driver name', ['required' => true, 'maxlength' => 150]) ?></div>
                            <div class="col-12 col-sm-6 col-xl-12"><?= $input('driver_phone', 'Phone number', ['type' => 'tel', 'required' => true, 'maxlength' => 30, 'inputmode' => 'tel']) ?></div>
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
