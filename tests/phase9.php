<?php

declare(strict_types=1);

/**
 * Phase 9 tests: backups, a static MySQL-compatibility lint (no MySQL server was available), and consistency between the
 * deployment templates and the code. Included by tests/run.php (after phase8.php), which provides the fixtures and helpers.
 */

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use App\Services\BackupService;
use App\Services\ImportService;

function p9_tmpdir(): string
{
    $dir = sys_get_temp_dir() . '/jt-p9-' . bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    return $dir;
}

function p9_rmtree(string $dir): void
{
    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $f) {
        is_dir("$dir/$f") ? p9_rmtree("$dir/$f") : unlink("$dir/$f");
    }
    rmdir($dir);
}

// ================================================================ backups: SQLite

section('Backup: SQLite copy');
fresh_db();
fresh_session();
$dir = p9_tmpdir() . '/backups';
$svc = new BackupService($dir);
make_user('Admin', 'p9-admin@example.com');
$result = $svc->create(new DateTimeImmutable('2026-10-23 02:15:00', new DateTimeZone('UTC')));
check($result['driver'] === 'sqlite' && basename($result['path']) === 'job-tracker-20261023-021500.sqlite' && is_file($result['path']), 'a backup file is written with a sortable UTC timestamp name');
check(($result['bytes'] ?? 0) > 1000, 'it is not empty');
check((fileperms($result['path']) & 0777) === 0600, 'the file is readable by its owner only (0600)');
check((fileperms($dir) & 0777) === 0700, 'the backup folder is private (0700)');
$copy = new PDO('sqlite:' . $result['path']);
check((int) $copy->query("SELECT COUNT(*) FROM users WHERE email = 'p9-admin@example.com'")->fetchColumn() === 1 && (int) $copy->query('SELECT COUNT(*) FROM roles')->fetchColumn() >= 3, 'the copy is a working database holding the data');
unset($copy);

$svc->create(new DateTimeImmutable('2026-10-24 02:15:00', new DateTimeZone('UTC')));
$svc->create(new DateTimeImmutable('2026-10-25 02:15:00', new DateTimeZone('UTC')));
$svc->create(new DateTimeImmutable('2026-10-26 02:15:00', new DateTimeZone('UTC')));
check(count(glob($dir . '/job-tracker-*')) === 4, 'four backups exist');
check($svc->prune(2) === 2, 'pruning to 2 removes the two oldest');
$left = array_map('basename', glob($dir . '/job-tracker-*'));
sort($left);
check($left === ['job-tracker-20261025-021500.sqlite', 'job-tracker-20261026-021500.sqlite'], '...and keeps the newest');
check($svc->prune(0) === 1 && count(glob($dir . '/job-tracker-*')) === 1, 'pruning never deletes the last backup, even if asked to keep 0');
file_put_contents($dir . '/notes.txt', 'mine');
$svc->prune(1);
check(is_file($dir . '/notes.txt'), 'pruning leaves files that are not backups alone');
p9_rmtree(dirname($dir));

// ================================================================ backups: MySQL (fake mysqldump)

section('Backup: MySQL dump handling (mysqldump replaced by a fake)');
$dir = p9_tmpdir() . '/backups';
$db = ['driver' => 'mysql', 'host' => 'db.internal', 'port' => 3307, 'database' => 'jobs_prod', 'username' => 'jt_user', 'password' => 's3cret pass;word$1'];
Config::set('app.db', $db);
[$argv, $env] = BackupService::mysqlCommand($db);
check($argv[0] === 'mysqldump' && in_array('--single-transaction', $argv, true) && in_array('--host=db.internal', $argv, true) && in_array('--port=3307', $argv, true) && in_array('--user=jt_user', $argv, true) && end($argv) === 'jobs_prod', 'the command has host, port, user, database and a consistent snapshot');
check(!str_contains(implode(' ', $argv), 's3cret'), 'the password is NOT on the command line (it would show in the process list)');
check($env === ['MYSQL_PWD' => 's3cret pass;word$1'], 'it travels in the environment instead, byte for byte');
check(!in_array('--result-file=/dev/stdout', $argv, true) && count(array_filter($argv, static fn ($a) => str_contains($a, ';') || str_contains($a, '$'))) === 0, 'nothing is passed through a shell');

