<?php
/**
 * One saved invoice (printable).
 * @var array<string,mixed> $invoice  @var array<int,array<string,mixed>> $lines  @var string $number  @var string $status
 * @var array{text:string,amount:string,code:string} $direction  @var bool $canManage
 */
use App\Services\CompanyProfile;
use App\Services\InvoiceService;

$label = static fn (string $s): string => (string) \App\Core\Config::get('statuses.statuses.' . $s . '.label', $s);
$subject = (string) ($invoice['driver'] ?: ($invoice['carrier'] ?: ($invoice['broker'] ?: '')));
$logo = CompanyProfile::logoUrl();
$dot = CompanyProfile::dot();
$mc = CompanyProfile::mc();
$phone = CompanyProfile::phone();
$address = CompanyProfile::address();
$period = ($invoice['period_from'] === null && $invoice['period_to'] === null)
    ? 'all dates'
    : ($invoice['period_from'] !== null ? fmt_date((string) $invoice['period_from']) : 'the start') . ' to ' . ($invoice['period_to'] !== null ? fmt_date((string) $invoice['period_to']) : 'today');
?>
<div class="jt-page-actions jt-noprint">
    <a class="btn btn-outline-secondary" href="<?= e(url('/invoices')) ?>">&laquo; All invoices</a>
    <button type="button" class="btn btn-outline-secondary" data-print><?= icon('file-text') ?> Print</button>
    <span class="ms-auto">
        <?php if ($status === 'paid'): ?><span class="badge text-bg-success fs-6">Paid <?= e(fmt_date((string) $invoice['paid_on'])) ?></span>
        <?php elseif ($status === 'void'): ?><span class="badge text-bg-secondary fs-6">Void</span>
        <?php else: ?><span class="badge text-bg-warning fs-6">Open</span><?php endif; ?>
    </span>
</div>

<?php if ($status === 'void'): ?>
    <div class="alert alert-secondary">This invoice was voided<?= $invoice['voided_at'] !== null ? ' on ' . e(fmt_date(substr((string) $invoice['voided_at'], 0, 10))) : '' ?>: <?= e((string) $invoice['void_reason']) ?>. Its jobs can be invoiced again.</div>
<?php endif; ?>

