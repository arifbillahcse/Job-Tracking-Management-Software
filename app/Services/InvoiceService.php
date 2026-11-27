<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Database;

/**
 * Saved invoices (v1.16.0; company invoices v1.17.0). A Carrier Statement is saved as a numbered invoice (DI-1, DI-2, ...): a SNAPSHOT of its lines, so editing a
 * job later never changes an invoice that was already given out. Everything is recomputed here from the jobs; nothing the browser
 * posts (amounts, lines) is trusted. A job is on at most one open invoice: jobs already on an invoice are left out unless asked for,
 * and voiding an invoice frees its jobs again (a job may be on one open driver invoice AND one open company invoice, they are different
 * documents). A carrier / driver statement is saved as a driver invoice (DI), a company-only statement as a company invoice (CI), and the
 * company invoices of a driver invoice can be generated from it. Money is added in whole cents.
 *
 *   total = subtotal (Bal. Due total) - carrier_fee (CF Pay total) - labor - pads + paid_amount
 */
final class InvoiceService
{
    public const TYPES = ['DI' => 'Driver Invoice', 'CI' => 'Company Invoice'];
    public const MAX_REMARKS = 2000;
    public const PER_PAGE = 25;

    /** The number as printed, like DI-12. @param array<string,mixed> $invoice */
    public static function number(array $invoice): string
    {
        return (string) $invoice['type'] . '-' . (int) $invoice['seq'];
    }

