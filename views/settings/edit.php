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
            <?= \App\Core\View::partial('settings/_storage_fields', ['values' => $values, 'val' => $val]) ?>
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
                    <li>Storage fees are calculated automatically from the storage fee settings (rate, days, month rule). A fee typed on a job always wins over the calculation.</li>
                </ul>
            </div>
        </div>
    </div>
</div>