<div class="card jt-card mb-3">
    <div class="card-body">
        <div class="jt-stmt-head">
            <div class="jt-stmt-company">
                <?php if ($logo !== ''): ?><img class="jt-stmt-logo" src="<?= e($logo) ?>" alt=""><?php endif; ?>
                <div>
                    <div class="jt-stmt-name"><?= e(CompanyProfile::name()) ?></div>
                    <?php if ($address !== ''): ?><div><?= e($address) ?></div><?php endif; ?>
                    <?php if ($dot !== '' || $mc !== ''): ?><div><?= $dot !== '' ? 'DOT: ' . e($dot) : '' ?><?= $dot !== '' && $mc !== '' ? ' &middot; ' : '' ?><?= $mc !== '' ? 'MC: ' . e($mc) : '' ?></div><?php endif; ?>
                    <?php if ($phone !== ''): ?><div>Phone: <?= e($phone) ?></div><?php endif; ?>
                </div>
            </div>
            <div class="jt-stmt-who">
                <div class="jt-stmt-title"><?= e(InvoiceService::TYPES[$invoice['type']] ?? 'Invoice') ?></div>
                <div class="jt-stmt-name">Invoice #: <?= e($number) ?></div>
                <?php if ($subject !== ''): ?><div><?= e($subject) ?></div><?php endif; ?>
                <?php if ($invoice['driver'] && $invoice['carrier']): ?><div>Carrier: <?= e($invoice['carrier']) ?></div><?php endif; ?>
                <?php if ($invoice['broker'] && $subject !== (string) $invoice['broker']): ?><div>Company: <?= e($invoice['broker']) ?></div><?php endif; ?>
                <?php if ($invoice['driver_phone']): ?><div>Phone: <?= e($invoice['driver_phone']) ?></div><?php endif; ?>
                <div>Date: <?= e(fmt_date((string) $invoice['invoice_date'])) ?></div>
                <div>Period: <?= e($period) ?></div>
            </div>
        </div>
        <div class="jt-stmt-totals">
            <span>Total jobs: <strong><?= e(count($lines)) ?></strong></span>
            <span>Total CF: <strong><?= e(fmt_number($invoice['total_cf'])) ?></strong></span>
            <span>Total due: <strong><?= e(fmt_money($invoice['total'])) ?></strong> <span class="text-body-secondary">(<?= e($direction['text']) ?><?= $direction['code'] === 'settled' ? '' : ' ' . e(fmt_money($direction['amount'])) ?>)</span></span>
        </div>

        <div class="table-responsive">
            <table class="table align-middle jt-table jt-compact jt-statement">
                <thead><tr>
                    <th>Job #</th><th>Status</th><th>Carrier / Driver</th><th>Customer</th><th>Phone</th><th>From</th><th>To</th>
                    <th class="text-end">A-CF</th><th class="text-end">Rate</th><th class="text-end">CF Pay</th><th class="text-end">Bal. Due</th><th>Remarks</th>
                </tr></thead>
                <tbody>
                <?php foreach ($lines as $l): ?>
                    <tr>
                        <td><?php if ($l['job_id'] !== null): ?><a href="<?= e(url('/jobs/' . $l['job_id'])) ?>"><?= e($l['job_number']) ?></a><?php else: ?><?= e($l['job_number']) ?><?php endif; ?></td>
                        <td><?= e($l['status'] ? $label((string) $l['status']) : '') ?></td>
                        <td><?= e($l['carrier'] ?: '—') ?><?= $l['driver'] ? '<div class="jt-subtle">' . e($l['driver']) . '</div>' : '' ?></td>
                        <td><?= e($l['customer']) ?></td>
                        <td><?= e($l['phone']) ?></td>
                        <td><?= e($l['from_location']) ?></td>
                        <td><?= e($l['to_location']) ?></td>
                        <td class="text-end"><?= $l['cf'] === null ? '&mdash;' : e(fmt_number($l['cf'])) ?></td>
                        <td class="text-end"><?= $l['rate'] === null ? '&mdash;' : e(fmt_money($l['rate'])) ?></td>
                        <td class="text-end"><?= $l['fee'] === null ? '&mdash;' : e(fmt_money($l['fee'])) ?></td>
                        <td class="text-end"><?= e(fmt_money($l['balance'])) ?></td>
                        <td><?= e($l['remarks']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr class="fw-semibold">
                    <td colspan="7">TOTALS</td>
                    <td class="text-end"><?= e(fmt_number($invoice['total_cf'])) ?></td><td></td>
                    <td class="text-end"><?= e(fmt_money($invoice['carrier_fee'])) ?></td><td class="text-end"><?= e(fmt_money($invoice['subtotal'])) ?></td><td></td>
                </tr></tfoot>
            </table>
        </div>

        <div class="jt-statement-sum ms-auto">
            <div><span>Subtotal <small>(Bal. Due total)</small></span><span><?= e(fmt_money($invoice['subtotal'])) ?></span></div>
            <div><span>CF Pay <small>(Rate &times; A-CF)</small></span><span>&minus; <?= e(fmt_money($invoice['carrier_fee'])) ?></span></div>
            <?php if ((float) $invoice['labor'] !== 0.0): ?><div><span>Labor cost</span><span>&minus; <?= e(fmt_money($invoice['labor'])) ?></span></div><?php endif; ?>
            <?php if ((float) $invoice['pads'] !== 0.0): ?><div><span>Pads</span><span>&minus; <?= e(fmt_money($invoice['pads'])) ?></span></div><?php endif; ?>
            <?php if ((float) $invoice['paid_amount'] !== 0.0): ?><div><span>Paid</span><span><?= (float) $invoice['paid_amount'] < 0 ? '&minus; ' . e(fmt_money(ltrim((string) $invoice['paid_amount'], '-'))) : '+ ' . e(fmt_money($invoice['paid_amount'])) ?></span></div><?php endif; ?>
            <div class="jt-statement-due"><span>TOTAL DUE</span><span><?= e(fmt_money($invoice['total'])) ?></span></div>
            <div class="jt-statement-dir jt-statement-dir-<?= e($direction['code']) ?>"><span><?= e($direction['text']) ?></span><span><?= $direction['code'] === 'settled' ? '' : e(fmt_money($direction['amount'])) ?></span></div>
        </div>

        <div class="jt-stmt-foot">
            <div class="jt-stmt-foot-cell">
                <?php if ($invoice['remarks']): ?><div class="mb-1 jt-prewrap"><?= e($invoice['remarks']) ?></div><?php endif; ?>
                <strong>Remarks</strong>
            </div>
            <div class="jt-stmt-foot-cell text-end"><strong>Signature</strong></div>
        </div>
    </div>
</div>

<?php if ($canManage && $status !== 'void'): ?>
    <div class="row g-3 jt-noprint">
        <div class="col-12 col-lg-7">
            <form method="post" action="<?= e(url('/invoices/' . $invoice['id'])) ?>" class="card jt-card" data-loading>
                <?= csrf_field() ?>
                <div class="card-body">
                    <div class="fw-semibold mb-2">Change this invoice</div>
                    <p class="form-text mt-0">The jobs on the invoice stay as they were saved. You can change the date, the remarks and the three amounts; the total is worked out again.</p>
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="e-date">Invoice date</label>
                            <input type="date" class="form-control" id="e-date" name="invoice_date" value="<?= e((string) $invoice['invoice_date']) ?>" required>
                        </div>
                        <?php foreach (['labor' => 'Labor cost', 'pads' => 'Pads', 'paid_amount' => 'Paid'] as $name => $text): $v = (float) $invoice[$name] === 0.0 ? '' : rtrim(rtrim(number_format((float) $invoice[$name], 2, '.', ''), '0'), '.'); ?>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="e-<?= e($name) ?>"><?= e($text) ?></label>
                                <input type="text" class="form-control" id="e-<?= e($name) ?>" name="<?= e($name) ?>" inputmode="decimal" maxlength="12" placeholder="0.00" value="<?= e($v) ?>">
                            </div>
                        <?php endforeach; ?>
                        <div class="col-12">
                            <label class="form-label" for="e-remarks">Remarks</label>
                            <textarea class="form-control" id="e-remarks" name="remarks" rows="3" maxlength="<?= e(InvoiceService::MAX_REMARKS) ?>"><?= e((string) ($invoice['remarks'] ?? '')) ?></textarea>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary mt-3">Save changes</button>
                </div>
            </form>
        </div>
        <div class="col-12 col-lg-5 d-flex flex-column gap-3">
            <div class="card jt-card">
                <div class="card-body">
                    <div class="fw-semibold mb-2">Payment</div>
                    <?php if ($status === 'paid'): ?>
                        <p class="mb-2">Marked paid on <?= e(fmt_date((string) $invoice['paid_on'])) ?>.</p>
                        <form method="post" action="<?= e(url('/invoices/' . $invoice['id'] . '/open')) ?>" data-loading>
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-outline-secondary">Mark as not paid</button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="<?= e(url('/invoices/' . $invoice['id'] . '/paid')) ?>" class="d-flex flex-wrap gap-2 align-items-end" data-loading>
                            <?= csrf_field() ?>
                            <div>
                                <label class="form-label" for="p-date">Paid on</label>
                                <input type="date" class="form-control" id="p-date" name="paid_on" max="<?= e(\App\Core\Clock::today()) ?>" value="<?= e(\App\Core\Clock::today()) ?>">
                            </div>
                            <button type="submit" class="btn btn-success">Mark as paid</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($status === 'open'): ?>
                <div class="card jt-card">
                    <div class="card-body">
                        <div class="fw-semibold mb-2">Void this invoice</div>
                        <p class="form-text mt-0">Use this if the invoice was made by mistake. It stays on file, its number is not used again, and its jobs can be invoiced again.</p>
                        <form method="post" action="<?= e(url('/invoices/' . $invoice['id'] . '/void')) ?>" data-loading>
                            <?= csrf_field() ?>
                            <label class="form-label" for="v-reason">Reason</label>
                            <input type="text" class="form-control mb-2" id="v-reason" name="reason" maxlength="255" required>
                            <button type="submit" class="btn btn-outline-danger" data-confirm="Void invoice <?= e($number) ?>? Its jobs can then be invoiced again.">Void invoice</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
