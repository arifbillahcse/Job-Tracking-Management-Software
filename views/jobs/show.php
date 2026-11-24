<?php
/**
 * @var array<string,mixed> $job  @var bool $showFinancials  @var array<int,array<string,mixed>> $transitions
 * @var array<int,array<string,mixed>> $timeline  @var string|null $autoOpen
 */
$dispatched = !empty($job['carrier_name']) || !empty($job['driver_name']) || !empty($job['driver_phone']);
$dl = static function (string $label, string $valueHtml): string {
    return '<dt class="col-5 col-sm-4 text-body-secondary fw-normal">' . e($label) . '</dt><dd class="col-7 col-sm-8">' . $valueHtml . '</dd>';
};
?>
<div class="card jt-card mb-4">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
        <div class="me-auto">
            <div class="text-body-secondary small">Job #</div>
            <div class="h3 mb-0 jt-jobno"><?= e($job['job_number']) ?></div>
        </div>
        <?= status_badge((string) $job['status']) ?>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-secondary" href="<?= e(url('/jobs')) ?>">Back to jobs</a>
            <?php if (can('jobs.edit')): ?>
                <a class="btn btn-primary" href="<?= e(url('/jobs/' . $job['id'] . '/edit')) ?>"><?= icon('edit') ?> Edit</a>
            <?php endif; ?>
            <?php if (can('jobs.delete')): ?>
                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteJobModal"><?= icon('trash') ?> Delete</button>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($transitions !== []): ?>
    <div class="card jt-card mb-4">
        <div class="card-body d-flex flex-wrap align-items-center gap-2">
            <span class="fw-semibold me-2">Update status</span>
            <?php foreach ($transitions as $t): ?>
                <?php if ($t['form'] === 'none'): ?>
                    <?= \App\Core\View::partial('jobs/_quick_move', ['job' => $job, 't' => $t, 'size' => '']) ?>
                <?php else: ?>
                    <button type="button" class="btn btn-<?= e($t['style']) ?>" data-bs-toggle="modal" data-bs-target="#statusModal-<?= e($t['to']) ?>"><?= e($t['label']) ?></button>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-12 col-lg-6">
        <div class="card jt-card h-100">
            <div class="card-header bg-transparent fw-semibold">Customer &amp; route</div>
            <div class="card-body">
                <dl class="row mb-0 gy-2">
                    <?= $dl('Customer', e($job['customer_first_name'] . ' ' . $job['customer_last_name'])) ?>
                    <?= $dl('Customer phone', tel_link($job['customer_phone'] ?? null)) ?>
                    <?= $dl('From', e($job['from_location'])) ?>
                    <?= $dl('To', e($job['to_location'])) ?>
                    <?= $dl('Job location', e($job['job_location'] ?: '—')) ?>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card jt-card h-100">
            <div class="card-header bg-transparent fw-semibold">Dates &amp; cargo</div>
            <div class="card-body">
                <dl class="row mb-0 gy-2">
                    <?= $dl('Pickup date', e(fmt_date($job['pickup_date']) ?: '—')) ?>
                    <?= $dl('1st available', e(fmt_date($job['first_available_date'])) . ' ' . availability_chip($job)) ?>
                    <?= $dl('Pickup time', e($job['pickup_time'] ?? '') ?: '—') ?>
                    <?= $dl('Miles', e(fmt_number($job['miles'] ?? null) ?: '—')) ?>
                    <?= $dl('Checks', implode(' &middot; ', array_map(static fn (string $k, string $label): string => '<span class="' . (!empty($job[$k]) ? 'text-success fw-semibold' : 'text-body-secondary') . '">' . (!empty($job[$k]) ? '&#10003; ' : '&#9744; ') . e($label) . '</span>', array_keys(\App\Services\JobService::CHECKS), \App\Services\JobService::CHECKS))) ?>
                    <?php if (!empty($job['loaded_date'])): ?>
                        <?= $dl('Loaded date', e(fmt_date((string) $job['loaded_date']))) ?>
                    <?php endif; ?>
                    <?php if ((string) $job['status'] === 'delivered' && !empty($job['delivery_date'])): ?>
                        <?= $dl('Delivery date', e(fmt_date((string) $job['delivery_date']))) ?>
                    <?php endif; ?>
                    <?= $dl('E-CF', e(fmt_number($job['estimated_cf'] ?? null) ?: '—')) ?>
                    <?= $dl('A-CF', e(fmt_number($job['cf']) ?: '—')) ?>
                    <?= $dl('Foreman', e($job['foreman'] ?? '') ?: '—') ?>
                    <?= $dl('Trip', empty($job['trip_id']) || empty($job['trip_number']) ? '—' : (can('trips.view') ? '<a class="jt-jobno" href="' . e(url('/trips/' . $job['trip_id'])) . '">' . e($job['trip_number']) . '</a>' : e($job['trip_number']))) ?>
                    <?= $dl('Job lot', e($job['job_lot'] ?? '') ?: '—') ?>
                    <?= $dl('Color', e($job['color'] ?? '') ?: '—') ?>
                    <?= $dl('Range', e($job['job_range'] ?? '') ?: '—') ?>
                    <?= $dl('Pads', e($job['pads'] ?? '') ?: '—') ?>
                    <?= $dl('Broker', e($job['broker'] ?? '') ?: '—') ?>
                    <?php if ($showFinancials): ?>
                        <?php foreach (['estimated_total' => 'E-Total', 'deposit' => 'Deposit', 'grand_total' => 'Grand total', 'bh_total' => 'Total to BH', 'paid' => 'Paid'] as $field => $label): ?>
                            <?= $dl($label, ($job[$field] ?? null) === null ? '—' : e(fmt_money($job[$field]))) ?>
                        <?php endforeach; ?>
                        <?= $dl('Carrier rate', ($job['carrier_rate'] ?? null) === null ? '—' : e(fmt_money($job['carrier_rate'])) . ' per CF') ?>
                        <?= $dl('Balance', e(fmt_money($job['balance']))) ?>
                        <?= $dl('Storage fee', e(fmt_money($job['storage_fee'])) . '<div class="jt-subtle">' . e(storage_fee_note($job)) . '</div>') ?>
                        <?= $dl('Storage paid', e(fmt_money($job['storage_paid'] ?? 0))) ?>
                        <?= $dl('Storage due', '<strong>' . e(fmt_money(\App\Services\StoragePaymentService::due($job))) . '</strong>') ?>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card jt-card h-100">
            <div class="card-header bg-transparent fw-semibold">Carrier</div>
            <div class="card-body">
                <?php if ($dispatched): ?>
                    <dl class="row mb-0 gy-2">
                        <?= $dl('Carrier', e($job['carrier_name'] ?: '—')) ?>
                        <?= $dl('Driver', e($job['driver_name'] ?: '—')) ?>
                        <?= $dl('Phone', tel_link($job['driver_phone'])) ?>

                    </dl>
                <?php else: ?>
                    <p class="text-body-secondary mb-0">Carrier details are optional. Add them under Edit once the job is in transit.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card jt-card h-100">
            <div class="card-header bg-transparent fw-semibold">History</div>
            <div class="card-body">
                <?= \App\Core\View::partial('jobs/_timeline', ['timeline' => $timeline, 'job' => $job]) ?>
                <?php if ((string) $job['updated_at'] !== (string) $job['created_at']): ?>
                    <div class="jt-subtle mt-3 pt-3 border-top">
                        Last updated <?= e(fmt_datetime($job['updated_at'])) ?><?= !empty($job['updated_by_name']) ? ' by ' . e($job['updated_by_name']) : '' ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($showFinancials): $storageDue = \App\Services\StoragePaymentService::due($job); ?>
        <div class="col-12" id="storage">
            <div class="card jt-card">
                <div class="card-header bg-transparent fw-semibold d-flex flex-wrap align-items-center gap-2">
                    Storage fee payments
                    <span class="jt-subtle fw-normal ms-auto">Fee <?= e(fmt_money($job['storage_fee'])) ?> &minus; paid <?= e(fmt_money($job['storage_paid'] ?? 0)) ?> = <strong>due <?= e(fmt_money($storageDue)) ?></strong></span>
                </div>
                <div class="card-body">
                    <?php if (!empty($canRecordStorage)): ?>
                        <?php if ((float) $storageDue > 0): ?>
                            <form method="post" action="<?= e(url('/jobs/' . $job['id'] . '/storage-payments')) ?>" class="row g-2 align-items-end mb-3">
                                <?= csrf_field() ?>
                                <div class="col-6 col-md-3">
                                    <label class="form-label small" for="sp-amount">Amount paid</label>
                                    <input type="number" class="form-control" id="sp-amount" name="amount" step="0.01" min="0.01" max="<?= e($storageDue) ?>" inputmode="decimal" required>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label small" for="sp-date">Paid on</label>
                                    <input type="date" class="form-control" id="sp-date" name="paid_on" value="<?= e(\App\Core\Clock::today()) ?>" max="<?= e(\App\Core\Clock::today()) ?>" required>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label small" for="sp-note">Note (optional)</label>
                                    <input type="text" class="form-control" id="sp-note" name="note" maxlength="255" placeholder="e.g. November storage, Zelle">
                                </div>
                                <div class="col-12 col-md-2"><button type="submit" class="btn btn-primary w-100">Record payment</button></div>
                            </form>
                        <?php else: ?>
                            <p class="text-body-secondary">No storage fee is due on this job right now. A payment can be recorded once the fee is due.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($storagePayments === []): ?>
                        <p class="text-body-secondary mb-0">No storage payments recorded yet.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead><tr><th scope="col">Paid on</th><th scope="col" class="text-end">Amount</th><th scope="col">Note</th><th scope="col">Recorded by</th><th></th></tr></thead>
                                <tbody>
                                <?php foreach ($storagePayments as $p): ?>
                                    <tr>
                                        <td><?= e(fmt_date((string) $p['paid_on'])) ?></td>
                                        <td class="text-end"><?= e(fmt_money($p['amount'])) ?></td>
                                        <td><?= e($p['note'] ?? '') ?: '<span class="text-body-secondary">&mdash;</span>' ?></td>
                                        <td><?= e($p['recorded_by'] ?? '') ?: '<span class="text-body-secondary">&mdash;</span>' ?></td>
                                        <td class="text-end">
                                            <?php if (!empty($canRecordStorage)): ?>
                                                <form method="post" action="<?= e(url('/jobs/' . $job['id'] . '/storage-payments/' . $p['id'] . '/delete')) ?>" class="d-inline">
                                                    <?= csrf_field() ?>
                                                    <button type="submit" class="btn btn-sm btn-outline-secondary" data-confirm="<?= e('Remove this storage payment of ' . fmt_money($p['amount']) . '? The amount becomes due again.') ?>">Remove</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (can('trips.view')): ?>
        <div class="col-12 col-lg-6">
            <div class="card jt-card h-100">
                <div class="card-header bg-transparent fw-semibold d-flex align-items-center gap-2"><?= icon('truck') ?> Foreman trips</div>
                <div class="card-body">
                    <?php if ($jobTrips === []): ?>
                        <p class="text-body-secondary mb-2">This job is not on any trip yet.</p>
                    <?php else: ?>
                        <ul class="list-unstyled mb-3">
                            <?php foreach ($jobTrips as $t): ?>
                                <li><a class="jt-jobno" href="<?= e(url('/trips/' . $t['id'])) ?>"><?= e($t['trip_number']) ?></a>
                                    <span class="text-body-secondary">&middot; <?= e($t['foreman_name']) ?><?= !empty($t['begins_on']) ? ' &middot; ' . e(fmt_date($t['begins_on'])) : '' ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if (can('trips.manage')): ?>
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <?php if ($tripChoices !== []): ?>
                                <form class="d-flex gap-2" method="post" action="<?= e(url('/jobs/' . $job['id'] . '/trip')) ?>">
                                    <?= csrf_field() ?>
                                    <label class="visually-hidden" for="add-trip">Add this job to a trip</label>
                                    <select class="form-select form-select-sm" id="add-trip" name="trip" required>
                                        <option value="">Add to a trip&hellip;</option>
                                        <?php foreach ($tripChoices as $t): ?>
                                            <option value="<?= e($t['id']) ?>"><?= e($t['trip_number']) ?> &middot; <?= e($t['foreman_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-outline-primary">Add</button>
                                </form>
                            <?php endif; ?>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(list_url('/trips/create', ['job' => $job['job_number']])) ?>"><?= icon('plus') ?> New trip with this job</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($job['notes'])): ?>
        <div class="col-12">
            <div class="card jt-card">
                <div class="card-header bg-transparent fw-semibold">Notes</div>
                <div class="card-body jt-notes"><?= e($job['notes']) ?></div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if (can('jobs.delete')): ?>
    <?= \App\Core\View::partial('partials/confirm-modal', [
        'modalId' => 'deleteJobModal',
        'heading' => 'Delete job ' . $job['job_number'] . '?',
        'message' => 'The job will be moved to the trash and removed from all lists. You can restore it from the Trash page.',
        'action' => '/jobs/' . $job['id'] . '/delete',
        'confirmLabel' => 'Delete job',
    ]) ?>
<?php endif; ?>

<?php if ($transitions !== []): ?>
    <?= \App\Core\View::partial('jobs/_status_modals', ['job' => $job, 'transitions' => $transitions, 'autoOpen' => $autoOpen]) ?>
<?php endif; ?>