    /** @param array<string,mixed> $invoice @return string open | paid | void */
    public static function status(array $invoice): string
    {
        if (($invoice['voided_at'] ?? null) !== null) {
            return 'void';
        }
        return ($invoice['paid_on'] ?? null) !== null ? 'paid' : 'open';
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::fetchOne('SELECT * FROM invoices WHERE id = ?', [$id]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function lines(int $id): array
    {
        return Database::fetchAll('SELECT * FROM invoice_lines WHERE invoice_id = ? ORDER BY sort_order ASC, id ASC', [$id]);
    }

    /**
     * The invoices (not voided) that hold each job, for marking jobs on the statement.
     *
     * @param array<int,int> $jobIds
     * @param string $type only invoices of this type (DI or CI), or '' for all
     * @return array<int,array<int,string>> job id => numbers
     */
    public static function numbersForJobs(array $jobIds, string $type = ''): array
    {
        $jobIds = array_values(array_unique(array_filter(array_map('intval', $jobIds), static fn (int $id): bool => $id > 0)));
        if ($jobIds === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($jobIds, 400) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $rows = Database::fetchAll(
                "SELECT il.job_id, iv.type, iv.seq FROM invoice_lines il JOIN invoices iv ON iv.id = il.invoice_id WHERE iv.voided_at IS NULL AND il.job_id IN ({$marks})" . ($type !== '' ? ' AND iv.type = ?' : '') . ' ORDER BY iv.id ASC',
                $type !== '' ? [...$chunk, $type] : $chunk
            );
            foreach ($rows as $r) {
                $out[(int) $r['job_id']][] = (string) $r['type'] . '-' . (int) $r['seq'];
            }
        }
        return $out;
    }

    /** The type a statement is saved as: a carrier or driver statement is a driver invoice (DI), a company-only one a company invoice (CI), nothing chosen = ''. */
    public static function typeFor(string $carrier, string $driver, string $broker): string
    {
        if ($carrier !== '' || $driver !== '') {
            return 'DI';
        }
        return $broker !== '' ? 'CI' : '';
    }

    /**
     * Save the current statement as an invoice.
     *
     * @return array{ok:bool,error?:string,id?:int,number?:string}
     */
    public static function create(
        string $carrier,
        string $driver,
        string $broker,
        string $from,
        string $to,
        int $laborCents,
        int $padsCents,
        int $paidCents,
        string $remarks,
        string $date,
        bool $includeInvoiced
    ): array {
        $type = self::typeFor($carrier, $driver, $broker);
        if ($type === '') {
            return ['ok' => false, 'error' => 'Choose a carrier, a driver or a company to save an invoice.'];
        }
        $date = trim($date) === '' ? Clock::today() : trim($date);
        if (CarrierStatement::parseDate($date, 'Invoice date')['error'] !== null) {
            return ['ok' => false, 'error' => 'The invoice date is not a valid date.'];
        }
        $remarks = trim($remarks);
        if (mb_strlen($remarks) > self::MAX_REMARKS) {
            return ['ok' => false, 'error' => 'The remarks can be at most ' . self::MAX_REMARKS . ' characters.'];
        }

        $statement = CarrierStatement::build($carrier, $driver, $broker, $from, $to, $laborCents, $padsCents, $paidCents, $includeInvoiced ? '' : $type);
        if ($statement['rows'] === []) {
            $any = CarrierStatement::build($carrier, $driver, $broker, $from, $to);
            return ['ok' => false, 'error' => $any['rows'] !== [] && !$includeInvoiced
                ? 'Every job here is already on a saved ' . strtolower(self::TYPES[$type]) . '. Tick "Also include jobs already on an invoice" to invoice them again.'
                : 'There are no jobs to invoice.'];
        }
        if ($statement['truncated']) {
            return ['ok' => false, 'error' => 'There are too many jobs for one invoice (more than ' . CarrierStatement::MAX_ROWS . '). Choose dates to narrow it down.'];
        }
        $incomplete = (int) $statement['totals']['incomplete'];
        if ($incomplete > 0) {
            return ['ok' => false, 'error' => $incomplete . ' job' . ($incomplete === 1 ? ' has' : 's have') . ' no carrier rate or no actual CF, so its CF Pay is unknown. Enter the Carrier rate and the A-CF on the job first, then save the invoice.'];
        }

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $id = (int) Database::transaction(static fn (): int => self::insert($type, $statement, $carrier, $driver, $broker, $from, $to, $remarks, $date));
                $invoice = self::find($id) ?? [];
                AuditService::record('invoice_created', 'invoice', $id, null, [
                    'number' => self::number($invoice), 'carrier' => $carrier, 'driver' => $driver, 'company' => $broker,
                    'jobs' => $statement['totals']['jobs'], 'total' => $statement['totals']['due'],
                ]);
                return ['ok' => true, 'id' => $id, 'number' => self::number($invoice)];
            } catch (\PDOException $e) {
                if ((string) $e->getCode() !== '23000' || $attempt === 3) { // 23000: two people took the same number at once, so take the next one
                    throw $e;
                }
            }
        }
        return ['ok' => false, 'error' => 'The invoice could not be saved. Please try again.'];
    }

