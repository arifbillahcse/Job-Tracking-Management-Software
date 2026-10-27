<?php
/** @var array<string,string> $values  @var array<int,string> $timezones  @var array<int,string> $pageSizes */
$val = static fn (string $key): string => old_or($key, $values[$key] ?? '');
?>
<div class="row">
    <div class="col-12 col-xl-7">
        <form class="card jt-card" method="post" action="<?= e(url('/settings')) ?>" novalidate data-loading data-unsaved>
            <?= csrf_field() ?>
            <div class="card-header bg-transparent fw-semibold">Application settings</div>
            <div class="card-body">
                <div class="mb-3">
                    <label for="company_name" class="form-label">Company name <span class="text-danger" aria-hidden="true">*</span></label>
                    <input type="text" class="form-control<?= has_error('company_name') ? ' is-invalid' : '' ?>" id="company_name" name="company_name" value="<?= e($val('company_name')) ?>" maxlength="60" required aria-describedby="company_name-help">
                    <div class="form-text" id="company_name-help">Shown in the menu, page titles and the sign-in page.</div>
                    <?= error_text('company_name') ?>
                </div>

                <div class="mb-3">
                    <label for="ready_threshold_days" class="form-label">Ready-for-delivery limit (days) <span class="text-danger" aria-hidden="true">*</span></label>
                    <input type="number" class="form-control<?= has_error('ready_threshold_days') ? ' is-invalid' : '' ?>" id="ready_threshold_days" name="ready_threshold_days" value="<?= e($val('ready_threshold_days')) ?>" min="1" max="365" step="1" required inputmode="numeric" aria-describedby="ready_threshold_days-help">
                    <div class="form-text" id="ready_threshold_days-help">The number of days for the rule below. With the pickup rule, a booked job picked up longer ago than this is in <strong>More Than <?= e($val('ready_threshold_days')) ?> Days</strong>, otherwise <strong>Ready for Delivery</strong>. With the 1st-available-date rule, a job whose date is within this many days of today is Ready, and jobs further away, <strong>or that have already waited longer</strong>, are in <strong>More Than <?= e($val('ready_threshold_days')) ?> Days</strong>. Jobs move between sections automatically and immediately when this changes.</div>
                    <?= error_text('ready_threshold_days') ?>
                </div>

                <div class="mb-3">
                    <label for="section_basis" class="form-label">Count the days from <span class="text-danger" aria-hidden="true">*</span></label>
                    <select class="form-select<?= has_error('section_basis') ? ' is-invalid' : '' ?>" id="section_basis" name="section_basis" required aria-describedby="section_basis-help">
                        <?php foreach (\App\Services\JobBucket::BASES as $key => $label): ?>
                            <option value="<?= e($key) ?>"<?= $val('section_basis') === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text" id="section_basis-help"><strong>Pickup date</strong>: a booked job picked up more than the limit ago moves to More Than <?= e($val('ready_threshold_days')) ?> Days; jobs with no pickup date use their 1st available date. <strong>1st available date</strong>: the original rule (see above).</div>
                    <?= error_text('section_basis') ?>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-12 col-sm-4">
                        <label for="currency_symbol" class="form-label">Currency symbol <span class="text-danger" aria-hidden="true">*</span></label>
                        <input type="text" class="form-control<?= has_error('currency_symbol') ? ' is-invalid' : '' ?>" id="currency_symbol" name="currency_symbol" value="<?= e($val('currency_symbol')) ?>" maxlength="3" required>
                        <?= error_text('currency_symbol') ?>
                    </div>
                    <div class="col-12 col-sm-8">
                        <label for="page_size" class="form-label">Rows per page <span class="text-danger" aria-hidden="true">*</span></label>
                        <select class="form-select<?= has_error('page_size') ? ' is-invalid' : '' ?>" id="page_size" name="page_size" required>
                            <?php foreach ($pageSizes as $size): ?>
                                <option value="<?= e($size) ?>"<?= $val('page_size') === $size ? ' selected' : '' ?>><?= e($size) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= error_text('page_size') ?>
                    </div>
                </div>

                <div class="mb-0">
                    <label for="timezone" class="form-label">Business time zone <span class="text-danger" aria-hidden="true">*</span></label>
                    <select class="form-select<?= has_error('timezone') ? ' is-invalid' : '' ?>" id="timezone" name="timezone" required aria-describedby="timezone-help">
                        <?php foreach ($timezones as $zone): ?>
                            <option value="<?= e($zone) ?>"<?= $val('timezone') === $zone ? ' selected' : '' ?>><?= e($zone) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text" id="timezone-help">Decides what "today" means for sections, overdue flags and displayed times.</div>
                    <?= error_text('timezone') ?>
                </div>
            </div>
            <div class="card-header bg-transparent fw-semibold border-top" id="storage-fee-heading">Automatic storage fee</div>
            <div class="card-body" role="group" aria-labelledby="storage-fee-heading">
                <p class="text-body-secondary small">Storage fee = <strong>A-CF &times; rate</strong>, charged once a job has waited <strong>more than</strong> the number of days below. With <em>every month</em>, the same amount is added for each further 30 days with no prorating (part of a month counts as a full month). For example, CF 100 &times; rate 0.6 = 60 for the first month, 120 after two months. Open jobs are re-checked every day and whenever you change these settings; a delivered job keeps the fee it had on its delivery date.</p>
                <div class="row g-3">
                    <div class="col-12 col-sm-4">
                        <label for="storage_fee_rate" class="form-label">Rate <span class="text-danger" aria-hidden="true">*</span></label>
                        <input type="text" inputmode="decimal" class="form-control<?= has_error('storage_fee_rate') ? ' is-invalid' : '' ?>" id="storage_fee_rate" name="storage_fee_rate" value="<?= e($val('storage_fee_rate')) ?>" maxlength="10" required aria-describedby="storage_fee_rate-help">
                        <div class="form-text" id="storage_fee_rate-help">Dollars per cubic foot, e.g. <code>0.6</code>, <strong>or cents</strong>, e.g. <code>60c</code>. Now: <?= e(rtrim(rtrim(number_format((float) $values['storage_fee_rate'] * 100, 2, '.', ''), '0'), '.')) ?> cents per CF. <code>0</code> turns it off.</div>
                        <?= error_text('storage_fee_rate') ?>
                    </div>
                    <div class="col-12 col-sm-4">
                        <label for="storage_fee_after_days" class="form-label">After (days) <span class="text-danger" aria-hidden="true">*</span></label>
                        <input type="number" class="form-control<?= has_error('storage_fee_after_days') ? ' is-invalid' : '' ?>" id="storage_fee_after_days" name="storage_fee_after_days" value="<?= e($val('storage_fee_after_days')) ?>" min="0" max="3650" step="1" required inputmode="numeric">
                        <?= error_text('storage_fee_after_days') ?>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label for="storage_fee_mode" class="form-label">Charged <span class="text-danger" aria-hidden="true">*</span></label>
                        <select class="form-select<?= has_error('storage_fee_mode') ? ' is-invalid' : '' ?>" id="storage_fee_mode" name="storage_fee_mode" required>
                            <?php foreach (\App\Services\StorageFeeService::MODES as $key => $label): ?>
                                <option value="<?= e($key) ?>"<?= $val('storage_fee_mode') === $key ? ' selected' : '' ?>><?= e(ucfirst($label)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= error_text('storage_fee_mode') ?>
                    </div>
                    <div class="col-12 col-sm-4">
                        <label for="storage_fee_basis" class="form-label">Counted from <span class="text-danger" aria-hidden="true">*</span></label>
                        <select class="form-select<?= has_error('storage_fee_basis') ? ' is-invalid' : '' ?>" id="storage_fee_basis" name="storage_fee_basis" required>
                            <?php foreach (\App\Services\StorageFeeService::BASIS as $key => $label): ?>
                                <option value="<?= e($key) ?>"<?= $val('storage_fee_basis') === $key ? ' selected' : '' ?>><?= e(ucfirst($label)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= error_text('storage_fee_basis') ?>
                    </div>
                </div>
                <p class="form-text mt-2 mb-0">A fee typed in by hand on a job (&ldquo;Storage fee override&rdquo;) always wins over the automatic one.</p>
            </div>
            <div class="card-footer bg-transparent d-flex gap-2">
                <button type="submit" class="btn btn-primary">Save settings</button>
                <a class="btn btn-outline-secondary" href="<?= e(url('/settings')) ?>">Reset</a>
            </div>
        </form>
    </div>
    <div class="col-12 col-xl-5 mt-3 mt-xl-0">
        <div class="card jt-card">
            <div class="card-header bg-transparent fw-semibold d-flex align-items-center gap-2"><?= icon('info') ?> Good to know</div>
            <div class="card-body small text-body-secondary">
                <ul class="mb-0 ps-3">
                    <li>Every change is recorded in the <?= can('audit.view') ? '<a href="' . e(url('/audit')) . '">audit log</a>' : 'audit log' ?> with the old and new value.</li>
                    <li>Session length, sign-in lockout and password rules are server configuration (<code>.env</code>), not settings, so a mistake here cannot lock people out.</li>
                    <li>Storage fees stay manually editable on each job; nothing is calculated from these settings.</li>
                </ul>
            </div>
        </div>
    </div>
</div>
