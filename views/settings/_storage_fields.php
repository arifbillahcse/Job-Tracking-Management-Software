<?php
/** The automatic storage fee fields. @var array<string,string> $values  @var callable $val */
?>
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
