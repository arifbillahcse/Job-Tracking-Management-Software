<?php
/**
 * Saved invoices.
 * @var array<string,string> $filters (q, status, type, from, to)  @var array<int,array<string,mixed>> $invoices  @var \App\Helpers\Paginator $paginator
 * @var string $sum  @var string $openSum  @var bool $canManage
 */
use App\Services\InvoiceService;

$badge = static function (string $status, array $inv): string {
    return match ($status) {
        'paid' => '<span class="badge text-bg-success">Paid ' . e(fmt_date((string) $inv['paid_on'])) . '</span>',
        'void' => '<span class="badge text-bg-secondary">Void</span>',
        default => '<span class="badge text-bg-warning">Open</span>',
    };
};
?>
<div class="jt-page-actions">
    <p class="text-body-secondary mb-0 me-auto jt-section-note">Saved invoices. To make one, open <a href="<?= e(url('/jobs/statement')) ?>">Carrier Statement</a>, choose a carrier, a driver (a driver invoice, DI) or just a company (a company invoice, CI) and press "Save as invoice". An invoice keeps the numbers it had when it was saved.</p>
</div>

<div class="card jt-card mb-3">
    <div class="card-body">
        <form method="get" action="<?= e(url('/invoices')) ?>" class="row g-3 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label" for="inv-q">Invoice # or name</label>
                <input type="text" class="form-control" id="inv-q" name="q" maxlength="100" placeholder="DI-12, CI-3, carrier, driver or company" value="<?= e($filters['q']) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label" for="inv-type">Type</label>
                <select class="form-select" id="inv-type" name="type">
                    <option value="">All</option>
                    <?php foreach (InvoiceService::TYPES as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= $filters['type'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label" for="inv-status">Status</label>
                <select class="form-select" id="inv-status" name="status">
                    <option value="">All</option>
                    <?php foreach (['open' => 'Open', 'paid' => 'Paid', 'void' => 'Void'] as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= $filters['status'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label" for="inv-from">From</label>
                <input type="date" class="form-control" id="inv-from" name="from" value="<?= e($filters['from']) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label" for="inv-to">To</label>
                <input type="date" class="form-control" id="inv-to" name="to" value="<?= e($filters['to']) ?>">
            </div>
            <div class="col-6 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Search</button>
                <a class="btn btn-outline-secondary" href="<?= e(url('/invoices')) ?>">Clear</a>
            </div>
        </form>
    </div>
</div>

<div class="card jt-card mb-3">
    <div class="card-body">
        <div class="d-flex flex-wrap gap-3 small mb-3">
            <span>Invoices: <strong><?= e($paginator->total) ?></strong></span>
            <span>Total of these (not voided): <strong><?= e(fmt_money($sum)) ?></strong></span>
            <span>Still open: <strong><?= e(fmt_money($openSum)) ?></strong></span>
        </div>
        <?php if ($invoices === []): ?>
            <p class="text-body-secondary mb-0">No invoices yet. Save one from the Carrier Statement.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle jt-table jt-compact">
                    <thead><tr><th>Invoice #</th><th>Type</th><th>Date</th><th>For</th><th class="text-end">Jobs</th><th class="text-end">Total due</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($invoices as $inv): $status = InvoiceService::status($inv); $number = InvoiceService::number($inv); ?>
                        <tr<?= $status === 'void' ? ' class="text-body-secondary"' : '' ?>>
                            <td><a class="jt-jobno" href="<?= e(url('/invoices/' . $inv['id'])) ?>"><?= e($number) ?></a></td>
                            <td><?= e(InvoiceService::TYPES[$inv['type']] ?? $inv['type']) ?></td>
                            <td><?= e(fmt_date((string) $inv['invoice_date'])) ?></td>
                            <td>
                                <?= e((string) ($inv['driver'] ?: ($inv['carrier'] ?: ($inv['broker'] ?: '—')))) ?>
                                <?php if ($inv['driver'] && $inv['carrier']): ?><div class="jt-subtle">Carrier: <?= e($inv['carrier']) ?></div><?php endif; ?>
                                <?php if ($inv['broker'] && ($inv['driver'] || $inv['carrier'])): ?><div class="jt-subtle">Company: <?= e($inv['broker']) ?></div><?php endif; ?>
                            </td>
                            <td class="text-end"><?= e($inv['line_count']) ?></td>
                            <td class="text-end"><?= e(fmt_money($inv['total'])) ?></td>
                            <td><?= $badge($status, $inv) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?= \App\Core\View::partial('partials/pagination', ['paginator' => $paginator, 'basePath' => '/invoices', 'linkParams' => $filters]) ?>
