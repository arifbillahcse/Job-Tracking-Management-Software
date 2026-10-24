<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class LoginAttemptRepository
{
    public function record(string $username, string $ip, bool $successful): void
    {
        Database::execute(
            'INSERT INTO login_attempts (username, ip, successful, created_at) VALUES (?, ?, ?, ?)',
            [substr(strtolower($username), 0, 190), substr($ip, 0, 45), $successful ? 1 : 0, gmdate('Y-m-d H:i:s')]
        );
    }

    public function failuresByUsername(string $username, string $since): int
    {
        return (int) Database::fetchValue(
            'SELECT COUNT(*) FROM login_attempts WHERE username = ? AND successful = 0 AND created_at >= ?',
            [substr(strtolower($username), 0, 190), $since]
        );
    }

    public function failuresByIp(string $ip, string $since): int
    {
        return (int) Database::fetchValue(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND successful = 0 AND created_at >= ?',
            [substr($ip, 0, 45), $since]
        );
    }

    public function purgeOlderThan(string $datetime): int
    {
        return Database::execute('DELETE FROM login_attempts WHERE created_at < ?', [$datetime]);
    }
}
