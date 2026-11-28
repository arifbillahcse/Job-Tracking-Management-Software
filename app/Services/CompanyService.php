<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;

/**
 * Companies (v1.18.0, assumption AP1): name, address, city, phone, DOT and MC number. A company is the job's Broker, matched by name
 * ignoring letter case (no foreign key), so a broker with no record here just has no details. Renaming a company can rename the
 * broker on its jobs too. Invoices keep a copy of the details taken when they were saved.
 */
final class CompanyService
{
    public const MAX_ROWS = 500;

    /** field => [label, maximum length, pattern the text must match] */
    public const FIELDS = [
        'address' => ['Address', 190, '/^[^<>"\\\\]+$/u'],
        'city' => ['City', 100, '/^[^<>"\\\\]+$/u'],
        'phone' => ['Phone', 30, '/^[0-9 ()+.\\-\\/xX#]+$/'],
        'dot_number' => ['DOT number', 30, '/^[A-Za-z0-9 \\-#.]+$/'],
        'mc_number' => ['MC number', 30, '/^[A-Za-z0-9 \\-#.]+$/'],
    ];

    /** Collapse whitespace and drop control characters. */
    public static function clean(mixed $value): string
    {
        return is_string($value) ? trim((string) preg_replace('/[\p{Cc}\s]+/u', ' ', $value)) : '';
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::fetchOne('SELECT * FROM companies WHERE id = ?', [$id]);
    }

    /** @return array<string,mixed>|null the company with this name (letter case ignored) */
    public static function findByName(string $name): ?array
    {
        $name = self::clean($name);
        return $name === '' ? null : Database::fetchOne('SELECT * FROM companies WHERE LOWER(name) = ?', [mb_strtolower($name)]);
    }

    /** @return array<int,string> every company name, for pick lists */
    public static function names(): array
    {
        return array_map(static fn (array $r): string => (string) $r['name'], Database::fetchAll('SELECT name FROM companies ORDER BY name'));
    }

    /** The names offered in the job form's Broker box: brokers already used on jobs plus the companies, each once. @return array<int,string> */
    public static function brokerChoices(): array
    {
        $all = [];
        foreach ([...(new \App\Repositories\JobRepository())->brokers(), ...self::names()] as $name) {
            $name = self::clean($name);
            if ($name !== '') {
                $all[mb_strtolower($name)] ??= $name;
            }
        }
        $names = array_values($all);
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        return $names;
    }

