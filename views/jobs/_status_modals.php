<?php
/**
 * One dialog per status change the user may perform. All post to /jobs/{id}/status with a hidden "to".
 * @var array<string,mixed> $job  @var array<int,array<string,mixed>> $transitions  @var string|null $autoOpen
 */
$failed = \App\Core\Session::flashed('open_modal'); // the dialog the user was filling in when validation failed
$value = static fn (string $to, string $field, mixed $fallback = ''): string => $failed === $to ? old_or($field, $fallback) : (string) $fallback;
$error = static fn (string $to, string $field): string => $failed === $to ? error_text($field) : '';
$invalid = static fn (string $to, string $field): string => $failed === $to && has_error($field) ? ' is-invalid' : '';
?>
<?php foreach ($transitions as $t):
    if ($t['form'] === 'none') {
        continue; // one-click changes have no dialog
    }
    $to = (string) $t['to'];
    $required = $t['note'] === 'required';
    $modalId = 'statusModal-' . $to;
    $confirmClass = str_contains((string) $t['style'], 'danger') ? 'danger' : 'primary';
    ?>
    <div class="modal fade" id="<?= e($modalId) ?>" tabindex="-1" aria-labelledby="<?= e($modalId) ?>Label" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="post" action="<?= e(url('/jobs/' . $job['id'] . '/status')) ?>" novalidate data-loading>
                <?= csrf_field() ?>
                <input type="hidden" name="to" value="<?= e($to) ?>">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="<?= e($modalId) ?>Label"><?= e($t['label']) ?> &middot; <?= e($job['job_number']) ?></h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php if ($t['form'] === 'dispatch'): ?>
                        <p class="text-body-secondary">The job moves to <strong>In Transit</strong>. All three carrier details are required.</p>
                        <div class="mb-3">
                            <label class="form-label" for="<?= e($modalId) ?>-carrier">Carrier name <span class="text-danger" aria-hidden="true">*</span></label>
                            <input type="text" class="form-control<?= $invalid($to, 'carrier_name') ?>" id="<?= e($modalId) ?>-carrier" name="carrier_name" maxlength="150" required
                                   value="<?= e($value($to, 'carrier_name', $job['carrier_name'] ?? '')) ?>">
                            <?= $error($to, 'carrier_name') ?>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-12 col-sm-6">
                                <label class="form-label" for="<?= e($modalId) ?>-driver">Driver name <span class="text-danger" aria-hidden="true">*</span></label>
                                <input type="text" class="form-control<?= $invalid($to, 'driver_name') ?>" id="<?= e($modalId) ?>-driver" name="driver_name" maxlength="150" required
                                       value="<?= e($value($to, 'driver_name', $job['driver_name'] ?? '')) ?>">
                                <?= $error($to, 'driver_name') ?>
                            </div>
                            <div class="col-12 col-sm-6">
                                <label class="form-label" for="<?= e($modalId) ?>-phone">Phone number <span class="text-danger" aria-hidden="true">*</span></label>
                                <input type="tel" class="form-control<?= $invalid($to, 'driver_phone') ?>" id="<?= e($modalId) ?>-phone" name="driver_phone" maxlength="30" required inputmode="tel"
                                       value="<?= e($value($to, 'driver_phone', $job['driver_phone'] ?? '')) ?>">
                                <?= $error($to, 'driver_phone') ?>
                            </div>
                        </div>
                    <?php elseif ($t['form'] === 'deliver'): ?>
                        <p class="text-body-secondary">The job moves to <strong>Delivered</strong>.</p>
                        <div class="mb-3">
                            <label class="form-label" for="<?= e($modalId) ?>-date">Delivery date <span class="text-danger" aria-hidden="true">*</span></label>
                            <input type="date" class="form-control<?= $invalid($to, 'delivered_on') ?>" id="<?= e($modalId) ?>-date" name="delivered_on" required
                                   max="<?= e(\App\Core\Clock::today()) ?>" value="<?= e($value($to, 'delivered_on', \App\Core\Clock::today())) ?>">
                            <?= $error($to, 'delivered_on') ?>
                        </div>
                    <?php elseif ($to === 'booked'): ?>
                        <p class="text-body-secondary">The job returns to <strong>Booked</strong>. Any carrier details are cleared (they stay in the history) and it has to be dispatched again.</p>
                    <?php endif; ?>

                    <label class="form-label" for="<?= e($modalId) ?>-note"><?= $required ? 'Reason' : 'Note (optional)' ?><?= $required ? ' <span class="text-danger" aria-hidden="true">*</span>' : '' ?></label>
                    <textarea class="form-control<?= $invalid($to, 'note') ?>" id="<?= e($modalId) ?>-note" name="note" rows="3" maxlength="1000"<?= $required ? ' required' : '' ?>><?= e($value($to, 'note')) ?></textarea>
                    <?= $error($to, 'note') ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-<?= e($confirmClass) ?>"><?= e($t['label']) ?></button>
                </div>
            </form>
        </div>
    </div>
<?php endforeach; ?>

<?php if (!empty($autoOpen)): ?>
    <span data-auto-open-modal="statusModal-<?= e($autoOpen) ?>" hidden></span>
<?php endif; ?>