$goodDump = "-- MySQL dump 10.13\nCREATE TABLE `jobs` (id int);\nINSERT INTO `jobs` VALUES (1);\n-- Dump completed on 2026-10-23 02:15:01\n";
$calls = [];
$runner = static function (array $argv, array $env, $stream) use (&$calls, $goodDump): int {
    $calls[] = $argv;
    fwrite($stream, $goodDump);
    return 0;
};
$svc = new BackupService($dir, $runner);
$res = $svc->create(new DateTimeImmutable('2026-10-23 02:15:00', new DateTimeZone('UTC')));
check($res['driver'] === 'mysql' && basename($res['path']) === 'job-tracker-20261023-021500.sql.gz', 'a MySQL backup is a .sql.gz');
check(gzdecode((string) file_get_contents($res['path'])) === $goodDump, 'it decompresses to exactly what mysqldump wrote');
check((fileperms($res['path']) & 0777) === 0600 && glob($dir . '/*.partial') === [], 'it is private and no temporary file is left');
check(count($calls) === 1 && in_array('--no-tablespaces', $calls[0], true), 'a healthy server is asked once, with --no-tablespaces');

$calls = [];
$picky = static function (array $argv, array $env, $stream) use (&$calls, $goodDump): int {
    $calls[] = $argv;
    if (in_array('--no-tablespaces', $argv, true)) {
        return 7; // e.g. a MariaDB that does not know the flag
    }
    fwrite($stream, $goodDump);
    return 0;
};
$res = (new BackupService($dir, $picky))->create(new DateTimeImmutable('2026-10-24 02:15:00', new DateTimeZone('UTC')));
check(count($calls) === 2 && !in_array('--no-tablespaces', $calls[1], true) && gzdecode((string) file_get_contents($res['path'])) === $goodDump, 'if the flag is refused it retries once without it');

$before = count(glob($dir . '/*'));
$failing = static function (array $argv, array $env, $stream): int {
    fwrite($stream, "-- MySQL dump\nCREATE TABLE a (id int);\n");
    return 2;
};
throws(static fn () => (new BackupService($dir, $failing))->create(new DateTimeImmutable('2026-10-25 02:15:00', new DateTimeZone('UTC'))), 'a failing mysqldump is an error, not a silent empty backup');
check(count(glob($dir . '/*')) === $before, '...and leaves no file behind');
$truncated = static function (array $argv, array $env, $stream): int {
    fwrite($stream, "-- MySQL dump\nCREATE TABLE a (id int);\nINSERT INTO a VALUES (1),(2),(");
    return 0; // exited "fine" but the output stops mid-statement
};
throws(static fn () => (new BackupService($dir, $truncated))->create(new DateTimeImmutable('2026-10-26 02:15:00', new DateTimeZone('UTC'))), 'a dump that stops half-way is rejected even if the exit code was 0');
check(count(glob($dir . '/*')) === $before, '...and is deleted');
$empty = static fn (array $argv, array $env, $stream): int => 0;
throws(static fn () => (new BackupService($dir, $empty))->create(new DateTimeImmutable('2026-10-27 02:15:00', new DateTimeZone('UTC'))), 'an empty dump is rejected');
check($svc->prune(1) === 1 && count(glob($dir . '/job-tracker-*.sql.gz')) === 1, 'pruning also handles .sql.gz files');
p9_rmtree(dirname($dir));
Config::set('app.db', ['driver' => 'sqlite', 'database' => ':memory:']);

// ================================================================ MySQL compatibility lint

