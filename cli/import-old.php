<?php

declare(strict_types=1);

/**
 * Bring the data of the client's OLD application (the "Invoice Manager") into this software: companies, drivers, driver invoices (DI-n) and
 * company invoices (CI-n) with the SAME numbers they had. A DRY RUN (the default) only reports what would happen and writes nothing;
 * add --apply to really import. Safe to run again: an invoice whose number already exists is skipped.
 *
 *   From the old application's Export button (a ZIP of four CSV files; handy, but it has no Labor / Pads / Paid / paid date / remarks / customer phone):
 *     php cli/import-old.php --zip=/path/invoice-export-2026-11-28.zip
 *     php cli/import-old.php --dir=/path/to/the/unzipped/csv/files
 *   From the old DATABASE (complete; only SELECT is used; the old database is usually on the same server):
 *     php cli/import-old.php --old-db=cpuser_oldinvoices --old-user=cpuser_old [--old-host=127.0.0.1] [--old-port=3306]
 *     (the password is asked for without showing it, or set the environment variable OLD_DB_PASSWORD)
 *
 * Take a backup first (php cli/backup.php), run it once without --apply and read the report, then run it again with --apply.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Services\OldData\OldDataImporter;
use App\Services\OldData\OldDataReader;

App::boot();

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m) === 1) {
        $opts[$m[1]] = $m[2] ?? '1';
    }
}
$apply = isset($opts['apply']);

if (isset($opts['zip']) || isset($opts['dir'])) {
    $data = OldDataReader::fromCsv((string) ($opts['zip'] ?? $opts['dir']));
} elseif (isset($opts['old-db'])) {
    $user = (string) ($opts['old-user'] ?? '');
    if ($opts['old-db'] === '1' || $user === '' || $user === '1') {
        fwrite(STDERR, "Give the old database and its user: --old-db=NAME --old-user=USER\n");
        exit(1);
    }
    // The names go into a connection string: allow only what a database, user, host and port can be made of.
    foreach (['old-db' => '/^[A-Za-z0-9_.\-]{1,64}$/', 'old-user' => '/^[A-Za-z0-9_.\-@]{1,80}$/', 'old-host' => '/^[A-Za-z0-9_.\-:]{1,100}$/'] as $name => $pattern) {
        if (isset($opts[$name]) && preg_match($pattern, (string) $opts[$name]) !== 1) {
            fwrite(STDERR, "--{$name} has characters a database name, user or host cannot have.\n");
            exit(1);
        }
    }
    if (isset($opts['old-port']) && preg_match('/^\d{1,5}$/', (string) $opts['old-port']) !== 1) {
        fwrite(STDERR, "--old-port must be a number.\n");
        exit(1);
    }
    $password = getenv('OLD_DB_PASSWORD');
    if ($password === false) {
        $tty = function_exists('posix_isatty') && posix_isatty(STDIN);
        if (!$tty) {
            fwrite(STDERR, "Set OLD_DB_PASSWORD, or run this in a terminal so the password can be asked for.\n");
            exit(1);
        }
        fwrite(STDERR, 'Password of the old database user: ');
        shell_exec('stty -echo 2>/dev/null');
        $password = rtrim((string) fgets(STDIN), "\r\n");
        shell_exec('stty echo 2>/dev/null');
        fwrite(STDERR, "\n");
    }
    try {
        $pdo = new PDO(
            'mysql:host=' . ($opts['old-host'] ?? '127.0.0.1') . ';port=' . (int) ($opts['old-port'] ?? 3306) . ';dbname=' . $opts['old-db'] . ';charset=utf8mb4',
            $user,
            (string) $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    } catch (Throwable $e) {
        fwrite(STDERR, 'Could not connect to the old database: ' . preg_replace('/\s+/', ' ', $e->getMessage()) . "\n");
        exit(1);
    }
    $data = OldDataReader::fromDatabase($pdo);
} else {
    fwrite(STDERR, "Say where the old data is: --zip=FILE, --dir=FOLDER or --old-db=NAME --old-user=USER (see the top of this file).\n");
    exit(1);
}

$r = OldDataImporter::run($data, $apply);

echo ($apply ? 'IMPORT' : 'DRY RUN (nothing is written)') . ' from ' . ($r['source'] !== '' ? $r['source'] : '?') . "\n\n";
if ($r['errors'] !== []) {
    foreach ($r['errors'] as $e) {
        echo "ERROR: {$e}\n";
    }
    exit(1);
}
printf("Companies        new %-4d already here %-4d left out %d\n", $r['companies']['new'], $r['companies']['existing'], $r['companies']['skipped']);
printf("Drivers          new %-4d already here %-4d left out %d\n", $r['drivers']['new'], $r['drivers']['existing'], $r['drivers']['skipped']);
printf("Driver invoices  new %-4d already here %d\n", $r['DI']['new'], $r['DI']['existing']);
printf("Company invoices new %-4d already here %d\n", $r['CI']['new'], $r['CI']['existing']);
printf("Invoice lines    %d (of them %d linked to a job with the same Job #, %d not linked)\n", $r['lines'], $r['linked'], $r['unlinked']);
if (!$r['exact']) {
    echo "\nNote: the Export has no Labor, Pads or Paid. They are imported as one amount \"Paid / adjustment\" so every total stays the same.\nFor everything, use --old-db=... instead.\n";
}
if ($r['mismatch'] > 0) {
    echo "\n{$r['mismatch']} invoice(s) have a different total here than in the old application (see the warnings).\n";
}
if ($r['warnings'] !== []) {
    echo "\nWarnings (" . count($r['warnings']) . "):\n";
    foreach (array_slice($r['warnings'], 0, 40) as $w) {
        echo "  - {$w}\n";
    }
    if (count($r['warnings']) > 40) {
        echo '  ... and ' . (count($r['warnings']) - 40) . " more\n";
    }
}
echo "\n" . ($apply ? ($r['applied'] ? "Done. Open Invoices, Companies and Drivers to look at it.\n" : "Nothing was written.\n") : "Nothing was written. If this looks right, run the same command again with --apply (after a backup).\n");
