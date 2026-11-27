<?php
/**
 * Carrier / driver statement.
 * @var string $labor  @var string $pads  @var string $paid  @var array<int,string> $carriers  @var array<int,string> $drivers  @var array<int,string> $companies  @var string $company  @var string $carrier  @var string $driver  @var string $from  @var string $to
 * @var array<int,string> $errors  @var array<string,mixed>|null $statement  @var bool $canExport
 */
$q = ['carrier' => $carrier, 'driver' => $driver, 'company' => $company, 'from' => $from, 'to' => $to, 'labor' => $labor, 'pads' => $pads, 'paid' => $paid, 'go' => '1'];
$label = static fn (string $s): string => (string) \App\Core\Config::get('statuses.statuses.' . $s . '.label', $s);
?>
<div class="jt-page-actions">
    <p class="text-body-secondary mb-0 me-auto jt-section-note">The jobs of one carrier, driver or company (Stage, In Transit and Delivered). CF Pay is each job's own carrier rate times its actual CF; Total Due is the Bal. Due total minus CF Pay, minus any Labor cost and Pads you type, plus any Paid amount. Leave the dates empty to include every job.</p>
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
                <label class="form-label" for="st-company">Company</label>
                <select class="form-select" id="st-company" name="company">
                    <option value="">Any company</option>
                    <?php foreach ($companies as $c): ?>
                        <option value="<?= e($c) ?>"<?= mb_strtolower($company) === mb_strtolower($c) ? ' selected' : '' ?>><?= e($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php foreach (['labor' => ['Labor cost', $labor, 'Subtracted from Total Due.'], 'pads' => ['Pads', $pads, 'Subtracted from Total Due.'], 'paid' => ['Paid', $paid, 'Added to Total Due; a minus (-100) takes money off.']] as $name => [$text, $current, $help]): ?>
                <div class="col-12 col-md-4">
                    <label class="form-label" for="st-<?= e($name) ?>"><?= e($text) ?> (optional)</label>
                    <input type="text" class="form-control" id="st-<?= e($name) ?>" name="<?= e($name) ?>" inputmode="decimal" maxlength="12" placeholder="0.00" value="<?= e($current) ?>" aria-describedby="st-<?= e($name) ?>-help">
                    <div class="form-text" id="st-<?= e($name) ?>-help"><?= e($help) ?></div>
                </div>
            <?php endforeach; ?>
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
            <?php
            $logo = \App\Services\CompanyProfile::logoUrl();
            $dot = \App\Services\CompanyProfile::dot();
            $mc = \App\Services\CompanyProfile::mc();
            $phone = \App\Services\CompanyProfile::phone();
            $address = \App\Services\CompanyProfile::address();
            $title = $driver !== '' ? 'Driver Statement' : ($carrier !== '' ? 'Carrier Statement' : 'Company Statement');
            $subject = $driver !== '' ? $driver : ($carrier !== '' ? $carrier : $company);
            $showWho = $carrier === '' || $driver === ''; // each row says who carried it when the statement does not name both
            ?>
            <div class="jt-stmt-head">
                <div class="jt-stmt-company">
                    <?php if ($logo !== ''): ?><img class="jt-stmt-logo" src="<?= e($logo) ?>" alt=""><?php endif; ?>
                    <div>
                        <div class="jt-stmt-name"><?= e(\App\Services\CompanyProfile::name()) ?></div>
                        <?php if ($address !== ''): ?><div><?= e($address) ?></div><?php endif; ?>
                        <?php if ($dot !== '' || $mc !== ''): ?><div><?= $dot !== '' ? 'DOT: ' . e($dot) : '' ?><?= $dot !== '' && $mc !== '' ? ' &middot; ' : '' ?><?= $mc !== '' ? 'MC: ' . e($mc) : '' ?></div><?php endif; ?>
                        <?php if ($phone !== ''): ?><div>Phone: <?= e($phone) ?></div><?php endif; ?>
                    </div>
                </div>
                <div class="jt-stmt-who">
                    <div class="jt-stmt-title"><?= e($title) ?></div>
                    <div class="jt-stmt-name"><?= e($subject) ?></div>
                    <?php if ($driver !== '' && $carrier !== ''): ?><div>Carrier: <?= e($carrier) ?></div><?php endif; ?>
                    <?php if ($company !== '' && $subject !== $company): ?><div>Company: <?= e($company) ?></div><?php endif; ?>
                    <?php if ($driver !== '' && $statement['driver_phone'] !== ''): ?><div>Phone: <?= e($statement['driver_phone']) ?></div><?php endif; ?>
                    <div>Date: <?= e(fmt_date(\App\Core\Clock::today())) ?></div>
                    <div>Period: <?= $from === '' && $to === '' ? 'all dates' : e(($from !== '' ? fmt_date($from) : 'the start') . ' to ' . ($to !== '' ? fmt_date($to) : 'today')) ?></div>
                </div>
            </div>
            <div class="jt-stmt-totals">
                <span>Total jobs: <strong><?= e($t['jobs']) ?></strong></span>
                <span>Total CF: <strong><?= e(fmt_number($t['cf'])) ?></strong></span>
                <span>Total due: <strong><?= e(fmt_money($t['due'])) ?></strong></span>
            </div>

            <?php if ($statement['truncated']): ?>
                <div class="alert alert-warning">Only the first <?= e(\App\Services\CarrierStatement::MAX_ROWS) ?> jobs are shown. Choose dates to narrow it down.</div>
            <?php endif; ?>
            <?php if ($t['incomplete'] > 0): ?>
                <div class="alert alert-warning">
                    <?php if ($t['no_rate'] > 0): ?><div><?= e($t['no_rate']) ?> job<?= $t['no_rate'] === 1 ? ' has' : 's have' ?> no carrier rate.</div><?php endif; ?>
                    <?php if ($t['no_cf'] > 0): ?><div><?= e($t['no_cf']) ?> job<?= $t['no_cf'] === 1 ? ' has' : 's have' ?> no actual CF.</div><?php endif; ?>
                    <div>Their CF Pay is left out ($0), so Total Due is not final until the rate and the A-CF are entered on the job.</div>
                </div>
            <?php endif; ?>

            <?php if ($statement['rows'] === []): ?>
                <p class="text-body-secondary mb-0">No jobs match. Carrier and driver are saved when a job is moved to Stage or In Transit.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle jt-table jt-compact jt-statement">
                        <thead><tr>
                            <th>Job #</th><th>Status</th><?php if ($showWho): ?><th>Carrier / Driver</th><?php endif; ?><th>Customer</th><th>Phone</th><th>From</th><th>To</th>
                            <th class="text-end">A-CF</th><th class="text-end">Rate</th><th class="text-end">CF Pay</th><th class="text-end">Bal. Due</th><th>Remarks</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($statement['rows'] as $r): ?>
                            <tr>
                                <td><a href="<?= e(url('/jobs/' . $r['id'])) ?>"><?= e($r['job_number']) ?></a></td>
                                <td><?= e($label($r['status'])) ?></td>
                                <?php if ($showWho): ?><td><?= e($r['carrier'] !== '' ? $r['carrier'] : '—') ?><?= $r['driver'] !== '' ? '<div class="jt-subtle">' . e($r['driver']) . '</div>' : '' ?></td><?php endif; ?>
                                <td><?= e($r['customer']) ?></td>
                                <td><?= e($r['phone']) ?></td>
                                <td><?= e($r['from']) ?></td>
                                <td><?= e($r['to']) ?></td>
                                <td class="text-end"><?= $r['cf'] === null ? '&mdash;' : e(fmt_number($r['cf'])) ?></td>
                                <td class="text-end"><?= $r['rate'] === null ? '&mdash;' : e(fmt_money($r['rate'])) ?></td>
                                <td class="text-end"><?= $r['fee'] === null ? '&mdash;' : e(fmt_money($r['fee'])) ?></td>
                                <td class="text-end"><?= e(fmt_money($r['balance'])) ?></td>
                                <td><?= e($r['remarks']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot><tr class="fw-semibold">
                            <td colspan="<?= $showWho ? 7 : 6 ?>">TOTALS</td>
                            <td class="text-end"><?= e(fmt_number($t['cf'])) ?></td><td></td>
                            <td class="text-end"><?= e(fmt_money($t['fee'])) ?></td><td class="text-end"><?= e(fmt_money($t['subtotal'])) ?></td><td></td>
                        </tr></tfoot>
                    </table>
                </div>

                <div class="jt-statement-sum ms-auto">
                    <div><span>Subtotal <small>(Bal. Due total)</small></span><span><?= e(fmt_money($t['subtotal'])) ?></span></div>
                    <div><span>CF Pay <small>(Rate &times; A-CF)</small></span><span>&minus; <?= e(fmt_money($t['fee'])) ?></span></div>
                    <?php if ($t['labor'] !== '0.00'): ?><div><span>Labor cost</span><span>&minus; <?= e(fmt_money($t['labor'])) ?></span></div><?php endif; ?>
                    <?php if ($t['pads'] !== '0.00'): ?><div><span>Pads</span><span>&minus; <?= e(fmt_money($t['pads'])) ?></span></div><?php endif; ?>
                    <?php if ($t['paid'] !== '0.00'): ?><div><span>Paid</span><span><?= (float) $t['paid'] < 0 ? '&minus; ' . e(fmt_money(ltrim($t['paid'], '-'))) : '+ ' . e(fmt_money($t['paid'])) ?></span></div><?php endif; ?>
                    <div class="jt-statement-due"><span>TOTAL DUE</span><span><?= e(fmt_money($t['due'])) ?></span></div>
                </div>

                <div class="jt-stmt-foot">
                    <div class="jt-stmt-foot-cell"><strong>Remarks</strong></div>
                    <div class="jt-stmt-foot-cell text-end"><strong>Signature</strong></div>
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
