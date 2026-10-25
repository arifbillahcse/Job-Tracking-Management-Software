<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Validator;
use App\Repositories\ImportRepository;
use App\Repositories\JobRepository;
use App\Repositories\StatusHistoryRepository;
use App\Services\Import\ColumnMapper;
use App\Services\Import\CsvReader;
use App\Services\Import\ImportFileException;
use App\Services\Import\JobFields;
use App\Services\Import\ValueParser;
use App\Services\Import\XlsxReader;
use App\Validation\JobRules;
use PDOException;

/**
 * Bulk import of jobs from CSV or Excel in two steps:
 *
 *  1. stage(): the upload is checked (size, extension, real content type), stored under a random name outside the
 *     web root, read, mapped and validated row by row. Nothing is written to the jobs table. The result is the preview.
 *  2. commit(): after the user confirms, the stored file is read and validated AGAIN against the database as it is
 *     now, and the valid rows are inserted in one transaction. Invalid rows are skipped and kept in an error report.
 *
 * Rows are checked with the same rules as the job form. What a user may import follows what they may do on screen:
 * balance and storage fee need jobs.edit_financials; status, carrier, driver, phone and delivery date need jobs.status.
 */
final class ImportService
{
    public const MAX_ROWS = 5000;
    public const MAX_COLUMNS = 60;
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const EXPIRY_HOURS = 24;
    public const SAMPLE_ROWS = 15;

    private const CSV_TYPES = ['text/plain', 'text/csv', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel', 'application/x-empty', 'inode/x-empty'];
    private const XLSX_TYPES = ['application/zip', 'application/x-zip-compressed', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];

    public function __construct(
        private ImportRepository $batches = new ImportRepository(),
        private JobRepository $jobs = new JobRepository(),
        private StatusHistoryRepository $history = new StatusHistoryRepository()
    ) {
    }

    /** Largest file accepted: our limit, or what PHP is configured to receive, whichever is smaller. */
    public static function maxBytes(): int
    {
        $limits = [self::MAX_BYTES];
        foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
            $bytes = self::iniBytes((string) ini_get($setting));
            if ($bytes > 0) {
                $limits[] = $bytes;
            }
        }
        return min($limits);
    }

    /** @return array<int,string> */
    public static function formats(): array
    {
        return XlsxReader::available() ? ['csv', 'xlsx'] : ['csv'];
    }