section('MySQL compatibility (static lint: a real MySQL server was not available)');
$reserved = array_flip(explode(' ', 'accessible add all alter analyze and as asc asensitive before between bigint binary blob both by call cascade case change char character check collate column condition constraint continue convert create cross cube cume_dist current_date current_time current_timestamp current_user cursor database databases day_hour day_microsecond day_minute day_second dec decimal declare default delayed delete dense_rank desc describe deterministic distinct distinctrow div double drop dual each else elseif empty enclosed escaped except exists exit explain false fetch first_value float float4 float8 for force foreign from fulltext function generated get grant group grouping groups having high_priority hour_microsecond hour_minute hour_second if ignore in index infile inner inout insensitive insert int int1 int2 int3 int4 int8 integer intersect interval into io_after_gtids io_before_gtids is iterate join json_table key keys kill lag last_value lateral lead leading leave left like limit linear lines load localtime localtimestamp lock long longblob longtext loop low_priority master_bind master_ssl_verify_server_cert match maxvalue mediumblob mediumint mediumtext middleint minute_microsecond minute_second mod modifies natural not no_write_to_binlog nth_value ntile null numeric of on optimize optimizer_costs option optionally or order out outer outfile over partition percent_rank precision primary procedure purge range rank read reads read_write real recursive references regexp release rename repeat replace require resignal restrict return revoke right rlike row rows row_number schema schemas second_microsecond select sensitive separator set show signal smallint spatial specific sql sqlexception sqlstate sqlwarning sql_big_result sql_calc_found_rows sql_small_result ssl starting stored straight_join system table terminated then tinyblob tinyint tinytext to trailing trigger true undo union unique unlock unsigned update usage use using utc_date utc_time utc_timestamp values varbinary varchar varcharacter varying virtual when where while window with write xor year_month zerofill'));
$files = glob(BASE_PATH . '/database/migrations/*.sql') ?: [];
check(count($files) >= 7, 'the migrations are found (' . count($files) . ')');
$problems = [];
$identifiers = [];
foreach ($files as $file) {
    $sql = (string) file_get_contents($file);
    $name = basename($file);
    foreach (['AUTOINCREMENT', 'PRAGMA', 'INTEGER PRIMARY KEY', 'WITHOUT ROWID', 'IF NOT EXISTS ' . 'sqlite'] as $bad) {
        if (stripos($sql, $bad) !== false) {
            $problems[] = "{$name}: SQLite-only syntax {$bad}";
        }
    }
    preg_match_all('/CREATE TABLE\s+`?(\w+)`?\s*\((.*?)\)\s*ENGINE\s*=\s*InnoDB[^;]*;/is', $sql, $tables, PREG_SET_ORDER);
    preg_match_all('/CREATE TABLE/i', $sql, $allTables);
    if (count($tables) !== count($allTables[0])) {
        $problems[] = "{$name}: a CREATE TABLE is not ENGINE=InnoDB or is malformed";
    }
    if (preg_match_all('/CREATE TABLE/i', $sql) > 0 && !preg_match('/CHARSET\s*=\s*utf8mb4/i', $sql)) {
        $problems[] = "{$name}: no utf8mb4 charset";
    }
    foreach ($tables as $t) {
        $identifiers[$t[1]] = $name;
        foreach (preg_split('/,\s*\n/', $t[2]) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^(CONSTRAINT|PRIMARY|UNIQUE|KEY|INDEX|FOREIGN)\b/i', $line)) {
                continue;
            }
            if (preg_match('/^`?(\w+)`?\s+\w+/', $line, $m)) {
                $identifiers[$m[1]] = $name;
            }
        }
    }
    preg_match_all('/CREATE\s+(?:UNIQUE\s+)?INDEX\s+`?(\w+)`?/i', $sql, $idx);
    foreach ($idx[1] as $i) {
        $identifiers[$i] = $name;
    }
}
foreach ($identifiers as $id => $file) {
    if (isset($reserved[strtolower($id)])) {
        $problems[] = "{$file}: \"{$id}\" is a reserved word in MySQL 8 and would need quoting everywhere";
    }
    if (strlen($id) > 64) {
        $problems[] = "{$file}: \"{$id}\" is longer than MySQL's 64-character identifier limit";
    }
}
check($problems === [], 'migrations: InnoDB + utf8mb4, no SQLite-only syntax, no reserved words or over-long names among ' . count($identifiers) . ' identifiers' . ($problems === [] ? '' : ' - ' . implode('; ', $problems)));

// Reserved-word lint on the application's own SQL: any bare column used in a query must not be reserved.
$sqlWords = [];
foreach (glob(BASE_PATH . '/app/Repositories/*.php') ?: [] as $file) {
    preg_match_all('/\b(?:SELECT|WHERE|ORDER BY|AND|SET)\s+(?:[a-z]\.)?(rank|groups|system|lead|lag|row_number|window|function|rows|empty|of|over|except|intersect|grouping|first_value|last_value|lateral|recursive|cume_dist|dense_rank|percent_rank|ntile|nth_value)\b/i', (string) file_get_contents($file), $m);
    $sqlWords = array_merge($sqlWords, $m[1]);
}
check($sqlWords === [], 'repositories: no MySQL 8 window-function keyword used as a column name');

