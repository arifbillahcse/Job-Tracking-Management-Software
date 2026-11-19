<?php
/**
 * Carrier / driver statement.
 * @var array<int,string> $carriers  @var array<int,string> $drivers  @var string $carrier  @var string $driver  @var string $from  @var string $to
 * @var string $rate  @var array<int,string> $errors  @var array<string,mixed>|null $statement  @var bool $canExport
 */
$q = ['carrier' => $carrier, 'driver' => $driver, 'from' => $from, 'to' => $to, 'rate' => $rate, 'go' => '1'];
$label = static fn (string $s): string => (string) \App\Core\Config::get('statuses.statuses.' . $s . '.label', $s);
?>
<div class="jt-page-actions">
    <p class="text-body-secondary mb-0 me-auto jt-section-note">The jobs of one carrier or driver (Stage, In Transit and Delivered). CF Pay is the rate times the actual CF; Total Due is the Bal. Due total minus CF Pay. Leave the dates empty to include every job.</p>
</div>

<div class="card jt-card mb-3 jt-noprint">
    <div class="card-body">
        <form method="get" action="<?= e(url('/jobs/statement')) ?>" class="row g-3 align-items-end">
            <input type="hidden" name="go" value="1">
            <div class="col-12 col-md-4">
                <label class="form-label" for="st-carrier">Carrier</label>
                <select class="form-select" id="st-carrier" name="carrier">
                    <option value="">Any carrier</option>
                    <?php foreach ($carriers as $c): ?>
                        <option value="<?= e($c) ?>"<?= mb_strtolower($carrier) === mb_strtolower($c) ? ' selected' : '' ?>><?= e($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label" for="st-driver">Driver</label>
                <select class="form-select" id="st-driver" name="driver">
                    <option value="">Any driver</option>
                    <?php foreach ($drivers as $d): ?>
                        <option value="<?= e($d) ?>"<?= mb_strtolower($driver) === mb_strtolower($d) ? ' selected' : '' ?>><?= e($d) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label" for="st-rate">Rate per CF <span class="text-danger" aria-hidden="true">*</span></label>
                <input type="text" class="form-control" id="st-rate" name="rate" inputmode="decimal" maxlength="7" placeholder="2.50" value="<?= e($rate) ?>">
            </div>
            <div class="col-6 col-md-4">
                <label class="form-label" for="st-from">From (optional)</label>
                <input type="date" class="form-control" id="st-from" name="from" value="<?= e($from) ?>">
            </div>
            <div class="col-6 col-md-4">
                <label class="form-label" for="st-to">To (optional)</label>
                <input type="date" class="form-control" id="st-to" name="to" value="<?= e($to) ?>">
            </div>
            <div class="col-12 col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Show statement</button>
                <a class="btn btn-outline-secondary" href="<?= e(url('/jobs/statement')) ?>">Clear</a>
            </div>
        </form>
        <?php if ($errors !== []): ?>
            <div class="alert alert-danger mt-3 mb-0" role="alert"><ul class="mb-0">
                <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
            </ul></div>
        <?php endif; ?>
    </div>
</div>

<?php if ($statement !== null): $t = $statement['totals']; ?>
    <div class="card jt-card mb-3">
        <div class="card-body">
            <div class="d-flex flex-wrap gap-3 justify-content-between mb-3">
                <div>
                    <div class="h5 mb-1">Statement &middot; <?= e(trim(($carrier !== '' ? $carrier : '') . ($carrier !== '' && $driver !== '' ? ' / ' : '') . $driver)) ?></div>
                    <div class="text-body-secondary small">
                        Rate <?= e(fmt_money(\App\Services\CarrierStatement::parseRate($rate)['value'])) ?> per CF &middot;
                        <?= $from === '' && $to === '' ? 'all dates' : e(($from !== '' ? fmt_date($from) : 'the start') . ' to ' . ($to !== '' ? fmt_date($to) : 'today')) ?>
                    </div>
                </div>
                <div class="text-end small">
                    <div>Total jobs: <strong><?= e($t['jobs']) ?></strong></div>
                    <div>Total CF: <strong><?= e(fmt_number($t['cf'])) ?></strong></div>
                    <div>Total due: <strong><?= e(fmt_money($t['due'])) ?></strong></div>
                </div>
            </div>

            <?php if ($statement['truncated']): ?>
                <div class="alert alert-warning">Only the first <?= e(\App\Services\CarrierStatement::MAX_ROWS) ?> jobs are shown. Choose dates to narrow it down.</div>
            <?php endif; ?>
            <?php if ($t['no_cf'] > 0): ?>
                <div class="alert alert-warning"><?= e($t['no_cf']) ?> job<?= $t['no_cf'] === 1 ? ' has' : 's have' ?> no actual CF yet, so <?= $t['no_cf'] === 1 ? 'its CF Pay is' : 'their CF Pay is' ?> left out ($0).</div>
            <?php endif; ?>

            <?php if ($statement['rows'] === []): ?>
                <p class="text-body-secondary mb-0">No jobs match. Carrier and driver are saved when a job is moved to Stage or In Transit.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle jt-table jt-compact jt-statement">
                        <thead><tr>
                            <th>Job #</th><th>Status</th><th>Customer</th><th>Phone</th><th>From</th><th>To</th>
                            <th class="text-end">A-CF</th><th class="text-end">Rate</th><th class="text-end">CF Pay</th><th class="text-end">Bal. Due</th><th>Remarks</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($statement['rows'] as $r): ?>
                            <tr>
                                <td><a href="<?= e(url('/jobs/' . $r['id'])) ?>"><?= e($r['job_number']) ?></a></td>
                                <td><?= e($label($r['status'])) ?></td>
                                <td><?= e($r['customer']) ?></td>
                                <td><?= e($r['phone']) ?></td>
                                <td><?= e($r['from']) ?></td>
                                <td><?= e($r['to']) ?></td>
                                <td class="text-end"><?= $r['cf'] === null ? '&mdash;' : e(fmt_number($r['cf'])) ?></td>
                                <td class="text-end"><?= e(fmt_money(\App\Services\CarrierStatement::parseRate($rate)['value'])) ?></td>
                                <td class="text-end"><?= $r['fee'] === null ? '&mdash;' : e(fmt_money($r['fee'])) ?></td>
                                <td class="text-end"><?= e(fmt_money($r['balance'])) ?></td>
                                <td><?= e($r['remarks']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot><tr class="fw-semibold">
                            <td colspan="6">TOTALS</td>
                            <td class="text-end"><?= e(fmt_number($t['cf'])) ?></td><td></td>
                            <td class="text-end"><?= e(fmt_money($t['fee'])) ?></td><td class="text-end"><?= e(fmt_money($t['subtotal'])) ?></td><td></td>
                        </tr></tfoot>
                    </table>
                </div>

                <div class="jt-statement-sum ms-auto">
                    <div><span>Subtotal <small>(Bal. Due total)</small></span><span><?= e(fmt_money($t['subtotal'])) ?></span></div>
                    <div><span>CF Pay <small>(Rate &times; A-CF)</small></span><span>&minus; <?= e(fmt_money($t['fee'])) ?></span></div>
                    <div class="jt-statement-due"><span>TOTAL DUE</span><span><?= e(fmt_money($t['due'])) ?></span></div>
                </div>

                <div class="d-flex gap-2 mt-3 jt-noprint">
                    <button type="button" class="btn btn-outline-secondary" data-print><?= icon('file-text') ?> Print</button>
                    <?php if ($canExport): ?>
                        <a class="btn btn-outline-secondary" href="<?= e(list_url('/jobs/statement', $q + ['format' => 'csv'])) ?>"><?= icon('download') ?> Download CSV</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