    /**
     * @param array{name:string,tmp_name:string,size:int,error:int,type:string}|null $file one entry of $_FILES
     * @return array{ok:bool,error?:string,batch?:int}
     */
    public function stage(?array $file, int $userId): array
    {
        if ($file === null || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'error' => 'Choose a CSV or Excel file to upload.'];
        }
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            return ['ok' => false, 'error' => 'That file is larger than the ' . self::megabytes(self::maxBytes()) . ' limit.'];
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'The upload failed. Please try again.'];
        }
        if ($file['size'] <= 0 || !is_file($file['tmp_name'])) {
            return ['ok' => false, 'error' => 'The file is empty.'];
        }
        if ($file['size'] > self::maxBytes()) {
            return ['ok' => false, 'error' => 'That file is larger than the ' . self::megabytes(self::maxBytes()) . ' limit.'];
        }

        $originalName = $this->cleanName($file['name']);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::formats(), true)) {
            return ['ok' => false, 'error' => 'Only ' . implode(' or ', array_map(static fn (string $f): string => '.' . $f, self::formats())) . ' files can be imported.'];
        }

        // The extension is only a claim. Look at what the file really is.
        $detected = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $allowed = $extension === 'xlsx' ? self::XLSX_TYPES : self::CSV_TYPES;
        if (!in_array($detected, $allowed, true)) {
            Logger::warning('Import upload rejected', ['name' => $originalName, 'detected' => $detected, 'user_id' => $userId]);
            return ['ok' => false, 'error' => 'That file is not really a ' . strtoupper($extension) . ' file (it looks like ' . $detected . ').'];
        }

        $directory = BASE_PATH . '/storage/imports';
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return ['ok' => false, 'error' => 'The server cannot store uploads right now.'];
        }
        $stored = bin2hex(random_bytes(16)) . '.' . $extension;
        $target = $directory . '/' . $stored;

        // Real uploads are moved with move_uploaded_file (which proves PHP received them). Plain copy is for the CLI test runner only.
        $moved = is_uploaded_file($file['tmp_name'])
            ? move_uploaded_file($file['tmp_name'], $target)
            : (PHP_SAPI === 'cli' && copy($file['tmp_name'], $target));
        if (!$moved) {
            return ['ok' => false, 'error' => 'The upload could not be saved.'];
        }
        @chmod($target, 0600);

        try {
            $analysis = $this->analyse($target, $extension);
        } catch (ImportFileException $e) {
            @unlink($target);
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $batchId = Database::transaction(function () use ($userId, $originalName, $stored, $extension, $analysis): int {
            $id = $this->batches->create($userId, $originalName, $stored, $extension, [
                'total' => $analysis['total'],
                'valid' => count($analysis['valid']),
                'invalid' => count($analysis['invalid']),
                'headers' => $analysis['headers'],
                'mapping' => $analysis['mapping_labels'],
                'ignored' => $analysis['ignored'],
                'warnings' => $analysis['warnings'],
                'sample' => array_slice(array_column($analysis['valid'], 'display'), 0, self::SAMPLE_ROWS),
            ]);
            $this->batches->replaceErrors($id, $analysis['invalid']);
            return $id;
        });

        return ['ok' => true, 'batch' => $batchId];
    }

    /**
     * Confirm a previewed batch: re-validate against the current database and insert the valid rows in one transaction.
     *
     * @return array{ok:bool,code?:string,imported?:int,skipped?:int}  code: not_found | already | expired | file_error
     */
    public function commit(int $batchId, int $userId): array
    {
        $batch = $this->batches->findForUser($batchId, $userId);
        if ($batch === null) {
            return ['ok' => false, 'code' => 'not_found'];
        }
        if ($batch['status'] !== 'previewed') {
            return ['ok' => false, 'code' => 'already'];
        }

        $path = BASE_PATH . '/storage/imports/' . $batch['stored_name'];
        $expired = strtotime((string) $batch['created_at'] . ' UTC') < time() - self::EXPIRY_HOURS * 3600;
        if ($expired || !is_file($path)) {
            $this->discard($batch, 'expired');
            return ['ok' => false, 'code' => 'expired'];
        }

        // Claim the batch so a double click or a second tab cannot import it twice.
        if (!$this->batches->transition($batchId, 'previewed', 'importing')) {
            return ['ok' => false, 'code' => 'already'];
        }

        try {
            $analysis = $this->analyse($path, (string) $batch['file_format']);
        } catch (ImportFileException $e) {
            $this->batches->transition($batchId, 'importing', 'previewed');
            return ['ok' => false, 'code' => 'file_error'];
        }

        $imported = 0;
        $lateDuplicates = [];

        try {
            Database::transaction(function () use ($analysis, $userId, $batch, &$imported, &$lateDuplicates): void {
                foreach ($analysis['valid'] as $row) {
                    try {
                        $id = $this->jobs->create($row['job'], $userId);
                    } catch (PDOException $e) {
                        if ((string) $e->getCode() === '23000' && str_contains($e->getMessage(), 'job_number')) {
                            $lateDuplicates[] = $row; // created by someone else a moment ago
                            continue;
                        }
                        throw $e;
                    }
                    if ($row['job']['status'] !== 'booked') {
                        $this->history->insert($id, 'booked', $row['job']['status'], 'Imported from ' . $batch['original_name'], $row['history'], $userId);
                    }
                    $imported++;
                }
            });
        } catch (\Throwable $e) {
            $this->batches->transition($batchId, 'importing', 'previewed');
            throw $e;
        }

        $invalid = $analysis['invalid'];
        foreach ($lateDuplicates as $row) {
            $invalid[] = [
                'row' => $row['row'],
                'job_number' => $row['job']['job_number'],
                'messages' => ['Job # was created by someone else while this import was running.'],
                'raw' => $row['raw'],
            ];
        }
        usort($invalid, static fn (array $a, array $b): int => $a['row'] <=> $b['row']);

        $this->batches->replaceErrors($batchId, $invalid);
        $this->batches->update($batchId, [
            'status' => 'committed',
            'imported_rows' => $imported,
            'skipped_rows' => count($invalid),
            'valid_rows' => $imported,
            'invalid_rows' => count($invalid),
            'committed_at' => gmdate('Y-m-d H:i:s'),
            'stored_name' => null,
        ]);
        @unlink($path);

        AuditService::record('jobs_imported', 'import', $batchId, null, [
            'file' => $batch['original_name'], 'imported' => $imported, 'skipped' => count($invalid), 'rows' => $analysis['total'],
        ]);

        return ['ok' => true, 'imported' => $imported, 'skipped' => count($invalid)];
    }

    public function cancel(int $batchId, int $userId): bool
    {
        $batch = $this->batches->findForUser($batchId, $userId);
        if ($batch === null || $batch['status'] !== 'previewed') {
            return false;
        }
        $this->discard($batch, 'cancelled');
        return true;
    }

    /**
     * Read, map and validate a file. Pure: touches no tables except to look up existing job numbers.
     *
     * @return array{
     *   total:int, headers:array<int,string>, mapping_labels:array<string,string>, ignored:array<int,string>, warnings:array<int,string>,
     *   valid:array<int,array{row:int,job:array<string,mixed>,history:array<string,mixed>|null,display:array<string,string>,raw:array<int,string>}>,
     *   invalid:array<int,array{row:int,job_number:?string,messages:array<int,string>,raw:array<int,string>}>
     * }
     * @throws ImportFileException
     */
    public function analyse(string $path, string $format): array
    {
        $context = ['financials' => Auth::can('jobs.edit_financials'), 'workflow' => Auth::can('jobs.status')];
        $spreadsheet = $format === 'xlsx';

        $data = $spreadsheet
            ? XlsxReader::read($path, self::MAX_ROWS, self::MAX_COLUMNS)
            : CsvReader::read($path, self::MAX_ROWS, self::MAX_COLUMNS);

        $map = ColumnMapper::map($data['headers']);
        if ($map['missing'] !== []) {
            throw new ImportFileException(
                'The file is missing required column(s): ' . implode(', ', array_map([JobFields::class, 'label'], $map['missing']))
                . '. Download the template to see the expected headings.'
            );
        }
        if ($map['duplicates'] !== []) {
            $parts = [];
            foreach ($map['duplicates'] as $field => $headers) {
                $parts[] = JobFields::label($field) . ' (' . implode(', ', $headers) . ')';
            }
            throw new ImportFileException('More than one column matches the same field: ' . implode('; ', $parts) . '. Remove or rename the extra column.');
        }

        $warnings = [];
        if ($map['ignored'] !== []) {
            $warnings[] = 'Ignored column(s) that do not match any job field: ' . implode(', ', $map['ignored']) . '.';
        }

        $mapping = $map['mapping'];
        foreach ($mapping as $index => $field) {
            $spec = JobFields::all()[$field];
            if (($spec['financial'] && !$context['financials']) || ($spec['workflow'] && !$context['workflow'])) {
                $warnings[] = $spec['label'] . ' column ignored: you do not have permission to import ' . ($spec['financial'] ? 'financial figures' : 'status, carrier or delivery details') . '.';
                unset($mapping[$index]);
            }
        }

        $labels = [];
        foreach ($map['mapping'] as $index => $field) {
            $labels[(string) $data['headers'][$index]] = JobFields::label($field);
        }

        $valid = [];
        $invalid = [];
        $seen = [];

        foreach ($data['rows'] as [$rowNo, $cells]) {
            $values = [];
            foreach ($mapping as $index => $field) {
                $values[$field] = (string) ($cells[$index] ?? '');
            }
            [$job, $errors, $display] = $this->validateRow($values, $context, $spreadsheet);

            $number = $job['job_number'] ?? null;
            if ($number !== null && $errors === []) {
                $key = strtolower((string) $number);
                if (isset($seen[$key])) {
                    $errors[] = 'Job # ' . $number . ' appears again (first on row ' . $seen[$key] . ').';
                } else {
                    $seen[$key] = $rowNo;
                }
            }

            if ($errors !== []) {
                $invalid[$rowNo] = ['row' => $rowNo, 'job_number' => $number === null ? null : (string) $number, 'messages' => $errors, 'raw' => $cells];
                continue;
            }

            $status = (string) $job['status'];
            $valid[$rowNo] = [
                'row' => $rowNo,
                'job' => $job,
                'history' => in_array($status, ['in_transit', 'delivered'], true)
                    ? array_filter(['carrier_name' => $job['carrier_name'], 'driver_name' => $job['driver_name'], 'driver_phone' => $job['driver_phone'], 'loaded_date' => $job['loaded_date'], 'delivery_date' => $job['delivery_date']])
                    : null,
                'display' => $display,
                'raw' => $cells,
            ];
        }

        // One query per 500 numbers instead of one per row.
        $existing = $this->jobs->existingNumbers(array_map(static fn (array $r): string => (string) $r['job']['job_number'], $valid));
        foreach ($valid as $rowNo => $row) {
            if (isset($existing[strtolower((string) $row['job']['job_number'])])) {
                $invalid[$rowNo] = [
                    'row' => $rowNo,
                    'job_number' => (string) $row['job']['job_number'],
                    'messages' => ['Job # ' . $row['job']['job_number'] . ' already exists (it may belong to a deleted job).'],
                    'raw' => $row['raw'],
                ];
                unset($valid[$rowNo]);
            }
        }

        ksort($invalid);
        return [
            'total' => count($data['rows']),
            'headers' => $data['headers'],
            'mapping_labels' => $labels,
            'ignored' => $map['ignored'],
            'warnings' => $warnings,
            'valid' => array_values($valid),
            'invalid' => array_values($invalid),
        ];
    }

    /**
     * Normalise and validate one row with the same rules as the job form.
     *
     * @param array<string,string> $values field => raw text, for the columns present in the file
     * @param array{financials:bool,workflow:bool} $context
     * @return array{0:array<string,mixed>,1:array<int,string>,2:array<string,string>} job row (for the database), error messages, display values
     */
    private function validateRow(array $values, array $context, bool $spreadsheet): array
    {
        $errors = [];
        $clean = [];

        foreach (JobFields::all() as $key => $spec) {
            if (!array_key_exists($key, $values)) {
                continue;
            }
            $raw = $values[$key];
            $shown = mb_strlen($raw) > 40 ? mb_substr($raw, 0, 40) . '…' : $raw;

            $parsed = match ($spec['type']) {
                JobFields::DATE => ValueParser::date($raw, $spreadsheet),
                JobFields::MONEY, JobFields::NUMBER => ValueParser::number($raw),
                JobFields::STATUS => ValueParser::status($raw),
                default => in_array($key, ['job_number', 'driver_phone', 'customer_phone'], true) ? ValueParser::wholeNumberText($raw) : ValueParser::text($raw),
            };

            if ($parsed === false) {
                $errors[] = match ($spec['type']) {
                    JobFields::DATE => "{$spec['label']}: \"{$shown}\" is not a valid date (use YYYY-MM-DD or MM/DD/YYYY).",
                    JobFields::STATUS => "{$spec['label']}: \"{$shown}\" is not a status (Booked, In Transit, Delivered, On Hold or Cancelled).",
                    default => "{$spec['label']}: \"{$shown}\" is not a number.",
                };
                $parsed = null;
            }
            $clean[$key] = $parsed;
        }

        $status = (string) ($clean['status'] ?? 'booked');
        $clean['status'] = $status;

        $rules = JobRules::rules(null, $context['financials'], false, false);
        if (in_array($status, ['in_transit', 'delivered'], true)) {
            $rules += JobRules::dispatchRules();
        }
        if (in_array($status, ['in_transit', 'delivered'], true)) {
            $rules['loaded_date'] = 'nullable|date';
        }
        if ($status === 'delivered') {
            $rules['delivered_on'] = 'required|date';
        }

        $labels = array_map(static fn (array $spec): string => $spec['label'], JobFields::all());
        $validator = Validator::make($clean, $rules, $labels);
        foreach ($validator->errors() as $field => $messages) {
            // A field whose text could not even be parsed was already reported above.
            if (array_key_exists($field, $clean) && $clean[$field] === null && $this->alreadyReported($errors, $labels[$field] ?? $field)) {
                continue;
            }
            array_push($errors, ...$messages);
        }

        if ($status === 'delivered' && isset($clean['delivered_on']) && (string) $clean['delivered_on'] > Clock::today()) {
            $errors[] = 'Delivered Date: cannot be in the future.';
        }

        $decimal = static fn (mixed $v): ?string => ($v === null || $v === '') ? null : number_format((float) $v, 2, '.', '');
        $dispatched = in_array($status, ['in_transit', 'delivered'], true);

        $job = [
            'job_number' => $clean['job_number'] ?? null,
            'customer_first_name' => $clean['customer_first_name'] ?? null,
            'customer_last_name' => $clean['customer_last_name'] ?? null,
            'customer_phone' => $clean['customer_phone'] ?? null,
            'pickup_date' => $clean['pickup_date'] ?? null,
            'first_available_date' => $clean['first_available_date'] ?? null,
            'from_location' => $clean['from_location'] ?? null,
            'to_location' => $clean['to_location'] ?? null,
            'job_location' => $clean['job_location'] ?? null,
            'cf' => $decimal($clean['cf'] ?? null),
            'balance' => $decimal($clean['balance'] ?? null) ?? '0.00',
            'storage_fee' => '0.00',
            'storage_fee_override' => $decimal($clean['storage_fee'] ?? null),
            'notes' => $clean['notes'] ?? null,
            'status' => $status,
            'carrier_name' => $dispatched ? ($clean['carrier_name'] ?? null) : null,
            'driver_name' => $dispatched ? ($clean['driver_name'] ?? null) : null,
            'driver_phone' => $dispatched ? ($clean['driver_phone'] ?? null) : null,
            'in_transit_at' => $dispatched ? gmdate('Y-m-d H:i:s') : null,
            'loaded_date' => $dispatched ? ($clean['loaded_date'] ?? null) : null,
            'delivery_date' => $status === 'delivered' && !empty($clean['delivered_on']) ? $clean['delivered_on'] : null,
            'delivered_at' => $status === 'delivered' && !empty($clean['delivered_on']) ? $clean['delivered_on'] . ' 00:00:00' : null,
        ];

        // Storage fee is calculated, never typed (a number in the file is an override).
        $assessed = StorageFeeService::assess($job);
        $job['storage_fee'] = $assessed['fee'];
        $job['storage_rate'] = $assessed['rate'];

        $display = [];
        foreach (['job_number', 'customer_first_name', 'customer_last_name', 'first_available_date', 'from_location', 'to_location', 'status'] as $field) {
            $display[$field] = (string) ($job[$field] ?? '');
        }

        return [$job, $errors, $display];
    }

    /**
     * @param array<int,string> $errors
     */
    private function alreadyReported(array $errors, string $label): bool
    {
        foreach ($errors as $error) {
            if (str_starts_with($error, $label . ':')) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $batch
     */
    private function discard(array $batch, string $status): void
    {
        if (!empty($batch['stored_name'])) {
            @unlink(BASE_PATH . '/storage/imports/' . $batch['stored_name']);
        }
        $this->batches->update((int) $batch['id'], ['status' => $status, 'stored_name' => null]);
    }

    private function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $name);
        return mb_substr($name === '' ? 'upload' : $name, 0, 200);
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return 0;
        }
        $number = (int) $value;
        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private static function megabytes(int $bytes): string
    {
        return round($bytes / 1024 / 1024, 1) . ' MB';
    }
}