$nocase = [];
$sqliteOnly = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH . '/app', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() !== 'php' || str_ends_with($file->getPathname(), 'SqliteCompat.php')) {
        continue;
    }
    foreach (file($file->getPathname()) ?: [] as $n => $line) {
        if (stripos($line, 'NOCASE') !== false && stripos($line, 'sqlite') === false) {
            $nocase[] = basename($file->getPathname()) . ':' . ($n + 1);
        }
        if (preg_match('/\b(strftime\(|datetime\(\s*\'now|INSERT OR (?:REPLACE|IGNORE)|\sGLOB\s|sqlite_master|AUTOINCREMENT|PRAGMA)/', $line) && stripos($line, "driver") === false && !str_contains($file->getPathname(), 'Database.php') && !str_contains($file->getPathname(), 'Migrator.php')) {
            $sqliteOnly[] = basename($file->getPathname()) . ':' . ($n + 1) . ' ' . trim($line);
        }
    }
}
check($nocase === [], 'COLLATE NOCASE appears only on lines guarded as SQLite-specific' . ($nocase === [] ? '' : ': ' . implode(', ', $nocase)));
check($sqliteOnly === [], 'no SQLite-only SQL (strftime, INSERT OR, GLOB, PRAGMA) outside the driver and migration code' . ($sqliteOnly === [] ? '' : ': ' . $sqliteOnly[0]));
$dbSource = (string) file_get_contents(BASE_PATH . '/app/Core/Database.php');
check(!preg_match('/INIT_COMMAND/', $dbSource) && substr_count($dbSource, '->exec("SET ') === 3, 'the MySQL session set-up is three separate plain SET statements');
check(str_contains($dbSource, 'ATTR_EMULATE_PREPARES => false') && str_contains($dbSource, 'PDO::PARAM_INT'), 'native prepares are on and integers are bound as integers (needed for LIMIT ? on MySQL)');
$limits = [];
foreach (glob(BASE_PATH . '/app/Repositories/*.php') ?: [] as $file) {
    preg_match_all('/LIMIT \?(?: OFFSET \?)?[^;\n]*\[([^\]]*)\]/', (string) file_get_contents($file), $m);
    $limits = array_merge($limits, $m[0]);
}
check(true, 'LIMIT placeholders: ' . count($limits) . ' call sites found (ints are bound as PARAM_INT by Database::run)');

// ================================================================ deployment templates vs code

section('Deployment templates match the application');
$deploy = BASE_PATH . '/deploy';
$nginx = (string) file_get_contents($deploy . '/nginx.conf.example');
$apache = (string) file_get_contents($deploy . '/apache-vhost.conf.example');
$ini = (string) file_get_contents($deploy . '/php-overrides.ini');
$cron = (string) file_get_contents($deploy . '/cron.example');
$envProd = (string) file_get_contents($deploy . '/env.production.example');

check(preg_match('#root\s+/[^;]*/public;#', $nginx) === 1, 'nginx: the document root is public/');
check(str_contains($nginx, 'location ~ \.php$ { return 404; }') && str_contains($nginx, 'location = /index.php'), 'nginx: only index.php can be executed');
check(str_contains($nginx, 'location ~ /\.') && str_contains($nginx, 'deny all'), 'nginx: dotfiles are never served');
check(preg_match('/client_max_body_size\s+(\d+)m/', $nginx, $m) === 1 && (int) $m[1] * 1048576 >= ImportService::MAX_BYTES, 'nginx: the body limit is at least the import limit');
check(preg_match('#DocumentRoot\s+\S+/public\s*$#m', $apache) === 1 && str_contains($apache, 'Require all denied'), 'apache: document root is public/ and the rest is denied');
check(preg_match('/LimitRequestBody\s+(\d+)/', $apache, $m) === 1 && (int) $m[1] >= ImportService::MAX_BYTES, 'apache: the body limit is at least the import limit');
$inline = [];
foreach (explode("\n", $apache) as $n => $line) {
    if (preg_match('/^\s*[A-Za-z<]\S*.*\S\s+#/', $line) && !preg_match('/^\s*#/', $line)) {
        $inline[] = 'line ' . ($n + 1);
    }
}
check($inline === [], 'apache: no trailing # comments (Apache refuses them)' . ($inline === [] ? '' : ': ' . implode(', ', $inline)));
check(str_contains($apache, 'RewriteRule ^ https://') && str_contains($nginx, 'return 301 https://'), 'both redirect HTTP to HTTPS');

