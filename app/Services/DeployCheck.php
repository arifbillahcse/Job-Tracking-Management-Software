<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Database\Migrator;
use App\Repositories\ForemanRepository;
use App\Repositories\JobRepository;
use App\Repositories\TripRepository;
use Throwable;

/**
 * Read-only check of a deployment (php cli/verify.php). It runs every query the application uses for jobs, trips and foremen
 * against the real database, which proves they work on that database engine (the automated tests only run on SQLite),
 * and reports the settings and data that decide what people see. It never writes anything.
 *
 * Levels: ok, info (a fact), warn (check this), fail (broken).
 */
final class DeployCheck
{
    /** @return array<int,array{level:string,label:string,detail:string}> */
    public static function run(): array
    {
        $rows = [];
        $add = static function (string $level, string $label, string $detail = '') use (&$rows): void {
            $rows[] = ['level' => $level, 'label' => $label, 'detail' => $detail];
        };
        $try = static function (string $label, callable $fn) use ($add): void {
            try {
                $detail = $fn();
                $add('ok', $label, is_string($detail) ? $detail : '');
            } catch (Throwable $e) {
                $add('fail', $label, substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 0, 240));
            }
        };

        $add('info', 'Code', 'version ' . app_version() . (app_build() !== null ? ', commit ' . app_build() : ', not a git clone'));
        try {
            $driver = Database::driver();
            $version = (string) Database::fetchValue($driver === 'sqlite' ? 'SELECT sqlite_version()' : 'SELECT VERSION()');
            $add('info', 'Database', $driver . ' ' . $version);
        } catch (Throwable $e) {
            $add('fail', 'Database is not reachable', $e->getMessage());
            return $rows;
        }

        $pending = array_filter((new Migrator())->status(), static fn (array $m): bool => !$m['applied']);
        $pending === [] ? $add('ok', 'All migrations applied', count((new Migrator())->status()) . ' files') : $add('fail', 'Migrations pending', implode(', ', array_column($pending, 'filename')));

        // Columns and tables the newer features need.
        $schema = [
            'jobs' => ['customer_phone', 'loaded_date', 'delivery_date', 'storage_fee_override', 'storage_rate', 'estimated_cf', 'estimated_total', 'deposit', 'grand_total', 'bh_total', 'paid', 'job_lot', 'color', 'foreman_id', 'trip_id'],
            'foremen' => ['id', 'name', 'phone', 'active'],
            'trips' => ['id', 'trip_number', 'foreman_id', 'begins_on', 'ends_on', 'collected_by', 'deleted_at'],
            'remember_tokens' => ['user_id', 'selector', 'token_hash', 'pw_marker', 'expires_at'],
            'trip_jobs' => ['id', 'trip_id', 'job_id', 'job_number', 'customer_name', 'diesel', 'hotels', 'helper', 'misc', 'commission', 'extra', 'remark'],
        ];
        foreach ($schema as $table => $columns) {
            $try("Table {$table} has its columns", static function () use ($table, $columns): string {
                Database::fetchAll('SELECT ' . implode(', ', $columns) . " FROM {$table} WHERE 1 = 0");
                return count($columns) . ' columns';
            });
        }

        // Every list, filter, report and export query.
        $jobs = new JobRepository();
        foreach (array_keys(JobBucket::definitions()) as $bucket) {
            $try("Job list: {$bucket}", static function () use ($jobs, $bucket): string {
                $n = $jobs->count(JobCriteria::forBucket($bucket));
                $jobs->fetch(JobCriteria::forBucket($bucket), 5, 0);
                return $n . ' jobs';
            });
        }
        $req = static fn (array $q): JobCriteria => JobCriteria::fromRequest(\App\Core\Request::make('GET', '/jobs', [], $q), JobBucket::ALL, true);
        foreach ([['foreman' => '1'], ['foreman' => 'none'], ['trip' => 'T'], ['q' => 'a'], ['sort' => 'assign', 'dir' => 'desc'], ['sort' => 'storage', 'dir' => 'desc']] as $q) {
            $try('Job list with ' . http_build_query($q), static function () use ($jobs, $req, $q): string {
                $jobs->count($req($q));
                $jobs->fetch($req($q), 5, 0);
                return '';
            });
        }
        $try('Dashboard figures', static function (): string {
            (new DashboardService())->build(true);
            return '';
        });