    /**
     * @return array<int,array<string,mixed>> companies with the number of (not deleted) jobs that carry their name as broker
     */
    public static function search(string $q): array
    {
        $where = '1 = 1';
        $params = [];
        $q = self::clean($q);
        if ($q !== '') {
            $like = '%' . strtr(mb_strtolower($q), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $where = "(LOWER(c.name) LIKE ? ESCAPE '!' OR LOWER(COALESCE(c.city, '')) LIKE ? ESCAPE '!' OR LOWER(COALESCE(c.dot_number, '')) LIKE ? ESCAPE '!' OR LOWER(COALESCE(c.mc_number, '')) LIKE ? ESCAPE '!' OR LOWER(COALESCE(c.address, '')) LIKE ? ESCAPE '!')";
            $params = [$like, $like, $like, $like, $like];
        }
        return Database::fetchAll(
            'SELECT c.*, (SELECT COUNT(*) FROM jobs j WHERE j.deleted_at IS NULL AND j.broker IS NOT NULL AND LOWER(j.broker) = LOWER(c.name)) AS job_count
             FROM companies c WHERE ' . $where . ' ORDER BY c.name ASC LIMIT ' . self::MAX_ROWS,
            $params
        );
    }

    /** @return int how many (not deleted) jobs have this broker name, letter case ignored */
    public static function jobCount(string $name): int
    {
        return (int) Database::fetchValue('SELECT COUNT(*) FROM jobs WHERE deleted_at IS NULL AND broker IS NOT NULL AND LOWER(broker) = ?', [mb_strtolower($name)]);
    }

    /** Brokers on jobs that have no company record yet. @return array<int,array{broker:string,jobs:int}> */
    public static function unlinked(): array
    {
        $rows = Database::fetchAll(
            "SELECT broker, COUNT(*) AS jobs FROM jobs WHERE deleted_at IS NULL AND broker IS NOT NULL AND broker <> '' AND LOWER(broker) NOT IN (SELECT LOWER(name) FROM companies) GROUP BY broker ORDER BY broker ASC LIMIT " . self::MAX_ROWS
        );
        return array_map(static fn (array $r): array => ['broker' => (string) $r['broker'], 'jobs' => (int) $r['jobs']], $rows);
    }

    /** The details to keep on an invoice for this broker, as JSON, or null when the company has no record. */
    public static function snapshot(string $name): ?string
    {
        $c = self::findByName($name);
        if ($c === null) {
            return null;
        }
        $info = array_filter([
            'name' => (string) $c['name'], 'address' => (string) ($c['address'] ?? ''), 'city' => (string) ($c['city'] ?? ''), 'phone' => (string) ($c['phone'] ?? ''),
            'dot_number' => (string) ($c['dot_number'] ?? ''), 'mc_number' => (string) ($c['mc_number'] ?? ''),
        ], static fn (string $v): bool => $v !== '');
        return json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
    }

    /** @return array<string,string> the details saved on an invoice (empty when none) */
    public static function infoOf(array $invoice): array
    {
        $data = json_decode((string) ($invoice['company_info'] ?? ''), true);
        return is_array($data) ? array_map('strval', array_filter($data, 'is_scalar')) : [];
    }

    /**
     * @param array<string,mixed> $input
     * @return array{values:array<string,string>,errors:array<string,string>}
     */
    public static function validate(array $input, ?int $ignoreId): array
    {
        $errors = [];
        $values = [];
        $name = self::clean($input['name'] ?? '');
        if ($name === '') {
            $errors['name'] = 'Enter the company name.';
        } elseif (mb_strlen($name) > 100) {
            $errors['name'] = 'The name can be at most 100 characters.';
        } elseif (preg_match('/^[^<>]+$/u', $name) !== 1) {
            $errors['name'] = 'The name has characters that are not allowed.';
        } else {
            $other = self::findByName($name);
            if ($other !== null && (int) $other['id'] !== (int) $ignoreId) {
                $errors['name'] = 'There is already a company with this name.';
            }
        }
        $values['name'] = $name;
        foreach (self::FIELDS as $key => [$label, $max, $pattern]) {
            $text = self::clean($input[$key] ?? '');
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
     * Add or change a company. When the name changes and $renameJobs is true, the broker on its jobs is changed to the new name.
     *
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
                'INSERT INTO companies (name, address, city, phone, dot_number, mc_number, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$values['name'], $orNull($values['address']), $orNull($values['city']), $orNull($values['phone']), $orNull($values['dot_number']), $orNull($values['mc_number']), Auth::id(), $now, $now]
            );
            AuditService::record('company_created', 'company', $newId, null, $values);
            return ['ok' => true, 'errors' => [], 'id' => $newId];
        }

        $jobs = 0;
        Database::transaction(static function () use ($id, $values, $before, $renameJobs, $now, $orNull, &$jobs): void {
            Database::execute(
                'UPDATE companies SET name = ?, address = ?, city = ?, phone = ?, dot_number = ?, mc_number = ?, updated_at = ? WHERE id = ?',
                [$values['name'], $orNull($values['address']), $orNull($values['city']), $orNull($values['phone']), $orNull($values['dot_number']), $orNull($values['mc_number']), $now, $id]
            );
            if ($renameJobs && $values['name'] !== (string) $before['name']) {
                $jobs = Database::execute('UPDATE jobs SET broker = ? WHERE broker IS NOT NULL AND LOWER(broker) = ?', [$values['name'], mb_strtolower((string) $before['name'])]);
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
                AuditService::record('company_updated', 'company', $id, $old, $new + ($jobs > 0 ? ['jobs_renamed' => (string) $jobs] : []));
            }
        });
        return ['ok' => true, 'errors' => [], 'id' => $id, 'jobs' => $jobs];
    }

    /** Remove the details. Jobs and invoices keep the broker name; they just have no details any more. */
    public static function delete(int $id): bool
    {
        $company = self::find($id);
        if ($company === null) {
            return false;
        }
        Database::transaction(static function () use ($id, $company): void {
            Database::execute('DELETE FROM companies WHERE id = ?', [$id]);
            AuditService::record('company_deleted', 'company', $id, ['name' => (string) $company['name']], null);
        });
        return true;
    }
}
