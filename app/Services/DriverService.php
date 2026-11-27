<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;

/**
 * Drivers (v1.19.0, assumption AQ1): name, phone and licence number. A driver is the job's Driver name, matched by name ignoring letter
 * case (no foreign key), so a driver with no record here just has no details. Renaming a driver can rename the driver on its jobs too.
 * Invoices keep a copy of the licence taken when they were saved. Same idea as CompanyService.
 */
final class DriverService
{
    public const MAX_ROWS = 500;

    /** field => [label, maximum length, pattern the text must match] */
    public const FIELDS = [
        'phone' => ['Phone', 30, '/^[0-9 ()+.\\-\\/xX#]+$/'],
        'license' => ['License number', 50, '/^[A-Za-z0-9 \\-#.\\/]+$/'],
    ];

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::fetchOne('SELECT * FROM drivers WHERE id = ?', [$id]);
    }

    /** @return array<string,mixed>|null the driver with this name (letter case ignored) */
    public static function findByName(string $name): ?array
    {
        $name = CompanyService::clean($name);
        return $name === '' ? null : Database::fetchOne('SELECT * FROM drivers WHERE LOWER(name) = ?', [mb_strtolower($name)]);
    }

    /** @return array<int,string> */
    public static function names(): array
    {
        return array_map(static fn (array $r): string => (string) $r['name'], Database::fetchAll('SELECT name FROM drivers ORDER BY name'));
    }

    /** The drivers offered in the Driver name box: those already used on jobs plus the ones added here, each once. @return array<int,string> */
    public static function choices(): array
    {
        $used = array_map(
            static fn (array $r): string => (string) $r['driver_name'],
            Database::fetchAll("SELECT DISTINCT driver_name FROM jobs WHERE deleted_at IS NULL AND driver_name IS NOT NULL AND driver_name <> ''")
        );
        $all = [];
        foreach ([...$used, ...self::names()] as $name) {
            $name = CompanyService::clean($name);
            if ($name !== '') {
                $all[mb_strtolower($name)] ??= $name;
            }
        }
        $names = array_values($all);
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        return $names;
    }

    /** @return array<string,string> driver name => phone, only for drivers that have one (to fill the phone box when a driver is picked) */
    public static function phones(): array
    {
        $out = [];
        foreach (Database::fetchAll("SELECT name, phone FROM drivers WHERE phone IS NOT NULL AND phone <> '' ORDER BY name") as $r) {
            $out[(string) $r['name']] = (string) $r['phone'];
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> drivers with the number of (not deleted) jobs that carry their name */
    public static function search(string $q): array
    {
        $where = '1 = 1';
        $params = [];
        $q = CompanyService::clean($q);
        if ($q !== '') {
            $like = '%' . mb_strtolower($q) . '%';
            $where = "(LOWER(d.name) LIKE ? OR LOWER(COALESCE(d.phone, '')) LIKE ? OR LOWER(COALESCE(d.license, '')) LIKE ?)";
            $params = [$like, $like, $like];
        }
        return Database::fetchAll(
            'SELECT d.*, (SELECT COUNT(*) FROM jobs j WHERE j.deleted_at IS NULL AND j.driver_name IS NOT NULL AND LOWER(j.driver_name) = LOWER(d.name)) AS job_count
             FROM drivers d WHERE ' . $where . ' ORDER BY d.name ASC LIMIT ' . self::MAX_ROWS,
            $params
        );
    }

    public static function jobCount(string $name): int
    {
        return (int) Database::fetchValue('SELECT COUNT(*) FROM jobs WHERE deleted_at IS NULL AND driver_name IS NOT NULL AND LOWER(driver_name) = ?', [mb_strtolower($name)]);
    }

    /** Driver names on jobs that have no record yet. @return array<int,array{driver:string,jobs:int}> */
    public static function unlinked(): array
    {
        $rows = Database::fetchAll(
            "SELECT driver_name, COUNT(*) AS jobs FROM jobs WHERE deleted_at IS NULL AND driver_name IS NOT NULL AND driver_name <> '' AND LOWER(driver_name) NOT IN (SELECT LOWER(name) FROM drivers) GROUP BY driver_name ORDER BY driver_name ASC LIMIT " . self::MAX_ROWS
        );
        return array_map(static fn (array $r): array => ['driver' => (string) $r['driver_name'], 'jobs' => (int) $r['jobs']], $rows);
    }

    /**
     * @param array<string,mixed> $input
     * @return array{values:array<string,string>,errors:array<string,string>}
     */
    public static function validate(array $input, ?int $ignoreId): array
    {
        $errors = [];
        $values = [];
        $name = CompanyService::clean($input['name'] ?? '');
        if ($name === '') {
            $errors['name'] = 'Enter the driver\'s name.';
        } elseif (mb_strlen($name) > 150) {
            $errors['name'] = 'The name can be at most 150 characters.';
        } elseif (preg_match('/^[^<>]+$/u', $name) !== 1) {
            $errors['name'] = 'The name has characters that are not allowed.';
        } else {
            $other = self::findByName($name);
            if ($other !== null && (int) $other['id'] !== (int) $ignoreId) {
                $errors['name'] = 'There is already a driver with this name.';
            }
        }
        $values['name'] = $name;
        foreach (self::FIELDS as $key => [$label, $max, $pattern]) {
            $text = CompanyService::clean($input[$key] ?? '');
            if (mb_strlen($text) > $max) {
                $errors[$key] = $label . ' can be at most ' . $max . ' characters.';
            } elseif ($text !== '' && preg_match($pattern, $text) !== 1) {
                $errors[$key] = $label . ' has characters that are not allowed.';
            }
            $values[$key] = $text;
        }
        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * @param array<string,mixed> $input
     * @return array{ok:bool,errors:array<string,string>,id?:int,jobs?:int}
     */
    public static function save(?int $id, array $input, bool $renameJobs): array
    {
        $before = $id === null ? null : self::find($id);
        if ($id !== null && $before === null) {
            return ['ok' => false, 'errors' => ['name' => 'not_found']];
        }
        $v = self::validate($input, $id);
        if ($v['errors'] !== []) {
            return ['ok' => false, 'errors' => $v['errors']];
        }
        $values = $v['values'];
        $now = gmdate('Y-m-d H:i:s');
        $orNull = static fn (string $s): ?string => $s === '' ? null : $s;

        if ($before === null) {
            $newId = Database::insert(
                'INSERT INTO drivers (name, phone, license, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$values['name'], $orNull($values['phone']), $orNull($values['license']), Auth::id(), $now, $now]
            );
            AuditService::record('driver_created', 'driver', $newId, null, $values);
            return ['ok' => true, 'errors' => [], 'id' => $newId];
        }

        $jobs = 0;
        Database::transaction(static function () use ($id, $values, $before, $renameJobs, $now, $orNull, &$jobs): void {
            Database::execute(
                'UPDATE drivers SET name = ?, phone = ?, license = ?, updated_at = ? WHERE id = ?',
                [$values['name'], $orNull($values['phone']), $orNull($values['license']), $now, $id]
            );
            if ($renameJobs && $values['name'] !== (string) $before['name']) {
                $jobs = Database::execute('UPDATE jobs SET driver_name = ? WHERE driver_name IS NOT NULL AND LOWER(driver_name) = ?', [$values['name'], mb_strtolower((string) $before['name'])]);
            }
            $old = [];
            $new = [];
            foreach ($values as $key => $value) {
                $was = (string) ($before[$key] ?? '');
                if ($was !== $value) {
                    $old[$key] = $was;
                    $new[$key] = $value;
                }
            }
            if ($new !== []) {
                AuditService::record('driver_updated', 'driver', $id, $old, $new + ($jobs > 0 ? ['jobs_renamed' => (string) $jobs] : []));
            }
        });
        return ['ok' => true, 'errors' => [], 'id' => $id, 'jobs' => $jobs];
    }

    /** Remove the details; jobs and invoices keep the driver name. */
    public static function delete(int $id): bool
    {
        $driver = self::find($id);
        if ($driver === null) {
            return false;
        }
        Database::transaction(static function () use ($id, $driver): void {
            Database::execute('DELETE FROM drivers WHERE id = ?', [$id]);
            AuditService::record('driver_deleted', 'driver', $id, ['name' => (string) $driver['name']], null);
        });
        return true;
    }
}