        $trips = new TripRepository();
        $tc = TripCriteria::fromRequest(\App\Core\Request::make('GET', '/trips', [], ['q' => 'a', 'foreman' => '1', 'from' => '2020-01-01', 'to' => '2030-01-01']));
        $tripQueries = [
            'Trip list and search' => static fn () => [$trips->count($tc), $trips->search($tc, 5, 0)],
            'Trip pick list (job form)' => static fn () => $trips->choices(1),
            'Trips a job can be added to' => static fn () => $trips->choicesForJob(1),
            'Trips of a job' => static fn () => $trips->forJob(1),
            'Foreman report' => static fn () => $trips->foremanReport($tc),
            'Dashboard trip figures' => static fn () => $trips->recentFigures('2020-01-01'),
            'Trip export lines' => static fn () => [$trips->countJobRows($tc), $trips->jobRows($tc, 5, 0)],
            'Trip rows with job status' => static fn () => $trips->rows(1),
        ];
        foreach ($tripQueries as $label => $fn) {
            $try($label, static function () use ($fn): string {
                $fn();
                return '';
            });
        }
        $foremen = new ForemanRepository();
        $try('Foremen page, pick list and filter', static function () use ($foremen): string {
            $n = count($foremen->allWithTotals());
            $foremen->options(1);
            $foremen->allForFilter();
            return $n . ' foremen';
        });
        $try('Storage fee calculation', static function (): string {
            $fee = StorageFeeService::assess(['status' => 'booked', 'cf' => '1000', 'pickup_date' => '2020-01-01', 'first_available_date' => '2020-01-01', 'loaded_date' => null, 'delivery_date' => null, 'storage_rate' => null, 'storage_fee_override' => null], '2020-02-15');
            return 'A-CF 1000 x ' . StorageFeeService::rate() . ' after 45 days = ' . $fee['fee'];
        });

        // Settings that decide what the client sees.
        foreach (['section_basis' => 'pickup', 'storage_fee_basis' => 'pickup', 'storage_fee_mode' => 'monthly'] as $key => $wanted) {
            $value = (string) SettingsService::get($key, '');
            $value === $wanted ? $add('ok', "Setting {$key}", $value) : $add('warn', "Setting {$key} is \"{$value}\"", "the client asked for \"{$wanted}\"; change it under Settings if that is not on purpose");
        }
        $add('info', 'Storage fee', 'rate ' . StorageFeeService::rate() . ' per CF, after ' . StorageFeeService::days() . ' days; ready limit ' . JobBucket::threshold() . ' days');

        // The data itself.
        $count = static fn (string $sql): int => (int) Database::fetchValue($sql);
        try {
            $add('info', 'Data', sprintf(
                '%d jobs (%d with a foreman, %d with a trip), %d foremen, %d live trips, %d trip rows',
                $count('SELECT COUNT(*) FROM jobs WHERE deleted_at IS NULL'),
                $count('SELECT COUNT(*) FROM jobs WHERE deleted_at IS NULL AND foreman_id IS NOT NULL'),
                $count('SELECT COUNT(*) FROM jobs WHERE deleted_at IS NULL AND trip_id IS NOT NULL'),
                $count('SELECT COUNT(*) FROM foremen'),
                $count('SELECT COUNT(*) FROM trips WHERE deleted_at IS NULL'),
                $count('SELECT COUNT(*) FROM trip_jobs')
            ));
        } catch (Throwable $e) {
            $add('fail', 'Data counts', substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 0, 240));
        }
        $checks = [
            'Jobs assigned to a trip that is deleted or missing' => 'SELECT COUNT(*) FROM jobs j LEFT JOIN trips t ON t.id = j.trip_id WHERE j.deleted_at IS NULL AND j.trip_id IS NOT NULL AND (t.id IS NULL OR t.deleted_at IS NOT NULL)',
            'Jobs assigned to a foreman that does not exist' => 'SELECT COUNT(*) FROM jobs j LEFT JOIN foremen f ON f.id = j.foreman_id WHERE j.foreman_id IS NOT NULL AND f.id IS NULL',
            'Jobs whose foreman differs from their trip\'s foreman' => 'SELECT COUNT(*) FROM jobs j JOIN trips t ON t.id = j.trip_id WHERE j.deleted_at IS NULL AND t.deleted_at IS NULL AND j.foreman_id <> t.foreman_id',
            'Jobs assigned to a trip that has no row for them' => 'SELECT COUNT(*) FROM jobs j WHERE j.deleted_at IS NULL AND j.trip_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM trip_jobs tj WHERE tj.trip_id = j.trip_id AND tj.job_id = j.id)',
            'Booked jobs with no pickup date (counted from their 1st available date)' => "SELECT COUNT(*) FROM jobs WHERE deleted_at IS NULL AND status = 'booked' AND pickup_date IS NULL",
        ];
        foreach ($checks as $label => $sql) {
            try {
                $n = $count($sql);
                $n === 0 ? $add('ok', $label, 'none') : $add('warn', $label, $n . ' job(s)');
            } catch (Throwable $e) {
                $add('fail', $label, substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 0, 240));
            }
        }

        return $rows;
    }
}
