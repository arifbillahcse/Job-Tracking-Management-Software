<?php

declare(strict_types=1);

/**
 * Phase 5 tests: CSV / Excel import (stage, preview, confirm) and export. Included by tests/run.php.
 */

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Database;
use App\Core\Request;
use App\Repositories\ImportRepository;
use App\Repositories\JobRepository;
use App\Repositories\StatusHistoryRepository;
use App\Services\ExportService;
use App\Services\Import\ColumnMapper;
use App\Services\Import\CsvReader;
use App\Services\Import\ImportFileException;
use App\Services\Import\JobFields;
use App\Services\Import\ValueParser;
use App\Services\Import\XlsxReader;
use App\Services\Import\XlsxWriter;
use App\Services\ImportService;
use App\Services\JobBucket;
use App\Services\JobCriteria;

// ---------------------------------------------------------------- helpers

/** Write $content to a temp file with the given extension and return its path. */
function tmp_file(string $content, string $ext = 'csv'): string
{
    $path = tempnam(sys_get_temp_dir(), 'jt') . '.' . $ext;
    file_put_contents($path, $content);
    return $path;
}

/** A $_FILES-style entry for a file on disk. */
function upload_of(string $path, ?string $name = null, int $error = UPLOAD_ERR_OK): array
{
    return ['name' => $name ?? basename($path), 'tmp_name' => $path, 'size' => (int) @filesize($path), 'error' => $error, 'type' => 'application/octet-stream'];
}

/** Build CSV text from rows (first row = headings). */
function csv_text(array $rows, string $delimiter = ','): string
{
    $h = fopen('php://memory', 'r+');
    foreach ($rows as $row) {
        fputcsv($h, $row, $delimiter, '"', '');
    }
    rewind($h);
    return (string) stream_get_contents($h);
}

const CSV_HEAD = ['Job #', 'Customer First Name', 'Customer Last Name', 'Pickup Date', '1st Available Date', 'From', 'To', 'CF', 'Balance'];

function csv_row(string $number, array $override = []): array
{
    return array_values($override + [
        'Job #' => $number, 'Customer First Name' => 'Ada', 'Customer Last Name' => 'Smith', 'Pickup Date' => '2026-11-01',
        '1st Available Date' => '2026-12-01', 'From' => 'Austin, TX', 'To' => 'Denver, CO', 'CF' => '900', 'Balance' => '1200.50',
    ]);
}

function import_job_numbers(): array
{
    $n = array_column(Database::fetchAll('SELECT job_number FROM jobs WHERE deleted_at IS NULL ORDER BY job_number'), 'job_number');
    return $n;
}

/** Stage a CSV given as rows; returns the service result. */
function stage_rows(array $rows, int $userId, string $name = 'jobs.csv'): array
{
    return (new ImportService())->stage(upload_of(tmp_file(csv_text($rows)), $name), $userId);
}

foreach (glob(BASE_PATH . '/storage/imports/*') ?: [] as $leftover) {
    @unlink($leftover); // start from an empty upload folder
}

// ================================================================ column matching

section('Import: column matching');
$client = ['job#', 'customer Fname', 'customer L name', 'pickupdate', '1st availble date', 'from', 'To', 'cf', 'Balance', 'job location', 'carrier name', 'driver', 'phone', 'storage fee'];
$m = ColumnMapper::map($client);
check($m['missing'] === [] && $m['ignored'] === [] && count($m['mapping']) === 14, "every heading in the client's own sheet (typos included) is recognised");
check(array_values($m['mapping']) === ['job_number', 'customer_first_name', 'customer_last_name', 'pickup_date', 'first_available_date', 'from_location', 'to_location', 'cf', 'balance', 'job_location', 'carrier_name', 'driver_name', 'driver_phone', 'storage_fee'], '...to the right fields, in order');
foreach ([['Job Number', 'job_number'], ['JOB NO', 'job_number'], ['  job_id ', 'job_number'], ['First Name', 'customer_first_name'], ['Customer Last Name', 'customer_last_name'], ['Pickup', 'pickup_date'], ['Available Date', 'first_available_date'], ['First Available', 'first_available_date'], ['Phone Number', 'driver_phone'], ['Cubic Feet', 'cf'], ['Location', 'job_location'], ['Comments', 'notes'], ['Delivered Date', 'delivered_on'], ["\xEF\xBB\xBFJob #", 'job_number']] as [$h, $f]) {
    check(JobFields::match($h) === $f, "heading \"{$h}\" -> {$f}");
}
check(JobFields::match('Random Column') === null && JobFields::match('') === null && JobFields::match('###') === null, 'unrelated headings match nothing');
$m = ColumnMapper::map(['Job #', 'Notes', 'Favourite Colour', '', 'First Name']);
check($m['ignored'] === ['Favourite Colour'] && in_array('customer_last_name', $m['missing'], true) && !in_array('job_number', $m['missing'], true), 'unknown headings are ignored; missing required columns are listed');
$m = ColumnMapper::map(['Job #', 'Job Number', 'First Name', 'Last Name', 'Available', 'From', 'To']);
check(array_keys($m['duplicates']) === ['job_number'] && count($m['mapping']) === 6, 'two columns claiming one field are reported as duplicates');
check(JobFields::required() === ['job_number', 'customer_first_name', 'customer_last_name', 'first_available_date', 'from_location', 'to_location'], 'required fields match the job form');

section('Import: value parsing');
foreach ([['2026-11-01', '2026-11-01'], ['2026/11/1', '2026-11-01'], ['11/01/2026', '2026-11-01'], ['11-1-2026', '2026-11-01'], ['25/12/2026', '2026-12-25'], ['1/2/26', '2026-01-02'], ['Nov 6, 2026', '2026-11-06'], ['November 6, 2026', '2026-11-06'], ['6 Nov 2026', '2026-11-06'], ['2026-11-01 14:30:00', '2026-11-01'], ['2026-11-01T09:00:00', '2026-11-01'], ['', null]] as [$in, $out]) {
    check(ValueParser::date($in) === $out, "date \"{$in}\" -> " . var_export($out, true));
}
foreach (['2026-02-30', '13/45/2026', '31/31/2026', 'tomorrow', '2026-13-01', '00/00/0000', '44866'] as $bad) {
    check(ValueParser::date($bad) === false, "date \"{$bad}\" is refused");
}
check(ValueParser::date('44866', true) === '2022-11-01' && ValueParser::date('46327', true) === '2026-11-01' && ValueParser::date('45351.75', true) === '2024-02-29', 'Excel serial numbers become dates (spreadsheet files only)');
check(ValueParser::date('0', true) === false && ValueParser::date('99999999', true) === false, 'absurd serial numbers are refused');
foreach ([['$1,200.50', '1200.50'], ['1200.5', '1200.5'], [' 900 ', '900'], ['€45', '45'], ['1,000', '1000'], ['12,345,678.90', '12345678.90'], ['', null], ['0', '0']] as [$in, $out]) {
    check(ValueParser::number($in) === $out, "number \"{$in}\" -> " . var_export($out, true));
}
foreach (['1.200,50', 'abc', '(5)', '1,2,3', '12e3', '=1+1', '$'] as $bad) {
    check(ValueParser::number($bad) === false, "number \"{$bad}\" is refused");
}
foreach ([['Booked', 'booked'], ['IN TRANSIT', 'in_transit'], ['in_transit', 'in_transit'], ['Delivered', 'delivered'], ['on hold', 'on_hold'], ['Canceled', 'cancelled'], ['cancelled', 'cancelled'], ['', null]] as [$in, $out]) {
    check(ValueParser::status($in) === $out, "status \"{$in}\" -> " . var_export($out, true));
}
check(ValueParser::status('lost') === false && ValueParser::status('booked; DROP') === false, 'unknown statuses are refused');
check(ValueParser::text("  hello\x00\x07 world  ") === 'hello world' && ValueParser::text("   ") === null, 'text is trimmed and control characters removed');
check(ValueParser::text("'=1+1") === '=1+1' && ValueParser::text("'+44 20") === '+44 20' && ValueParser::text("'normal") === "'normal", 'the apostrophe our export adds before = + - @ is removed on import, other apostrophes are kept');
check(ValueParser::wholeNumberText('1001.0') === '1001' && ValueParser::wholeNumberText('JT-1.0') === 'JT-1.0' && ValueParser::wholeNumberText('10.5') === '10.5', 'spreadsheet-style 1001.0 becomes 1001 (job numbers, phones)');

// ================================================================ readers