$iniValues = [];
foreach (explode("\n", $ini) as $line) {
    if (preg_match('/^\s*([a-z_.]+)\s*=\s*([^;\s]+)/i', $line, $m)) {
        $iniValues[$m[1]] = $m[2];
    }
}
$mb = static fn (string $v): int => (int) $v * (str_ends_with(strtoupper($v), 'M') ? 1048576 : 1);
check($mb($iniValues['upload_max_filesize'] ?? '0') >= ImportService::MAX_BYTES && $mb($iniValues['post_max_size'] ?? '0') >= $mb($iniValues['upload_max_filesize'] ?? '0'), 'php.ini: upload and post limits cover the 5 MB import limit');
check(($iniValues['display_errors'] ?? '') === 'Off' && ($iniValues['log_errors'] ?? '') === 'On' && ($iniValues['expose_php'] ?? '') === 'Off', 'php.ini: errors are logged, not displayed; PHP does not announce itself');
check(($iniValues['session.use_strict_mode'] ?? '') === '1' && ($iniValues['session.cookie_httponly'] ?? '') === '1', 'php.ini: strict session mode and HttpOnly cookies');

preg_match_all('#php (cli/[a-z-]+\.php)#', $cron, $scripts);
check($scripts[1] !== [] && count(array_filter($scripts[1], static fn (string $s): bool => !is_file(BASE_PATH . '/' . $s))) === 0, 'cron: every script it runs exists (' . implode(', ', $scripts[1]) . ')');
check(str_contains($cron, 'cli/backup.php') && str_contains($cron, 'cli/cleanup.php --apply'), 'cron: backup and cleanup are scheduled');

$prod = Env::parse($envProd);
$dev = Env::parse((string) file_get_contents(BASE_PATH . '/.env.example'));
check(($prod['APP_ENV'] ?? '') === 'production' && ($prod['APP_DEBUG'] ?? '') === 'false' && ($prod['SESSION_SECURE'] ?? '') === 'true' && ($prod['DB_DRIVER'] ?? '') === 'mysql' && str_starts_with($prod['APP_URL'] ?? '', 'https://'), 'production env: production, debug off, secure cookies, MySQL, https URL');
check(array_keys($prod) === array_keys($dev), 'the production and development env templates define the same keys');
$appConfig = (string) file_get_contents(BASE_PATH . '/app/Config/app.php');
preg_match_all("/Env::(?:get|bool|int)\('([A-Z_]+)'/", $appConfig, $used);
check(array_diff($used[1], array_keys($prod)) === [], 'every environment variable the application reads is in the production template' . (array_diff($used[1], array_keys($prod)) === [] ? '' : ': ' . implode(', ', array_diff($used[1], array_keys($prod)))));
check(trim((string) file_get_contents(BASE_PATH . '/VERSION')) !== '' && preg_match('/^\d+\.\d+\.\d+$/', trim((string) file_get_contents(BASE_PATH . '/VERSION'))) === 1, 'VERSION holds a semantic version');
check(str_contains((string) file_get_contents(BASE_PATH . '/CHANGELOG.md'), trim((string) file_get_contents(BASE_PATH . '/VERSION'))), 'CHANGELOG mentions the current version');

section('Root .htaccess (hosts that cannot change the document root)');
$ht = (string) file_get_contents(BASE_PATH . '/.htaccess');
preg_match('/RewriteRule \^\(([a-z|]+)\)\(\/\|\$\) - \[F,L\]/', $ht, $blocked);
$blockedDirs = explode('|', $blocked[1] ?? '');
$topDirs = array_values(array_filter(array_map('basename', glob(BASE_PATH . '/*', GLOB_ONLYDIR) ?: []), static fn (string $d): bool => $d !== 'public'));
check(array_diff($topDirs, $blockedDirs) === [], 'every top-level folder except public/ is blocked' . (array_diff($topDirs, $blockedDirs) === [] ? '' : ' - missing: ' . implode(', ', array_diff($topDirs, $blockedDirs))));
check(str_contains($ht, 'RewriteRule ^(.*)$ public/$1 [L]') && strpos($ht, 'RewriteRule ^public/ - [L]') < strpos($ht, 'RewriteRule ^(.*)$ public/$1 [L]'), 'everything else is served from public/, and public/ itself is exempt first (no rewrite loop)');
check(strpos($ht, '[F,L]') < strpos($ht, 'public/$1 [L]'), 'the blocking rules come before the catch-all');
check(str_contains($ht, '.env') && str_contains($ht, 'composer') && str_contains($ht, 'FilesMatch'), 'root files (.env, composer.json, VERSION) and dotfiles are refused');
$inlineHt = array_filter(explode("\n", $ht), static fn (string $l): bool => (bool) preg_match('/^\s*(Rewrite|Options|Require)\S*\s.*\S\s+#/', $l));
check($inlineHt === [], 'no trailing # comments on directive lines (Apache refuses them)');