    /** @param array<string,mixed> $statement */
    private static function insert(string $type, array $statement, string $carrier, string $driver, string $broker, string $from, string $to, string $remarks, string $date): int
    {
        $t = $statement['totals'];
        $now = gmdate('Y-m-d H:i:s');
        $seq = (int) Database::fetchValue('SELECT COALESCE(MAX(seq), 0) + 1 FROM invoices WHERE type = ?', [$type]);
        $id = Database::insert(
            'INSERT INTO invoices (type, seq, invoice_date, carrier, driver, broker, driver_phone, period_from, period_to, total_cf, subtotal, carrier_fee, labor, pads, paid_amount, total, remarks, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $type, $seq, $date, $carrier === '' ? null : $carrier, $driver === '' ? null : $driver, $broker === '' ? null : $broker,
                $statement['driver_phone'] === '' ? null : $statement['driver_phone'], $from === '' ? null : $from, $to === '' ? null : $to,
                $t['cf'], $t['subtotal'], $t['fee'], $t['labor'], $t['pads'], $t['paid'], $t['due'], $remarks === '' ? null : $remarks, Auth::id(), $now, $now,
            ]
        );
        $order = 0;
        foreach ($statement['rows'] as $r) {
            Database::execute(
                'INSERT INTO invoice_lines (invoice_id, sort_order, job_id, job_number, status, customer, phone, from_location, to_location, broker, carrier, driver, cf, rate, fee, balance, remarks)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $id, ++$order, $r['id'], $r['job_number'], $r['status'], $r['customer'], $r['phone'] === '' ? null : $r['phone'], $r['from'], $r['to'],
                    $r['broker'] === '' ? null : $r['broker'], $r['carrier'] === '' ? null : $r['carrier'], $r['driver'] === '' ? null : $r['driver'],
                    $r['cf'], $r['rate'], $r['fee'], $r['balance'], $r['remarks'] === '' ? null : $r['remarks'],
                ]
            );
        }
        return $id;
    }

    /**
     * Change the date, remarks, Labor, Pads or Paid of an invoice that is not voided; the total is recomputed from the saved lines.
     *
     * @param array<string,mixed> $input
     * @return array{ok:bool,error?:string}
     */
    public static function update(int $id, array $input): array
    {
        $invoice = self::find($id);
        if ($invoice === null) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if (self::status($invoice) === 'void') {
            return ['ok' => false, 'error' => 'A voided invoice cannot be changed.'];
        }
        $money = [];
        foreach (['labor' => 'Labor cost', 'pads' => 'Pads', 'paid_amount' => 'Paid'] as $key => $label) {
            $m = CarrierStatement::parseMoney($input[$key] ?? '', $label);
            if ($m['error'] !== null) {
                return ['ok' => false, 'error' => $m['error']];
            }
            $money[$key] = $m['cents'];
        }
        $date = trim(is_string($input['invoice_date'] ?? null) ? $input['invoice_date'] : '');
        if ($date === '' || CarrierStatement::parseDate($date, 'Invoice date')['error'] !== null) {
            return ['ok' => false, 'error' => 'The invoice date is not a valid date.'];
        }
        $remarks = trim(is_string($input['remarks'] ?? null) ? $input['remarks'] : '');
        if (mb_strlen($remarks) > self::MAX_REMARKS) {
            return ['ok' => false, 'error' => 'The remarks can be at most ' . self::MAX_REMARKS . ' characters.'];
        }

        $cents = static fn (mixed $v): int => (int) round((float) $v * 100);
        $totalCents = $cents($invoice['subtotal']) - $cents($invoice['carrier_fee']) - $money['labor'] - $money['pads'] + $money['paid_amount'];
        $fmt = static fn (int $c): string => number_format($c / 100, 2, '.', '');
        $new = ['invoice_date' => $date, 'labor' => $fmt($money['labor']), 'pads' => $fmt($money['pads']), 'paid_amount' => $fmt($money['paid_amount']), 'total' => $fmt($totalCents), 'remarks' => $remarks];
        $old = [];
        $changed = [];
        foreach ($new as $key => $value) {
            $was = $invoice[$key] === null ? '' : (string) $invoice[$key];
            $same = in_array($key, ['labor', 'pads', 'paid_amount', 'total'], true) ? $cents($was) === $cents($value) : $was === $value;
            if (!$same) {
                $old[$key] = $was;
                $changed[$key] = $value;
            }
        }
        if ($changed === []) {
            return ['ok' => true];
        }
        Database::transaction(static function () use ($id, $new, $old, $changed, $invoice): void {
            Database::execute(
                'UPDATE invoices SET invoice_date = ?, labor = ?, pads = ?, paid_amount = ?, total = ?, remarks = ?, updated_at = ? WHERE id = ?',
                [$new['invoice_date'], $new['labor'], $new['pads'], $new['paid_amount'], $new['total'], $new['remarks'] === '' ? null : $new['remarks'], gmdate('Y-m-d H:i:s'), $id]
            );
            AuditService::record('invoice_updated', 'invoice', $id, ['number' => self::number($invoice)] + $old, $changed);
        });
        return ['ok' => true];
    }

    /** @return array{ok:bool,error?:string} */
    public static function markPaid(int $id, string $date): array
    {
        $invoice = self::find($id);
        if ($invoice === null) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if (self::status($invoice) === 'void') {
            return ['ok' => false, 'error' => 'A voided invoice cannot be marked paid.'];
        }
        $date = trim($date) === '' ? Clock::today() : trim($date);
        if (CarrierStatement::parseDate($date, 'Paid date')['error'] !== null) {
            return ['ok' => false, 'error' => 'The paid date is not a valid date.'];
        }
        if ($date > Clock::today()) {
            return ['ok' => false, 'error' => 'The paid date cannot be in the future.'];
        }
        Database::transaction(static function () use ($id, $date, $invoice): void {
            Database::execute('UPDATE invoices SET paid_on = ?, updated_at = ? WHERE id = ?', [$date, gmdate('Y-m-d H:i:s'), $id]);
            AuditService::record('invoice_paid', 'invoice', $id, ['paid_on' => (string) ($invoice['paid_on'] ?? '')], ['number' => self::number($invoice), 'paid_on' => $date]);
        });
        return ['ok' => true];
    }

    /** @return array{ok:bool,error?:string} */
    public static function markOpen(int $id): array
    {
        $invoice = self::find($id);
        if ($invoice === null) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if (self::status($invoice) === 'void') {
            return ['ok' => false, 'error' => 'A voided invoice cannot be reopened.'];
        }
        if ($invoice['paid_on'] === null) {
            return ['ok' => true];
        }
        Database::transaction(static function () use ($id, $invoice): void {
            Database::execute('UPDATE invoices SET paid_on = NULL, updated_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), $id]);
            AuditService::record('invoice_reopened', 'invoice', $id, ['number' => self::number($invoice), 'paid_on' => (string) $invoice['paid_on']], ['paid_on' => '']);
        });
        return ['ok' => true];
    }

    /**
     * Cancel an invoice made by mistake: it stays on file (the number is never reused) and its jobs can be invoiced again.
     * A paid invoice has to be marked unpaid first.
     *
     * @return array{ok:bool,error?:string}
     */
    public static function void(int $id, string $reason): array
    {
        $invoice = self::find($id);
        if ($invoice === null) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if (self::status($invoice) === 'void') {
            return ['ok' => false, 'error' => 'This invoice is already voided.'];
        }
        if (self::status($invoice) === 'paid') {
            return ['ok' => false, 'error' => 'A paid invoice cannot be voided. Mark it unpaid first.'];
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 255) {
            return ['ok' => false, 'error' => 'Give a reason for voiding the invoice (up to 255 characters).'];
        }
        Database::transaction(static function () use ($id, $reason, $invoice): void {
            Database::execute('UPDATE invoices SET voided_at = ?, void_reason = ?, updated_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), $reason, gmdate('Y-m-d H:i:s'), $id]);
            AuditService::record('invoice_voided', 'invoice', $id, ['number' => self::number($invoice)], ['reason' => $reason]);
        });
        return ['ok' => true];
    }

    /** The company invoices made from a driver invoice. @return array<int,array<string,mixed>> */
    public static function children(int $id): array
    {
        return Database::fetchAll('SELECT * FROM invoices WHERE source_invoice_id = ? ORDER BY id ASC', [$id]);
    }

    /**
     * Make one company invoice (CI) per company (the job's broker) from the lines of a driver invoice (DI), copying the saved lines (the jobs
     * are not read again). Remarks and dates are copied; Labor, Pads and Paid start at 0 on each company invoice (copying them to every one
     * would count the same cost several times) and can be changed afterwards. Lines with no company are left out. It is refused while
     * company invoices made from this driver invoice are still open, so it cannot be done twice by accident (void them first to redo it).
     *
     * @return array{ok:bool,error?:string,numbers?:array<int,string>,skipped?:int}
     */
    public static function generateCompanyInvoices(int $id): array
    {
        $di = self::find($id);
        if ($di === null) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if ($di['type'] !== 'DI') {
            return ['ok' => false, 'error' => 'Company invoices are made from a driver invoice.'];
        }
        if (self::status($di) === 'void') {
            return ['ok' => false, 'error' => 'A voided invoice cannot be split into company invoices.'];
        }
        $made = array_values(array_filter(self::children($id), static fn (array $c): bool => self::status($c) !== 'void'));
        if ($made !== []) {
            return ['ok' => false, 'error' => 'Company invoices were already made from ' . self::number($di) . ': ' . implode(', ', array_map([self::class, 'number'], $made)) . '. Void them first to make them again.'];
        }

        $groups = [];
        $names = [];
        $skipped = 0;
        foreach (self::lines($id) as $line) {
            $company = trim((string) ($line['broker'] ?? ''));
            if ($company === '') {
                $skipped++;
                continue;
            }
            $key = mb_strtolower($company);
            $names[$key] ??= $company;
            $groups[$key][] = $line;
        }
        if ($groups === []) {
            return ['ok' => false, 'error' => 'None of the jobs on this invoice has a company (broker), so there is nothing to split.'];
        }

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $created = Database::transaction(static fn (): array => self::insertCompanyInvoices($di, $groups, $names));
                $numbers = [];
                foreach ($created as $invoiceId) {
                    $invoice = self::find($invoiceId) ?? [];
                    $numbers[] = self::number($invoice);
                    AuditService::record('invoice_created', 'invoice', $invoiceId, null, [
                        'number' => self::number($invoice), 'company' => (string) $invoice['broker'], 'from' => self::number($di), 'total' => (string) $invoice['total'],
                    ]);
                }
                return ['ok' => true, 'numbers' => $numbers, 'skipped' => $skipped];
            } catch (\PDOException $e) {
                if ((string) $e->getCode() !== '23000' || $attempt === 3) {
                    throw $e;
                }
            }
        }
        return ['ok' => false, 'error' => 'The company invoices could not be saved. Please try again.'];
    }

    /**
     * @param array<string,mixed> $di
     * @param array<string,array<int,array<string,mixed>>> $groups
     * @param array<string,string> $names
     * @return array<int,int> the new invoice ids
     */
    private static function insertCompanyInvoices(array $di, array $groups, array $names): array
    {
        $cents = static fn (mixed $v): int => $v === null || $v === '' ? 0 : (int) round((float) $v * 100);
        $fmt = static fn (int $c): string => number_format($c / 100, 2, '.', '');
        $now = gmdate('Y-m-d H:i:s');
        $ids = [];
        foreach ($groups as $key => $lines) {
            $sub = 0;
            $fee = 0;
            $cf = 0;
            foreach ($lines as $l) {
                $sub += $cents($l['balance']);
                $fee += $cents($l['fee']);
                $cf += $cents($l['cf']);
            }
            $seq = (int) Database::fetchValue("SELECT COALESCE(MAX(seq), 0) + 1 FROM invoices WHERE type = 'CI'");
            $id = Database::insert(
                'INSERT INTO invoices (type, seq, invoice_date, carrier, driver, broker, driver_phone, period_from, period_to, total_cf, subtotal, carrier_fee, labor, pads, paid_amount, total, remarks, source_invoice_id, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?, ?, ?, ?, ?)',
                [
                    'CI', $seq, $di['invoice_date'], $di['carrier'], $di['driver'], $names[$key], $di['driver_phone'], $di['period_from'], $di['period_to'],
                    $fmt($cf), $fmt($sub), $fmt($fee), $fmt($sub - $fee), $di['remarks'], $di['id'], Auth::id(), $now, $now,
                ]
            );
            $order = 0;
            foreach ($lines as $l) {
                Database::execute(
                    'INSERT INTO invoice_lines (invoice_id, sort_order, job_id, job_number, status, customer, phone, from_location, to_location, broker, carrier, driver, cf, rate, fee, balance, remarks)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$id, ++$order, $l['job_id'], $l['job_number'], $l['status'], $l['customer'], $l['phone'], $l['from_location'], $l['to_location'], $l['broker'], $l['carrier'], $l['driver'], $l['cf'], $l['rate'], $l['fee'], $l['balance'], $l['remarks']]
                );
            }
            $ids[] = $id;
        }
        AuditService::record('invoice_companies_made', 'invoice', (int) $di['id'], null, ['number' => self::number($di), 'company_invoices' => count($ids)]);
        return $ids;
    }

    /**
     * The invoice list: a page of invoices and the totals of what matches.
     *
     * @param array{q?:string,status?:string,type?:string,from?:string,to?:string} $filters
     * @return array{rows:array<int,array<string,mixed>>,total:int,sum:string,open_sum:string}
     */
    public static function search(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = self::where($filters);
        $total = (int) Database::fetchValue("SELECT COUNT(*) FROM invoices i WHERE {$where}", $params);
        $rows = Database::fetchAll(
            "SELECT i.*, (SELECT COUNT(*) FROM invoice_lines il WHERE il.invoice_id = i.id) AS line_count FROM invoices i WHERE {$where} ORDER BY i.id DESC LIMIT {$limit} OFFSET {$offset}",
            $params
        );
        $sum = (string) Database::fetchValue("SELECT COALESCE(SUM(i.total), 0) FROM invoices i WHERE {$where} AND i.voided_at IS NULL", $params);
        $open = (string) Database::fetchValue("SELECT COALESCE(SUM(i.total), 0) FROM invoices i WHERE {$where} AND i.voided_at IS NULL AND i.paid_on IS NULL", $params);
        return ['rows' => $rows, 'total' => $total, 'sum' => number_format((float) $sum, 2, '.', ''), 'open_sum' => number_format((float) $open, 2, '.', '')];
    }

    /**
     * @param array{q?:string,status?:string,type?:string,from?:string,to?:string} $f
     * @return array{0:string,1:array<int,mixed>}
     */
    private static function where(array $f): array
    {
        $where = '1 = 1';
        $params = [];
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            if (preg_match('/^([A-Za-z]{2})-?(\d+)$/', $q, $m) === 1) {
                $where .= ' AND i.type = ? AND i.seq = ?';
                array_push($params, strtoupper($m[1]), (int) $m[2]);
            } else {
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($q)) . '%';
                $where .= " AND (LOWER(COALESCE(i.carrier, '')) LIKE ? OR LOWER(COALESCE(i.driver, '')) LIKE ? OR LOWER(COALESCE(i.broker, '')) LIKE ?)";
                array_push($params, $like, $like, $like);
            }
        }
        $type = (string) ($f['type'] ?? '');
        if (isset(self::TYPES[$type])) {
            $where .= ' AND i.type = ?';
            $params[] = $type;
        }
        $status = (string) ($f['status'] ?? '');
        if ($status === 'open') {
            $where .= ' AND i.voided_at IS NULL AND i.paid_on IS NULL';
        } elseif ($status === 'paid') {
            $where .= ' AND i.voided_at IS NULL AND i.paid_on IS NOT NULL';
        } elseif ($status === 'void') {
            $where .= ' AND i.voided_at IS NOT NULL';
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
            $date = (string) ($f[$key] ?? '');
            if ($date !== '' && CarrierStatement::parseDate($date, '')['error'] === null) {
                $where .= " AND i.invoice_date {$op} ?";
                $params[] = $date;
            }
        }
        return [$where, $params];
    }
}