section('Import: reading CSV files');
$r = CsvReader::read(tmp_file("a,b\n1,2\n\n3,4\n"), 100, 10);
check($r['rows'] === [[2, ['1', '2']], [4, ['3', '4']]], 'blank lines are skipped but row numbers still match the sheet');
$r = CsvReader::read(tmp_file("a;b;c\n1;\"x;y\";3\n"), 100, 10);
check($r['headers'] === ['a', 'b', 'c'] && $r['rows'][0][1] === ['1', 'x;y', '3'], 'semicolon delimiter detected; quoted delimiters respected');
$r = CsvReader::read(tmp_file("a\tb\n1\t2\n"), 100, 10);
check($r['rows'][0][1] === ['1', '2'], 'tab delimiter detected');
$r = CsvReader::read(tmp_file("\xEF\xBB\xBFa,b\n\"multi\nline\",\"say \"\"hi\"\"\"\n"), 100, 10);
check($r['headers'] === ['a', 'b'] && $r['rows'][0][1] === ["multi\nline", 'say "hi"'], 'BOM stripped; quoted line breaks and doubled quotes handled');
$r = CsvReader::read(tmp_file("\xFF\xFE" . mb_convert_encoding("a,b\n1,Zoë\n", 'UTF-16LE')), 100, 10);
check($r['rows'][0][1] === ['1', 'Zoë'], 'UTF-16 ("Unicode text" from Excel) is converted');
$r = CsvReader::read(tmp_file("a,b\n1,caf\xE9\n"), 100, 10);
check($r['rows'][0][1] === ['1', 'café'], 'Windows-1252 (older Excel) is converted');
$r = CsvReader::read(tmp_file("a,b\r\n1,2\r\n3,4"), 100, 10);
check(count($r['rows']) === 2, 'Windows line endings and no trailing newline');
$thrown = static function (callable $fn): string {
    try {
        $fn();
    } catch (ImportFileException $e) {
        return $e->getMessage();
    }
    return '';
};
check(str_contains($thrown(static fn () => CsvReader::read(tmp_file(''), 10, 10)), 'empty'), 'an empty file is refused');
check(str_contains($thrown(static fn () => CsvReader::read(tmp_file("\n\n,,\n"), 10, 10)), 'no header'), 'a file of blank lines has no header');
check($thrown(static fn () => CsvReader::read(tmp_file("a,b\n"), 10, 10)) === '' && CsvReader::read(tmp_file("a,b\n"), 10, 10)['rows'] === [], 'a header-only file reads as zero rows');
check(str_contains($thrown(static fn () => CsvReader::read(tmp_file("a,b\n" . str_repeat("1,2\n", 11)), 10, 10)), 'more than 10 data rows'), 'the row limit is enforced');
check(str_contains($thrown(static fn () => CsvReader::read(tmp_file(implode(',', range(1, 12)) . "\n"), 10, 10)), 'more than 10 columns'), 'the column limit is enforced');
check(str_contains($thrown(static fn () => CsvReader::read(tmp_file("a,b\n1,\x00\x01\x02binary\n"), 10, 10)), 'binary'), 'binary data is refused');

section('Import: reading and writing Excel files');
check(XlsxReader::available(), 'the zip and XML extensions needed for Excel are present');
$f = tmp_file('', 'xlsx');
$n = XlsxWriter::write($f, 'Jobs', ['Job #', 'Name', 'Date', 'CF', 'Balance', 'Notes'], ['text', 'text', 'date', 'number', 'money', 'text'], [
    ['JT-1', 'Ada <&> "Q"', '2026-11-01', '900.5', '1200.50', '=SUM(A1)'],
    ['JT-2', 'Grace', null, null, null, "line1\nline2"],
    ['1001', 'Zoë 日本語', '2024-02-29', '12', '0', '  spaced  '],
]);
$x = XlsxReader::read($f, 100, 30);
check($n === 3 && $x['headers'] === ['Job #', 'Name', 'Date', 'CF', 'Balance', 'Notes'] && count($x['rows']) === 3, 'written workbook reads back (3 rows)');
check($x['rows'][0] === [2, ['JT-1', 'Ada <&> "Q"', '46327', '900.5', '1200.5', '=SUM(A1)']], 'text, XML-special characters, a date serial and numbers survive; a "formula-looking" text stays plain text');
check($x['rows'][1][1] === ['JT-2', 'Grace', '', '', '', "line1\nline2"], 'empty cells and line breaks');
check($x['rows'][2][1][1] === 'Zoë 日本語' && $x['rows'][2][1][2] === '45351', 'Unicode text and a leap-day serial');
$zip = new ZipArchive();
$zip->open($f);
$wellFormed = true;
for ($i = 0; $i < $zip->numFiles; $i++) {
    $dom = new DOMDocument();
    $wellFormed = $wellFormed && @$dom->loadXML((string) $zip->getFromIndex($i));
}
$sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
$zip->close();
check($wellFormed, 'every part of the generated workbook is well-formed XML');
check(str_contains($sheet, '<pane ySplit="1"') && str_contains($sheet, '<autoFilter ref="A1:F4"/>') && str_contains($sheet, 't="inlineStr"'), 'header row frozen, auto-filter over the data, text stored as strings (never as formulas)');

// a hand-made workbook the way Excel writes it: shared strings, rich text, sparse cells, a gap row, booleans, an inline string
$book = static function (string $sheetXml, ?string $shared = null, array $extra = []): string {
    $path = tempnam(sys_get_temp_dir(), 'wb') . '.xlsx';
    $z = new ZipArchive();
    $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
    $z->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Data" sheetId="1" r:id="rId7"/></sheets></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId7" Type="x" Target="worksheets/data.xml"/></Relationships>');
    $z->addFromString('xl/worksheets/data.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $sheetXml . '</sheetData></worksheet>');
    if ($shared !== null) {
        $z->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . $shared . '</sst>');
    }
    foreach ($extra as $name => $content) {
        $z->addFromString($name, $content);
    }
    $z->close();
    return $path;
};
$shared = '<si><t>Job #</t></si><si><t>Name</t></si><si><r><t>Ada </t></r><r><rPr><b/></rPr><t>Smith</t></r><rPh><t>PHONETIC</t></rPh></si><si><t>JT-9</t></si>';
$sheetXml = '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="D1" t="inlineStr"><is><t>Flag</t></is></c></row>'
    . '<row r="2"><c r="A2" t="s"><v>3</v></c><c r="B2" t="s"><v>2</v></c><c r="D2" t="b"><v>1</v></c></row>'
    . '<row r="3"/><row r="4"><c r="A4"><v>1001</v></c><c r="C4"><v>5</v></c></row>'
    . '<row r="7"><c r="A7" t="e"><v>#DIV/0!</v></c><c r="B7"/></row>';
$x = XlsxReader::read($book($sheetXml, $shared), 100, 30);
check($x['headers'] === ['Job #', 'Name', '', 'Flag'], 'shared strings and inline strings in the header; a gap column stays empty');
check($x['rows'][0] === [2, ['JT-9', 'Ada Smith', '', 'TRUE']], 'shared string with rich-text runs is joined, phonetic hints dropped; booleans read');
check($x['rows'][1] === [4, ['1001', '', '5']], 'sparse cells land in the right columns; empty rows are skipped but row numbers are real');
check(count($x['rows']) === 2, 'error cells and empty rows contribute nothing');

check(str_contains($thrown(static fn () => XlsxReader::read($book('<row r="1"><c r="A1"><v>x</v></c></row>' . str_repeat('<row r="2"><c r="A2"><v>1</v></c></row>', 11)), 10, 30)), 'more than 10 data rows'), 'the row limit applies to Excel too');
check(str_contains($thrown(static fn () => XlsxReader::read(tmp_file('this is not a zip', 'xlsx'), 10, 30)), 'not a valid'), 'a non-zip file is refused');
$z = tmp_file('', 'xlsx');
$zip = new ZipArchive();
$zip->open($z, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('readme.txt', 'hello');
$zip->close();
check(str_contains($thrown(static fn () => XlsxReader::read($z, 10, 30)), 'not an Excel'), 'a zip that is not a workbook is refused');
check(str_contains($thrown(static fn () => XlsxReader::read($book('', null, ['xl/sharedStrings.xml' => '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "boom">]><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>&a;</t></si></sst>']), 10, 30)), 'unsupported content'), 'XML with a DOCTYPE / entity declaration (XXE, entity bombs) is refused');
check(str_contains($thrown(static fn () => XlsxReader::read($book('<row r="1"><c r="A1" t="inlineStr"><is><t>a</t></is></c></row>', null, ['padding.bin' => str_repeat("\0", 31 * 1024 * 1024)]), 10, 30)), 'far larger'), 'a zip bomb (tiny file that unpacks huge) is refused before anything is unpacked');
$many = $book('');
$zip = new ZipArchive();
$zip->open($many);
for ($i = 0; $i < 210; $i++) {
    $zip->addFromString("junk/{$i}.txt", 'x');
}
$zip->close();
check(str_contains($thrown(static fn () => XlsxReader::read($many, 10, 30)), 'too many parts'), 'a zip with hundreds of entries is refused');
$damaged = 'no exception';
try {
    XlsxReader::read($book('<row r="1"><c r="A1" t="inlineStr"><is><t>a</t></is></c></row>', null, ['xl/sharedStrings.xml' => '<sst><si><t>unterminated']), 10, 30);
} catch (Throwable $e) {
    $damaged = get_class($e);
}
check(in_array($damaged, ['no exception', ImportFileException::class], true), 'damaged XML never produces anything but a clean import error (got: ' . $damaged . ')');
$wide = '<row r="1"><c r="A1" t="inlineStr"><is><t>a</t></is></c><c r="Z1" t="inlineStr"><is><t>late</t></is></c></row>';
check(str_contains($thrown(static fn () => XlsxReader::read($book($wide), 10, 5)), 'more than 5 columns'), 'a non-empty cell beyond the column limit is refused');

// ================================================================ staging an upload (security)

section('Import: upload checks');
fresh_db();
fresh_session();
Clock::fake('2026-10-23');
$admin = make_user('Admin', 'imp-admin@example.com');
$mgr = make_user('Manager', 'imp-mgr@example.com');
$staff = make_user('Staff', 'imp-stf@example.com');
sign_in($admin);
$svc = new ImportService();
$uid = (int) $admin['id'];
$good = tmp_file(csv_text([CSV_HEAD, csv_row('IMP-1')]));

check(!$svc->stage(null, $uid)['ok'] && str_contains($svc->stage(null, $uid)['error'], 'Choose'), 'no file chosen');
check(str_contains($svc->stage(upload_of($good, 'a.csv', UPLOAD_ERR_NO_FILE), $uid)['error'], 'Choose'), 'UPLOAD_ERR_NO_FILE');
check(str_contains($svc->stage(upload_of($good, 'a.csv', UPLOAD_ERR_INI_SIZE), $uid)['error'], 'larger than'), 'file larger than the server allows');
check(!$svc->stage(upload_of($good, 'a.csv', UPLOAD_ERR_PARTIAL), $uid)['ok'], 'a partial upload fails');
check(str_contains($svc->stage(upload_of(tmp_file(''), 'empty.csv'), $uid)['error'], 'empty'), 'an empty file');
$big = upload_of($good, 'big.csv');
$big['size'] = ImportService::maxBytes() + 1;
check(str_contains($svc->stage($big, $uid)['error'], 'larger than'), 'a file over the size limit');
check(ImportService::maxBytes() <= 5 * 1024 * 1024, 'the limit never exceeds 5 MB');
foreach (['evil.php', 'evil.exe', 'evil.html', 'old.xls', 'noext', 'archive.csv.zip', 'macro.xlsm', 'x.csv.php'] as $name) {
    check(!$svc->stage(upload_of($good, $name), $uid)['ok'], "extension of \"{$name}\" is refused");
}
check($svc->stage(upload_of($good, 'UPPER.CSV'), $uid)['ok'], 'extensions are case-insensitive');
$php = tmp_file("<?php system(\$_GET['c']); ?>\n", 'csv');
$r = $svc->stage(upload_of($php, 'harmless.csv'), $uid);
check(!$r['ok'] && str_contains($r['error'], 'not really a CSV'), 'a PHP script renamed to .csv is refused by its real content type');
check(!$svc->stage(upload_of(tmp_file("<html><script>alert(1)</script></html>", 'csv'), 'page.csv'), $uid)['ok'], 'HTML renamed to .csv is refused');
check(!$svc->stage(upload_of(tmp_file("%PDF-1.4\n%binary", 'csv'), 'doc.csv'), $uid)['ok'], 'a PDF renamed to .csv is refused');
check(!$svc->stage(upload_of($f, 'sheet.csv'), $uid)['ok'], 'an Excel file renamed to .csv is refused');
check(!$svc->stage(upload_of($good, 'sheet.xlsx'), $uid)['ok'], 'a CSV renamed to .xlsx is refused');
check(!$svc->stage(upload_of(tmp_file('PK-not-really', 'xlsx'), 'sheet.xlsx'), $uid)['ok'], 'garbage renamed to .xlsx is refused');

$before = glob(BASE_PATH . '/storage/imports/*') ?: [];
$r = $svc->stage(upload_of($good, "../../etc/passwd\x00.csv"), $uid);
$batch = (new ImportRepository())->findForUser((int) $r['batch'], $uid);
check($r['ok'] && !str_contains($batch['original_name'], '..') && !str_contains($batch['original_name'], '/') && !str_contains($batch['original_name'], "\0"), 'a path-traversal file name is neutralised');
check(preg_match('/^[0-9a-f]{32}\.csv$/', (string) $batch['stored_name']) === 1, 'the file is stored under a random name, not the uploaded one');
$stored = BASE_PATH . '/storage/imports/' . $batch['stored_name'];
check(is_file($stored) && (fileperms($stored) & 0777) === 0600, 'stored outside the web root, readable by the app only (0600)');
check(!str_contains(realpath($stored), '/public/'), 'never inside public/');
check(count(glob(BASE_PATH . '/storage/imports/*') ?: []) === count($before) + 1, 'exactly one stored file per upload');

$rejected = glob(BASE_PATH . '/storage/imports/*') ?: [];
$svc->stage(upload_of(tmp_file(csv_text([['Job #', 'Name']]), 'csv'), 'bad-headers.csv'), $uid);
check(count(glob(BASE_PATH . '/storage/imports/*') ?: []) === count($rejected), 'a file that fails analysis leaves nothing behind in storage');

// ================================================================ analysis and row validation

section('Import: row validation');
fresh_db();
fresh_session();
Clock::fake('2026-10-23');
$admin = make_user('Admin', 'imp-admin@example.com');
$mgr = make_user('Manager', 'imp-mgr@example.com');
$staff = make_user('Staff', 'imp-stf@example.com');
sign_in($admin);
$svc = new ImportService();
mk_job(['job_number' => 'EXIST-1']);
$trashed = mk_job(['job_number' => 'GONE-1']);
(new JobRepository())->softDelete($trashed, null);

$head = ['Job #', 'Customer First Name', 'Customer Last Name', 'Pickup Date', '1st Available Date', 'From', 'To', 'CF', 'Balance', 'Status', 'Carrier Name', 'Driver Name', 'Phone', 'Delivered Date', 'Junk Column'];
$row = static fn (array $o): array => array_map(static fn (string $h): string => (string) ($o[$h] ?? ''), $head);
$ok = ['Customer First Name' => 'Ada', 'Customer Last Name' => 'Smith', '1st Available Date' => '2026-12-01', 'From' => 'A', 'To' => 'B'];
$withOk = fn (array $o): array => $row($o + $ok); // overrides win (with +, the LEFT array wins)
$file = tmp_file(csv_text([
    $head,
    $withOk(['Job #' => 'OK-1', 'Balance' => '$1,200.50', 'CF' => '900', 'Pickup Date' => '11/01/2026']),                                  // row 2 valid, messy formats
    $withOk(['Job #' => 'OK-2', 'Status' => 'In Transit', 'Carrier Name' => 'C', 'Driver Name' => 'D', 'Phone' => '(555) 010-2231']),         // row 3 valid in transit
    $withOk(['Job #' => 'OK-3', 'Status' => 'delivered', 'Carrier Name' => 'C', 'Driver Name' => 'D', 'Phone' => '5550102231', 'Delivered Date' => '2026-10-20']), // row 4 valid delivered
    $withOk(['Job #' => 'BAD-DATE', '1st Available Date' => '2026-02-30']),                                                               // row 5
    $withOk(['Job #' => 'BAD-NUM', 'Balance' => 'lots', 'CF' => '-3']),                                                                    // row 6 two problems
    $row(['Job #' => 'NO-NAME', '1st Available Date' => '2026-12-01', 'From' => 'A', 'To' => 'B']),                                            // row 7 missing names
    $withOk(['Job #' => 'OK-1']),                                                                                                          // row 8 duplicate in file
    $withOk(['Job #' => 'exist-1']),                                                                                                       // row 9 exists (other case)
    $withOk(['Job #' => 'GONE-1']),                                                                                                        // row 10 exists but deleted
    $withOk(['Job #' => 'BAD STATUS', 'Status' => 'lost']),                                                                                // row 11
    $withOk(['Job #' => 'NO-CARRIER', 'Status' => 'In Transit']),                                                                          // row 12
    $withOk(['Job #' => 'NO-DELDATE', 'Status' => 'Delivered', 'Carrier Name' => 'C', 'Driver Name' => 'D', 'Phone' => '5550102231']),       // row 13
    $withOk(['Job #' => 'FUTURE', 'Status' => 'Delivered', 'Carrier Name' => 'C', 'Driver Name' => 'D', 'Phone' => '5550102231', 'Delivered Date' => '2026-11-30']), // row 14
    $withOk(['Job #' => 'BAD PHONE', 'Status' => 'In Transit', 'Carrier Name' => 'C', 'Driver Name' => 'D', 'Phone' => 'abc']),             // row 15
    $withOk(['Job #' => 'LONG-NAME', 'Customer First Name' => str_repeat('x', 101)]),                                                      // row 16
    $withOk(['Job #' => 'HAS SPACE!']),                                                                                                    // row 17
    $row([]),                                                                                                                                 // row 18 blank (skipped)
    $withOk(['Job #' => 'OK-4', 'Junk Column' => 'ignored']),                                                                              // row 19 valid
]));
$a = $svc->analyse($file, 'csv');
$valid = array_column($a['valid'], 'row');
$problems = array_column($a['invalid'], null, 'row');
check($valid === [2, 3, 4, 12, 19], 'exactly the five good rows are valid (rows 2, 3, 4, 12 and 19: carrier details are optional now)');
check($a['total'] === 17, 'the blank row is not counted (17 data rows)');
check(array_keys($problems) === [5, 6, 7, 8, 9, 10, 11, 13, 14, 15, 16, 17], 'every bad row is reported, with its real row number (heading = row 1)');
check(str_contains($problems[5]['messages'][0], 'not a valid date') && str_contains($problems[5]['messages'][0], '2026-02-30'), 'row 5: impossible date, quoting what was typed');
check(count($problems[6]['messages']) === 2 && str_contains(implode(' ', $problems[6]['messages']), 'Balance') && str_contains(implode(' ', $problems[6]['messages']), 'CF'), 'row 6: both problems on the row are listed');
check(str_contains(implode(' ', $problems[7]['messages']), 'Customer First Name is required') && str_contains(implode(' ', $problems[7]['messages']), 'Customer Last Name is required'), 'row 7: missing required values');
check(str_contains($problems[8]['messages'][0], 'appears again (first on row 2)'), 'row 8: duplicate inside the file points at the first occurrence');
check(str_contains($problems[9]['messages'][0], 'already exists') && str_contains($problems[10]['messages'][0], 'already exists'), 'rows 9-10: job # already in the system, in any letter case, even if that job was deleted');
check(str_contains($problems[11]['messages'][0], 'is not a status'), 'row 11: unknown status');
check(!isset($problems[12]) && $jobs12 = true, 'row 12: an In Transit job without carrier details is fine (they are optional)');
check(str_contains(implode(' ', $problems[13]['messages']), 'Delivered Date is required'), 'row 13: Delivered needs a date');
check(str_contains(implode(' ', $problems[14]['messages']), 'future'), 'row 14: a future delivery date');
check(isset($problems[15]) && isset($problems[16]) && str_contains(implode(' ', $problems[17]['messages']), 'Job #'), 'rows 15-17: bad phone, over-long name, job # with a space and "!"');
check($problems[7]['job_number'] === 'NO-NAME' && $problems[7]['raw'][0] === 'NO-NAME', 'each problem keeps the original cells for the report');
check(array_keys($a['mapping_labels']) === array_slice($head, 0, 14) && $a['ignored'] === ['Junk Column'] && count($a['warnings']) === 1 && str_contains($a['warnings'][0], 'Junk Column'), 'unrecognised columns are ignored and mentioned');
$jobs = array_column($a['valid'], 'job', 'row');
check($jobs[12]['status'] === 'in_transit' && $jobs[12]['carrier_name'] === null && $jobs[12]['loaded_date'] === null, 'row 12: stored as in transit with no carrier and no loaded date');
check($jobs[2]['pickup_date'] === '2026-11-01' && $jobs[2]['balance'] === '1200.50' && $jobs[2]['cf'] === '900.00' && $jobs[2]['status'] === 'booked' && $jobs[2]['in_transit_at'] === null, 'row 2: US date, $1,200.50 and a default status of Booked are normalised');
check($jobs[3]['status'] === 'in_transit' && $jobs[3]['carrier_name'] === 'C' && $jobs[3]['in_transit_at'] !== null && $jobs[3]['delivered_at'] === null, 'row 3: in transit with carrier details');
check($jobs[4]['status'] === 'delivered' && $jobs[4]['delivered_at'] === '2026-10-20 00:00:00' && $jobs[4]['driver_phone'] === '5550102231', 'row 4: delivered with its date');
check(count((new JobRepository())->fetch(JobCriteria::forBucket('all'), 100, 0)) === 1, 'analysing writes nothing (only EXIST-1 is in the jobs table)');

section('Import: file-level problems');
$msg = static fn (string $csv): string => $thrown(static fn () => (new ImportService())->analyse(tmp_file($csv), 'csv'));
check(str_contains($msg("Job #,Name\nX,Y\n"), 'missing required column(s): Customer First Name, Customer Last Name, 1st Available Date, From, To'), 'a file without the required columns lists exactly which are missing');
check(str_contains($msg("Job #,Job Number,First Name,Last Name,Available,From,To\n"), 'More than one column matches the same field'), 'two columns for one field');
check(str_contains($msg(''), 'empty'), 'an empty file');
check(str_contains($msg(csv_text([CSV_HEAD]) . str_repeat(csv_text([csv_row('R')]), 5001)), 'more than 5,000'), 'more than 5,000 rows is refused');
$exact = csv_text([CSV_HEAD]);
for ($i = 1; $i <= 5000; $i++) {
    $exact .= csv_text([csv_row(sprintf('BULK-%04d', $i))]);
}
$t0 = microtime(true);
$a = (new ImportService())->analyse(tmp_file($exact), 'csv');
check(count($a['valid']) === 5000 && $a['invalid'] === [], 'exactly 5,000 rows is accepted and all validate');
check((microtime(true) - $t0) < 20, 'analysing 5,000 rows is quick (' . round(microtime(true) - $t0, 1) . 's)');

section('Import: permissions decide what may be imported');
$dispatchCols = [...CSV_HEAD, 'Status', 'Carrier Name', 'Driver Name', 'Phone', 'Storage Fee'];
$financial = tmp_file(csv_text([$dispatchCols, [...csv_row('P-1'), 'In Transit', 'C', 'D', '5550102231', '99']]));
make_role('ImportOnly', ['jobs.view', 'jobs.import']);
$importOnly = make_user('ImportOnly', 'imp-only@example.com');

sign_in($staff);
$a = (new ImportService())->analyse($financial, 'csv');
$j = $a['valid'][0]['job'] ?? null;
check($j !== null && $j['balance'] === '0.00' && $j['storage_fee'] === '0.00', 'Staff (no financial permission): balance and storage fee in the file are ignored (stay 0.00)');
check($j['status'] === 'in_transit' && $j['carrier_name'] === 'C', 'Staff (has jobs.status): the Status and carrier columns are honoured');
$w = implode(' ', $a['warnings']);
check(str_contains($w, 'Balance column ignored') && str_contains($w, 'Storage Fee column ignored') && !str_contains($w, 'Status column ignored'), 'Staff: told exactly which columns were ignored and why');

sign_in($importOnly);
$a = (new ImportService())->analyse($financial, 'csv');
$j = $a['valid'][0]['job'] ?? null;
check($j !== null && $j['status'] === 'booked' && $j['carrier_name'] === null && $j['driver_phone'] === null && $j['in_transit_at'] === null, 'a user without jobs.status: Status, carrier, driver and phone are ignored (the job stays Booked)');
$w = implode(' ', $a['warnings']);
check(str_contains($w, 'Status column ignored') && str_contains($w, 'Carrier Name column ignored') && str_contains($w, 'Balance column ignored'), '...and is told so');

sign_in($mgr);
$a = (new ImportService())->analyse($financial, 'csv');
$j = $a['valid'][0]['job'];
check($j['storage_fee'] === '99.00' && $j['status'] === 'in_transit' && $a['warnings'] === [], 'Manager: storage fee and status are honoured, no warnings');
$delivered = tmp_file(csv_text([$dispatchCols, [...csv_row('P-2'), 'Delivered', 'C', 'D', '5550102231', '1']]));
$noDate = (new ImportService())->analyse($delivered, 'csv');
check($noDate['valid'] === [] && count($noDate['invalid']) === 1 && str_contains(implode(' ', $noDate['invalid'][0]['messages']), 'Delivered Date is required'), 'Manager: a Delivered row without a delivery date is refused (workflow rules apply to imports too)');

// ================================================================ confirm

section('Import: confirm writes only the valid rows');
fresh_db();
fresh_session();
Clock::fake('2026-10-23');
$admin = make_user('Admin', 'imp-admin@example.com');
$other = make_user('Admin', 'imp-admin2@example.com');
sign_in($admin);
$svc = new ImportService();
$uid = (int) $admin['id'];
mk_job(['job_number' => 'EXIST-1']);
$rows = [
    $head,
    $withOk(['Job #' => 'C-1', 'Balance' => '100']),
    $withOk(['Job #' => 'C-2', 'Status' => 'In Transit', 'Carrier Name' => 'Blue Ridge', 'Driver Name' => 'Marcus', 'Phone' => '(555) 010-2231']),
    $withOk(['Job #' => 'C-3', 'Status' => 'Delivered', 'Carrier Name' => 'Blue Ridge', 'Driver Name' => 'Marcus', 'Phone' => '(555) 010-2231', 'Delivered Date' => '2026-10-20']),
    $withOk(['Job #' => 'C-BAD', '1st Available Date' => 'nope']),
    $withOk(['Job #' => 'EXIST-1']),
];
$staged = $svc->stage(upload_of(tmp_file(csv_text($rows)), 'march.csv'), $uid);
$bid = (int) $staged['batch'];
check($staged['ok'], 'staged');
check(import_job_numbers() === ['EXIST-1'], 'after upload and preview NOTHING is in the jobs table yet');
$b = (new ImportRepository())->findForUser($bid, $uid);
check($b['status'] === 'previewed' && (int) $b['total_rows'] === 5 && (int) $b['valid_rows'] === 3 && (int) $b['invalid_rows'] === 2, 'the batch records 5 rows: 3 valid, 2 problems');
check(count($b['sample']) === 3 && $b['sample'][0]['job_number'] === 'C-1', 'a sample of valid rows is kept for the preview');
check(count((new ImportRepository())->errors($bid)) === 2, 'problem rows are stored');
check((new ImportService())->commit($bid, (int) $other['id'])['code'] === 'not_found', "another user cannot confirm someone else's batch");
check((new ImportRepository())->findForUser($bid, (int) $other['id']) === null, '...or even see it');

$storedBefore = BASE_PATH . '/storage/imports/' . $b['stored_name'];
check(is_file($storedBefore), 'the uploaded file is kept until the user decides');
$r = $svc->commit($bid, $uid);
check($r === ['ok' => true, 'imported' => 3, 'skipped' => 2], 'confirm imports 3 and skips 2');
check(import_job_numbers() === ['C-1', 'C-2', 'C-3', 'EXIST-1'], 'the three valid jobs exist; the bad rows do not');
$c1 = Database::fetchOne("SELECT * FROM jobs WHERE job_number = 'C-1'");
$c2 = Database::fetchOne("SELECT * FROM jobs WHERE job_number = 'C-2'");
$c3 = Database::fetchOne("SELECT * FROM jobs WHERE job_number = 'C-3'");
check($c1['status'] === 'booked' && $c1['balance'] == 100 && (int) $c1['created_by'] === $uid && $c1['customer_first_name'] === 'Ada', 'imported job fields and created_by');
check($c2['status'] === 'in_transit' && $c2['carrier_name'] === 'Blue Ridge' && $c2['in_transit_at'] !== null, 'in-transit job has carrier details and a dispatch time');
check($c3['status'] === 'delivered' && $c3['delivered_at'] === '2026-10-20 00:00:00', 'delivered job has its delivery date');
check(JobBucket::of($c2) === 'transit' && JobBucket::of($c3) === 'delivered' && JobBucket::of($c1) !== null, 'imported jobs land in the right sections automatically');
$h = (new StatusHistoryRepository())->forJob((int) $c3['id']);
check(count($h) === 1 && $h[0]['from_status'] === 'booked' && $h[0]['to_status'] === 'delivered' && str_contains((string) $h[0]['note'], 'Imported from march.csv') && count((new StatusHistoryRepository())->forJob((int) $c1['id'])) === 0, 'jobs that are not Booked get a history entry saying where they came from');
$b = (new ImportRepository())->findForUser($bid, $uid);
check($b['status'] === 'committed' && (int) $b['imported_rows'] === 3 && (int) $b['skipped_rows'] === 2 && $b['committed_at'] !== null && $b['stored_name'] === null, 'batch marked committed with counts');
check(!is_file($storedBefore), 'the uploaded file is deleted after confirm');
$a = Database::fetchOne("SELECT * FROM audit_logs WHERE action = 'jobs_imported' ORDER BY id DESC LIMIT 1");
check($a !== null && str_contains((string) $a['new_values'], 'march.csv') && str_contains((string) $a['new_values'], '"imported":3'), 'the import is audited');
check($svc->commit($bid, $uid)['code'] === 'already', 'confirming twice does nothing the second time');
check(import_job_numbers() === ['C-1', 'C-2', 'C-3', 'EXIST-1'], '...and creates no duplicates');
check($svc->cancel($bid, $uid) === false, 'a processed batch cannot be cancelled');

// the same file again: everything is now a duplicate
$again = $svc->stage(upload_of(tmp_file(csv_text($rows)), 'march-again.csv'), $uid);
$ab = (new ImportRepository())->findForUser((int) $again['batch'], $uid);
check((int) $ab['valid_rows'] === 0 && (int) $ab['invalid_rows'] === 5, 're-uploading the same file finds every row already exists (nothing would be doubled)');
check($svc->commit((int) $again['batch'], $uid) === ['ok' => true, 'imported' => 0, 'skipped' => 5], 'confirming imports nothing');

section('Import: cancel, expiry and late duplicates');
$st = $svc->stage(upload_of(tmp_file(csv_text([$head, $withOk(['Job #' => 'X-1'])])), 'x.csv'), $uid);
$xb = (new ImportRepository())->findForUser((int) $st['batch'], $uid);
$path = BASE_PATH . '/storage/imports/' . $xb['stored_name'];
check(is_file($path) && $svc->cancel((int) $st['batch'], $uid), 'cancel works');
check(!is_file($path) && (new ImportRepository())->findForUser((int) $st['batch'], $uid)['status'] === 'cancelled' && !in_array('X-1', import_job_numbers(), true), '...deleting the uploaded file and importing nothing');
check($svc->commit((int) $st['batch'], $uid)['code'] === 'already', 'a cancelled batch cannot be confirmed');

$st = $svc->stage(upload_of(tmp_file(csv_text([$head, $withOk(['Job #' => 'X-2'])])), 'old.csv'), $uid);
$xb = (new ImportRepository())->findForUser((int) $st['batch'], $uid);
Database::execute('UPDATE import_batches SET created_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 25 * 3600), $st['batch']]);
check($svc->commit((int) $st['batch'], $uid)['code'] === 'expired' && !is_file(BASE_PATH . '/storage/imports/' . $xb['stored_name']) && !in_array('X-2', import_job_numbers(), true), 'a preview older than 24 hours expires: nothing imported, file deleted');

$st = $svc->stage(upload_of(tmp_file(csv_text([$head, $withOk(['Job #' => 'X-3']), $withOk(['Job #' => 'X-4'])])), 'race.csv'), $uid);
mk_job(['job_number' => 'X-3']); // someone creates it between preview and confirm
$r = $svc->commit((int) $st['batch'], $uid);
check($r === ['ok' => true, 'imported' => 1, 'skipped' => 1] && in_array('X-4', import_job_numbers(), true), 'a job # taken between preview and confirm is skipped, the rest imports (the check is repeated at confirm)');
$errs = (new ImportRepository())->errors((int) $st['batch']);
check(count($errs) === 1 && $errs[0]['job_number'] === 'X-3' && str_contains($errs[0]['messages'][0], 'already exists'), '...and reported');

section('Import: all-or-nothing and safety at confirm');
$st = $svc->stage(upload_of(tmp_file(csv_text([$head, $withOk(['Job #' => 'T-1']), $withOk(['Job #' => 'T-2', 'Status' => 'In Transit', 'Carrier Name' => 'C', 'Driver Name' => 'D', 'Phone' => '5550102231'])])), 'atomic.csv'), $uid);
Database::connect()->exec('DROP TABLE job_status_history'); // the second row needs a history entry, so it will fail
$threw = false;
try {
    $svc->commit((int) $st['batch'], $uid);
} catch (Throwable) {
    $threw = true;
}
check($threw && !in_array('T-1', import_job_numbers(), true) && !in_array('T-2', import_job_numbers(), true), 'a failure part-way rolls back the whole import (no half-imported file)');
check((new ImportRepository())->findForUser((int) $st['batch'], $uid)['status'] === 'previewed', '...and the batch can be confirmed again');

// permissions are re-checked at confirm
fresh_db();
fresh_session();
$mgr = make_user('Manager', 'imp-mgr2@example.com');
sign_in($mgr);
$st = $svc->stage(upload_of(tmp_file(csv_text([[...CSV_HEAD, 'Status'], [...csv_row('PM-1'), 'Delivered']])), 'perm.csv'), (int) $mgr['id']);
check((int) (new ImportRepository())->findForUser((int) $st['batch'], (int) $mgr['id'])['invalid_rows'] === 1, 'precondition: a Delivered row without a delivery date is a problem');
$st = $svc->stage(upload_of(tmp_file(csv_text([CSV_HEAD, csv_row('PM-2', ['Balance' => '555'])])), 'perm2.csv'), (int) $mgr['id']);
Database::execute('DELETE FROM role_permissions WHERE role_id = ? AND permission_id = ?', [role_id('Manager'), perm_id('jobs.edit_financials')]);
Database::execute('DELETE FROM role_permissions WHERE role_id = ? AND permission_id = ?', [role_id('Manager'), perm_id('jobs.view_financials')]);
App\Services\PermissionService::flush();
Auth::reset();
$svc->commit((int) $st['batch'], (int) $mgr['id']);
check(Database::fetchValue("SELECT balance FROM jobs WHERE job_number = 'PM-2'") == 0, 'if the user loses permission between preview and confirm, the figures are NOT imported (permissions are checked again)');

// ================================================================ the problem report

section('Import: the problem report');
fresh_db();
fresh_session();
$admin = make_user('Admin', 'imp-rep@example.com');
sign_in($admin);
$uid = (int) $admin['id'];
$st = $svc->stage(upload_of(tmp_file(csv_text([$head, $withOk(['Job #' => 'REP-1', '1st Available Date' => 'bogus']), $withOk(['Job #' => '=cmd|calc', 'Customer First Name' => ''])])), 'rep.csv'), $uid);
$res = call('GET', '/jobs/import/' . $st['batch'] . '/problems');
$text = $res->content();
check($res->status() === 200 && str_starts_with($text, "\xEF\xBB\xBF") && str_contains((string) $res->getHeader('Content-Disposition'), 'attachment'), 'the report is a CSV download with a BOM');
$lines = array_map('str_getcsv', array_filter(explode("\n", substr($text, 3))));
check(array_slice($lines[0], 0, 3) === ['Row', 'Problems', 'Job #'] && count($lines) === 3, 'it has a Row and Problems column followed by the original columns');
check($lines[1][0] === '2' && str_contains($lines[1][1], 'not a valid date') && $lines[1][2] === 'REP-1', 'each problem row carries its row number, the reasons and the original cells');
check(str_starts_with($lines[2][2], "'="), 'formula-looking cells are neutralised in the report too');

// ================================================================ routes and pages

section('Import: pages and permissions');
fresh_db();
fresh_session();
Clock::fake('2026-10-23');
$admin = make_user('Admin', 'pg-admin@example.com');
$mgr = make_user('Manager', 'pg-mgr@example.com');
$stf = make_user('Staff', 'pg-stf@example.com');
make_role('ExportOnly', ['jobs.export']);
$exp = make_user('ExportOnly', 'pg-exp@example.com');
mk_job(['job_number' => 'PG-1']);

sign_in($stf);
foreach (['/jobs/import', '/jobs/import/template/csv', '/jobs/import/1', '/jobs/import/1/problems'] as $p) {
    check(call('GET', $p)->status() === 403, "Staff: GET {$p} -> 403");
}
check(call('POST', '/jobs/import')->status() === 403 && call('POST', '/jobs/import/1/commit')->status() === 403 && call('POST', '/jobs/import/1/cancel')->status() === 403, 'Staff: upload, confirm and cancel -> 403');
check(call('GET', '/jobs/export/all')->status() === 403, 'Staff: export -> 403');
$list = call('GET', '/jobs')->body();
check(!str_contains($list, 'Export') && !str_contains($list, '/jobs/import') && !str_contains(plain(call('GET', '/dashboard')->body()), 'Import Jobs'), 'Staff: no Export / Import buttons and no menu entry');

fresh_session();
sign_in($mgr);
$list = call('GET', '/jobs')->body();
check(str_contains($list, 'Export') && str_contains($list, 'format=csv') && str_contains($list, 'format=xlsx') && str_contains($list, '</svg> Import</a>'), 'Manager: Export (CSV and Excel) and Import buttons on All Jobs');
check(str_contains(call('GET', '/jobs/ready')->body(), 'format=csv') && !str_contains(call('GET', '/jobs/ready')->body(), '</svg> Import</a>'), '...Export on each section, the Import button only on All Jobs');
check(str_contains(plain(call('GET', '/dashboard')->body()), 'Import Jobs'), 'Manager: "Import Jobs" in the menu');
$page = call('GET', '/jobs/import')->body();
check(str_contains($page, 'enctype="multipart/form-data"') && str_contains($page, 'name="file"') && str_contains($page, 'accept=".csv,.xlsx"') && str_contains($page, '/jobs/import/template/csv') && str_contains($page, '/jobs/import/template/xlsx'), 'import page: upload form, accepted types, template downloads');
check(str_contains(plain($page), 'up to ' . round(ImportService::maxBytes() / 1048576, 1) . ' MB and 5,000 rows') && str_contains(plain($page), 'Customer First Name') && str_contains(plain($page), '1st Available Date') && str_contains(plain($page), 'Required'), '...stated limits and a column reference');
check(preg_match('/<td(?![^>]*data-label)/', $page) === 0, '...table cells carry data-labels (card layout on phones)');
fresh_session();
sign_in($stf);

fresh_session();
sign_in($mgr);
$tmpl = call('GET', '/jobs/import/template/csv');
$tt = str_getcsv(explode("\n", substr($tmpl->content(), 3))[0]);
check(in_array('Balance', $tt, true) && in_array('Storage Fee', $tt, true) && count($tt) === 29, 'template for a Manager has all 29 columns incl. money');
check(call('GET', '/jobs/import/template/pdf')->status() === 404, 'unknown template format -> 404');
$x = tmp_file(call('GET', '/jobs/import/template/xlsx')->content(), 'xlsx');
$xr = XlsxReader::read($x, 10, 30);
check($xr['headers'] === $tt && count($xr['rows']) === 1, 'the Excel template reads back with the same headings');
$a = (new ImportService())->analyse(tmp_file($tmpl->content()), 'csv');
check(count($a['valid']) === 1 && $a['invalid'] === [], 'the template, unedited, is itself a valid import file');

// upload -> preview -> confirm through the real routes
$csv = csv_text([[...CSV_HEAD, 'Junk'], [...csv_row('RT-1'), 'x'], [...csv_row('RT-2'), 'y'], [...csv_row('RT-BAD', ['1st Available Date' => 'soon']), 'z'], [...csv_row('RT-<script>', []), 'q']]);
$files = ['file' => upload_of(tmp_file($csv), 'route.csv')];
$r = call('POST', '/jobs/import', [], [], $files);
check($r->status() === 302 && preg_match('#/jobs/import/(\d+)$#', (string) $r->getHeader('Location'), $m) === 1, 'uploading redirects to the preview');
$bid = (int) $m[1];
check(import_job_numbers() === ['PG-1'], '...and nothing is imported yet');
$prev = call('GET', "/jobs/import/{$bid}")->body();
$ptxt = plain($prev);
check(str_contains($ptxt, 'Rows read') && str_contains($ptxt, 'Ready to import') && str_contains($ptxt, 'Problems') && str_contains($ptxt, 'route.csv'), 'preview: counts and file name');
check(preg_match('#jt-stat-value">2</span>\s*<span class="jt-stat-label">Ready to import#', $prev) === 1 && preg_match('#jt-stat-value">2</span>\s*<span class="jt-stat-label">Problems#', $prev) === 1, 'preview: 2 ready, 2 problems');
check(str_contains($ptxt, 'not a valid date') && str_contains($ptxt, 'Import 2 jobs') && str_contains($ptxt, 'RT-1') && str_contains($ptxt, 'Junk'), 'preview: problem reasons, the confirm button, a sample and the ignored column');
check(!str_contains($prev, 'RT-<script>') && str_contains($prev, 'RT-&lt;script&gt;'), 'preview: hostile text from the file is escaped (visible as text, never executed)');
check(str_contains($prev, '/jobs/import/' . $bid . '/commit') && str_contains($prev, '/jobs/import/' . $bid . '/cancel') && str_contains($prev, '/jobs/import/' . $bid . '/problems'), 'preview: confirm, cancel and download-report actions');
check(call('GET', '/jobs/import/999999')->status() === 404, 'unknown batch -> 404');
$noToken = real_router()->dispatch(Request::make('POST', "/jobs/import/{$bid}/commit"));
check($noToken->status() === 403 && import_job_numbers() === ['PG-1'], 'confirm needs a CSRF token');
$noToken = real_router()->dispatch(Request::make('POST', '/jobs/import', [], [], [], $files));
check($noToken->status() === 403, 'upload needs a CSRF token');

fresh_session();
sign_in($admin);
check(call('GET', "/jobs/import/{$bid}")->status() === 404 && call('POST', "/jobs/import/{$bid}/commit")->status() === 404 && call('GET', "/jobs/import/{$bid}/problems")->status() === 404, "another user (even an Admin) cannot see or confirm someone else's import");
fresh_session();
sign_in($mgr);
$r = call('POST', "/jobs/import/{$bid}/commit");
check($r->status() === 302 && import_job_numbers() === ['PG-1', 'RT-1', 'RT-2'] && ($_SESSION['_flash_next']['alert']['type'] ?? '') === 'warning', 'confirm through the route imports the 2 good rows, with a warning about the skipped ones');
$res = call('GET', "/jobs/import/{$bid}")->body();
check(str_contains(plain($res), 'Jobs imported') && str_contains(plain($res), 'Problem report') && !str_contains($res, '/commit'), 'the page now shows the result and offers the problem report');
check(str_contains(call('GET', '/jobs/import')->body(), 'route.csv') && str_contains(plain(call('GET', '/jobs/import')->body()), '2 imported, 2 skipped'), 'the import appears under Recent imports');
$r = call('POST', "/jobs/import/{$bid}/commit");
check(str_contains((string) ($_SESSION['_flash_next']['alert']['message'] ?? ''), 'already been processed'), 'confirming again says so');

$files = ['file' => upload_of(tmp_file(csv_text([$head, $withOk(['Job #' => 'RT-9'])])), 'cancel-me.csv')];
call('POST', '/jobs/import', [], [], $files);
$cid = (int) Database::fetchValue('SELECT MAX(id) FROM import_batches');
$r = call('POST', "/jobs/import/{$cid}/cancel");
check($r->status() === 302 && !in_array('RT-9', import_job_numbers(), true) && str_contains(plain(call('GET', "/jobs/import/{$cid}")->body()), 'This import was cancelled'), 'cancel through the route');

foreach ([['', 'Choose a CSV'], ['bad.php', 'Only']] as [$name, $expect]) {
    $f2 = $name === '' ? [] : ['file' => upload_of($good, $name)];
    call('POST', '/jobs/import', [], [], $f2);
    check(str_contains((string) ($_SESSION['_flash_next']['alert']['message'] ?? ''), $expect), "upload problem is explained: {$expect}");
}
call('POST', '/jobs/import', [], [], ['file' => upload_of(tmp_file(csv_text([['Job #', 'Name']])), 'headers.csv')]);
check(str_contains((string) ($_SESSION['_flash_next']['alert']['message'] ?? ''), 'missing required column'), 'a file without the required columns is explained on the upload page');
$huge = real_router()->dispatch(Request::make('POST', '/jobs/import', [], [], ['CONTENT_TYPE' => 'multipart/form-data; boundary=x', 'CONTENT_LENGTH' => '99999999'], []));
check($huge->status() === 413 && str_contains($huge->body(), 'larger than this server accepts'), 'an upload that exceeds post_max_size gets a clear "too large" page, not a confusing form error');

// ================================================================ export

section('Export: columns, content and safety');
fresh_db();
fresh_session();
Clock::fake('2026-10-23');
$admin = make_user('Admin', 'ex-admin@example.com');
$mgr = make_user('Manager', 'ex-mgr@example.com');
$stf = make_user('Staff', 'ex-stf@example.com');
sign_in($mgr);
mk_job(['job_number' => 'EX-R1', 'first_available_date' => '2026-10-28', 'balance' => '120.50', 'customer_first_name' => 'Zoë', 'customer_last_name' => "O'Brien, Jr.", 'notes' => "line1\nline2 \"quoted\""]);
mk_job(['job_number' => 'EX-R2', 'first_available_date' => '2026-10-20', 'balance' => '80.00', 'cf' => '12.50']);
mk_job(['job_number' => 'EX-L1', 'first_available_date' => '2026-12-31', 'storage_fee' => '45.00']);
mk_job(['job_number' => 'EX-T1', 'status' => 'in_transit', 'carrier_name' => 'Blue Ridge', 'driver_name' => 'Marcus', 'driver_phone' => '(555) 010-2231', 'in_transit_at' => '2026-10-20 09:00:00']);
mk_job(['job_number' => 'EX-D1', 'status' => 'delivered', 'delivered_at' => '2026-09-15 00:00:00', 'carrier_name' => 'Summit', 'driver_name' => 'Priya', 'driver_phone' => '5550104417']);
mk_job(['job_number' => 'EX-H1', 'status' => 'on_hold']);
mk_job(['job_number' => 'EX-EVIL', 'customer_first_name' => '=HYPERLINK("http://evil","x")', 'customer_last_name' => '+cmd', 'from_location' => '-2+3', 'to_location' => '@SUM(A1)', 'notes' => "\t=1+1"]);

$csvOf = static function (string $bucket, array $query = [], bool $financials = true): array {
    $c = JobCriteria::fromRequest(Request::make('GET', '/', [], $query), $bucket, $financials);
    $text = (new ExportService())->csv($c, $financials)->content();
    $h = fopen('php://memory', 'r+');
    fwrite($h, substr($text, 3));
    rewind($h);
    $rows = [];
    while (($r = fgetcsv($h, 0, ',', '"', '')) !== false) {
        $rows[] = $r;
    }
    return [$text, $rows];
};
foreach ([['ready', ['Job #', 'Customer First Name', 'Customer Last Name', 'Customer Phone', 'Pickup Date', '1st Available Date', 'From', 'To', 'A-CF', 'Balance', 'Storage Fee']],
    ['later', ['Job #', 'Customer First Name', 'Customer Last Name', 'Customer Phone', 'Pickup Date', '1st Available Date', 'From', 'To', 'A-CF', 'Balance', 'Storage Fee']],
    ['transit', ['Job #', 'Customer First Name', 'Customer Last Name', 'Customer Phone', 'Pickup Date', '1st Available Date', 'From', 'To', 'A-CF', 'Balance', 'Storage Fee', 'Loaded Date', 'Carrier Name', 'Driver Name', 'Phone']],
    ['delivered', ['Job #', 'Customer First Name', 'Customer Last Name', 'Customer Phone', 'Pickup Date', '1st Available Date', 'From', 'To', 'A-CF', 'Balance', 'Storage Fee', 'Loaded Date', 'Delivered Date']]] as [$bucket, $expected]) {
    [$text, $rows] = $csvOf($bucket);
    check($rows[0] === $expected, "{$bucket}: headings follow the client's sheet for that section");
}
[$text, $rows] = $csvOf('all');
check(str_starts_with($text, "\xEF\xBB\xBF") && $rows[0] === array_map([JobFields::class, 'label'], array_keys(JobFields::all())), 'All Jobs: BOM first (Excel reads UTF-8), then every importable column in template order');
[, $rows] = $csvOf('all', [], false);
check(!in_array('Balance', $rows[0], true) && !in_array('Storage Fee', $rows[0], true) && count($rows[0]) === 22, 'without access to money the Balance and Storage Fee columns are not even in the file');
check(!str_contains(implode(',', array_merge(...$csvOf('all', [], false)[1])), '120.50') && !str_contains($csvOf('later', [], false)[0], '45.00'), '...and none of the amounts appear anywhere in it');

[, $rows] = $csvOf('ready');
check(array_column($rows, 0) === ['Job #', 'EX-R2', 'EX-R1', 'EX-EVIL'], 'Ready: only ready jobs, in the list\'s own order (soonest available date first)');
[, $rows] = $csvOf('ready', ['sort' => 'job', 'dir' => 'desc']);
check(array_column($rows, 0) === ['Job #', 'EX-R2', 'EX-R1', 'EX-EVIL'], 'the list\'s sort is applied (job # descending)');
[, $rows] = $csvOf('ready', ['sort' => 'job', 'dir' => 'asc']);
check(array_column($rows, 0) === ['Job #', 'EX-EVIL', 'EX-R1', 'EX-R2'], '...and ascending');
[, $rows] = $csvOf('all', ['q' => 'ex-t1']);
check(array_column($rows, 0) === ['Job #', 'EX-T1'], 'search is applied');
[, $rows] = $csvOf('all', ['status' => 'on_hold']);
check(array_column($rows, 0) === ['Job #', 'EX-H1'], 'filters are applied');
[, $rows] = $csvOf('all', ['from_date' => '2026-12-01']);
check(array_column($rows, 0) === ['Job #', 'EX-L1'], 'date filters are applied');
[, $rows] = $csvOf('all', ['per_page' => '10', 'page' => '2']);
check(count($rows) === 8, 'paging is ignored: all matching jobs are exported, not just one page');
$criteria = JobCriteria::fromRequest(Request::make('GET', '/', [], ['q' => 'ex']), 'all', true);
check(count($csvOf('all', ['q' => 'ex'])[1]) - 1 === (new JobRepository())->count($criteria), 'the file has exactly as many rows as the list says');

[, $rows] = $csvOf('ready');
$r1 = $rows[2];
check($r1[0] === 'EX-R1' && $r1[1] === 'Zoë' && $r1[2] === "O'Brien, Jr." && $r1[3] === '' && $r1[4] === '' && $r1[5] === '2026-10-28' && $r1[8] === '' && $r1[9] === '120.50', 'accents, apostrophes, commas, empty cells, ISO dates and plain amounts');
[, $rows] = $csvOf('all');
$byNumber = array_column($rows, null, 0);
check($byNumber['EX-R1'][array_search('Notes', $rows[0], true)] === "line1\nline2 \"quoted\"", 'line breaks and quotes in a cell survive');
check($byNumber['EX-R2'][8] === '12.5' && $byNumber['EX-T1'][array_search('Status', $rows[0], true)] === 'In Transit' && $byNumber['EX-D1'][array_search('Delivered Date', $rows[0], true)] === '2026-09-15', 'CF without trailing zeros, status as a label, delivery date');
$evil = $byNumber['EX-EVIL'];
check($evil[1] === "'=HYPERLINK(\"http://evil\",\"x\")" && $evil[2] === "'+cmd" && $evil[6] === "'-2+3" && $evil[7] === "'@SUM(A1)", 'cells starting with = + - @ get a leading apostrophe so Excel cannot run them (CSV injection)');
check($evil[array_search('Notes', $rows[0], true)] === "'\t=1+1", '...including a cell that starts with a tab');
check(!str_contains(implode('', array_map(static fn (array $r): string => $r[0], $rows)), '='), 'normal job numbers are untouched');
check(ExportService::safeCell('=1+1') === "'=1+1" && ExportService::safeCell('+1') === "'+1" && ExportService::safeCell('-1') === "'-1" && ExportService::safeCell('@x') === "'@x" && ExportService::safeCell("\r=x") === "'\r=x" && ExportService::safeCell('plain') === 'plain' && ExportService::safeCell('') === '' && ExportService::safeCell('2026-10-23') === '2026-10-23', 'safeCell');

section('Export: large exports stream in chunks');
fresh_db();
fresh_session();
$mgr = make_user('Manager', 'ex-big@example.com');
sign_in($mgr);
$repo = new JobRepository();
Database::transaction(static function () use ($repo): void {
    for ($i = 1; $i <= 1250; $i++) {
        $repo->create(['job_number' => sprintf('BIG-%05d', $i), 'customer_first_name' => 'A', 'customer_last_name' => 'B', 'first_available_date' => '2026-12-01', 'from_location' => 'X', 'to_location' => 'Y', 'status' => 'booked'], null);
    }
});
[$text, $rows] = $csvOf('all');
check(count($rows) === 1251 && count(array_unique(array_column($rows, 0))) === 1251, '1,250 jobs (three chunks of 500) export completely with no row repeated or skipped');
check(array_column($rows, 0)[1] === 'BIG-01250' && end($rows)[0] === 'BIG-00001', '...in the list\'s order (newest first)');
memory_reset_peak_usage();
$before = memory_get_usage();
$producer = (new ExportService())->csv(JobCriteria::forBucket('all'), true)->content();
check(strlen($producer) > 40000 && (memory_get_peak_usage() - $before) < 10 * 1024 * 1024, 'memory stays flat while streaming (peak growth ' . round((memory_get_peak_usage() - $before) / 1048576, 1) . ' MB for a ' . round(strlen($producer) / 1024) . ' KB file)');

section('Export: Excel');
$c = JobCriteria::forBucket('all');
$resp = (new ExportService())->xlsx($c, true);
$bin = $resp->content();
check(str_starts_with($bin, 'PK') && str_contains((string) $resp->getHeader('Content-Type'), 'spreadsheetml') && str_contains((string) $resp->getHeader('Content-Disposition'), '.xlsx'), 'an .xlsx download');
$xf = tmp_file($bin, 'xlsx');
$xr = XlsxReader::read($xf, 5000, 60);
check(count($xr['rows']) === 1250 && $xr['headers'][0] === 'Job #', 'all 1,250 rows are in the workbook');
check($xr['rows'][0][1][0] === 'BIG-01250' && $xr['rows'][0][1][5] === '46357', 'real Excel dates (serial numbers) rather than text');
check((new ImportService())->stage(upload_of($xf, 'back.xlsx'), (int) $mgr['id'])['ok'], 'the exported workbook is accepted by the importer');

// ================================================================ export routes

section('Export: routes and permissions');
fresh_db();
fresh_session();
Clock::fake('2026-10-23');
$admin = make_user('Admin', 'er-admin@example.com');
$mgr = make_user('Manager', 'er-mgr@example.com');
$stf = make_user('Staff', 'er-stf@example.com');
make_role('ExportOnly', ['jobs.export']);
$expo = make_user('ExportOnly', 'er-exp@example.com');
mk_job(['job_number' => 'ER-1', 'balance' => '10.00']);
mk_job(['job_number' => 'ER-2', 'status' => 'in_transit', 'carrier_name' => 'C', 'driver_name' => 'D', 'driver_phone' => '5550102231']);

sign_in($mgr);
$r = call('GET', '/jobs/export/all', [], ['format' => 'csv']);
check($r->status() === 200 && $r->isStreamed() && str_starts_with((string) $r->getHeader('Content-Type'), 'text/csv') && preg_match('/attachment; filename="jobs-all-\d{8}-\d{6}\.csv"/', (string) $r->getHeader('Content-Disposition')) === 1, 'CSV download with a dated file name');
check($r->getHeader('Cache-Control') !== null && str_contains((string) $r->getHeader('Cache-Control'), 'no-store'), 'not cached by browsers or proxies');
check(str_contains(call('GET', '/jobs/export/transit', [], ['format' => 'csv'])->content(), 'ER-2') && !str_contains(call('GET', '/jobs/export/transit', [], ['format' => 'csv'])->content(), 'ER-1'), 'the section in the URL decides the rows');
check(call('GET', '/jobs/export/all')->status() === 200 && str_contains(call('GET', '/jobs/export/all')->content(), 'ER-1'), 'CSV is the default format');
check(call('GET', '/jobs/export/all', [], ['format' => 'xlsx'])->status() === 200 && str_contains((string) call('GET', '/jobs/export/all', [], ['format' => 'xlsx'])->getHeader('Content-Type'), 'spreadsheetml'), 'xlsx format');
check(call('GET', '/jobs/export/bogus')->status() === 404 && call('GET', '/jobs/export/all', [], ['format' => 'pdf'])->status() === 404 && call('GET', '/jobs/export/all', [], ['format' => '../x'])->status() === 404, 'unknown section or format -> 404');
check(call('GET', '/jobs/export/all', [], ['sort' => 'evil;DROP', 'per_page' => '999', 'status' => 'zzz'])->status() === 200, 'garbage parameters are harmless');
check((int) Database::fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'jobs_exported'") >= 4, 'exports are audited');
$a = Database::fetchOne("SELECT * FROM audit_logs WHERE action = 'jobs_exported' ORDER BY id DESC LIMIT 1");
check($a !== null && str_contains((string) $a['new_values'], '"rows"') && str_contains((string) $a['new_values'], '"format"'), '...with the section, format and row count');

fresh_session();
sign_in($stf);
check(call('GET', '/jobs/export/all')->status() === 403, 'Staff (no jobs.export): 403');
fresh_session();
sign_in($expo);
check(call('GET', '/jobs/export/all')->status() === 403, 'a user with jobs.export but without jobs.view cannot export either');
fresh_session();
check(call('GET', '/jobs/export/all')->status() === 302, 'signed out: redirected to sign in');
fresh_session();
sign_in($mgr);
$staffExport = (function () use ($stf, $admin, $mgr): string {
    // a user who may export and view, but not see money
    make_role('ViewExport', ['jobs.view', 'jobs.export']);
    $u = make_user('ViewExport', 'er-ve@example.com');
    fresh_session();
    sign_in($u);
    return call('GET', '/jobs/export/all')->content();
})();
check(!str_contains($staffExport, 'Balance') && !str_contains($staffExport, '10.00'), 'a user without financial access gets a file with no money in it');

// ================================================================ round trip

section('Export then import: nothing is lost');
fresh_db();
fresh_session();
Clock::fake('2026-10-23');
$admin = make_user('Admin', 'rt-admin@example.com');
sign_in($admin);
mk_job(['job_number' => 'RT-A', 'customer_first_name' => "Zoë", 'customer_last_name' => "O'Brien, Jr.", 'pickup_date' => '2026-10-01', 'first_available_date' => '2026-11-15', 'from_location' => 'Austin, TX', 'to_location' => 'Denver, CO', 'cf' => '900.50', 'balance' => '1200.50', 'job_location' => 'Main warehouse', 'storage_fee' => '45.00', 'notes' => "multi\nline \"note\""]);
mk_job(['job_number' => 'RT-B', 'first_available_date' => '2026-10-10', 'status' => 'in_transit', 'carrier_name' => 'Blue Ridge', 'driver_name' => 'Marcus Reed', 'driver_phone' => '(555) 010-2231', 'in_transit_at' => '2026-10-12 09:00:00', 'balance' => '0.00']);
mk_job(['job_number' => 'RT-C', 'first_available_date' => '2026-09-01', 'status' => 'delivered', 'delivered_at' => '2026-09-05 00:00:00', 'carrier_name' => 'Summit', 'driver_name' => 'Priya', 'driver_phone' => '5550104417', 'balance' => '75.25']);
mk_job(['job_number' => 'RT-D', 'first_available_date' => '2026-12-20', 'status' => 'on_hold', 'notes' => "=1+1"]);
mk_job(['job_number' => 'RT-E', 'first_available_date' => '2026-12-21', 'status' => 'cancelled', 'customer_last_name' => '+Plus']);
$snapshot = static function (): array {
    $rows = Database::fetchAll('SELECT job_number, customer_first_name, customer_last_name, pickup_date, first_available_date, from_location, to_location, cf, balance, job_location, status, carrier_name, driver_name, driver_phone, storage_fee, substr(delivered_at, 1, 10) AS delivered_on, notes FROM jobs ORDER BY job_number');
    foreach ($rows as &$r) {
        foreach (['cf', 'balance', 'storage_fee'] as $k) {
            $r[$k] = $r[$k] === null ? null : number_format((float) $r[$k], 2, '.', '');
        }
    }
    return $rows;
};
$original = $snapshot();
$exports = [
    'csv' => (new ExportService())->csv(JobCriteria::forBucket('all'), true)->content(),
    'xlsx' => (new ExportService())->xlsx(JobCriteria::forBucket('all'), true)->content(),
];
foreach ($exports as $format => $content) {
    Database::connect()->exec('DELETE FROM job_status_history');
    Database::connect()->exec('DELETE FROM jobs');
    $svc = new ImportService();
    $st = $svc->stage(upload_of(tmp_file($content, $format), 'roundtrip.' . $format), (int) $admin['id']);
    check($st['ok'], "{$format}: the exported file is accepted by the importer");
    $b = (new ImportRepository())->findForUser((int) $st['batch'], (int) $admin['id']);
    check((int) $b['valid_rows'] === 5 && (int) $b['invalid_rows'] === 0 && $b['ignored'] === [] && $b['warnings'] === [], "{$format}: all 5 rows valid, every column recognised");
    $svc->commit((int) $st['batch'], (int) $admin['id']);
    $now = $snapshot();
    check($now === $original, "{$format}: every field of every job comes back identical (names with accents and commas, dates, money, status, carrier, delivery date, multi-line notes, formula-looking text)");
}

Clock::fake(null);

foreach (glob(BASE_PATH . '/storage/imports/*') ?: [] as $leftover) {
    @unlink($leftover);
}
