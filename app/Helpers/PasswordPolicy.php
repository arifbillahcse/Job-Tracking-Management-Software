<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Core\Config;

/**
 * Length-first password policy (no composition rules) plus a common-password check.
 */
final class PasswordPolicy
{
    private const COMMON = [
        'password', 'password1', 'password12', 'password123', 'password1234', 'passw0rd123',
        '1234567890', '12345678910', '123456789012', 'qwertyuiop', 'qwerty12345', 'qwerty123456',
        'iloveyou123', 'welcome123', 'welcome1234', 'admin12345', 'administrator', 'letmein1234',
        'changeme123', 'changemenow', 'abc1234567', 'abcdefghij', 'abcd123456', 'trustno1234',
        'monkey12345', 'dragon12345', 'football123', 'baseball123', 'superman123', 'internet123',
        'jobtracker', 'jobtracker1', 'jobtracker123', 'softorio123', 'hostorio123',
    ];

    /** Returns an error message, or null when the password is acceptable. */
    public static function check(string $password): ?string
    {
        $min = (int) Config::get('app.password.min_length', 10);

        if (mb_strlen($password) < $min) {
            return "Password must be at least {$min} characters long.";
        }
        if (mb_strlen($password) > 128) {
            return 'Password may not be longer than 128 characters.';
        }
        if (in_array(strtolower($password), self::COMMON, true)) {
            return 'That password is too common. Please choose a less predictable one.';
        }
        if (count(array_unique(mb_str_split($password))) < 4) {
            return 'Password is too repetitive. Please use a wider mix of characters.';
        }
        return null;
    }

    public static function hash(string $password): string
    {
        $options = Config::get('app.password.options', []);
        return password_hash($password, self::algorithm(), is_array($options) ? $options : []);
    }

    public static function needsRehash(string $hash): bool
    {
        $options = Config::get('app.password.options', []);
        return password_needs_rehash($hash, self::algorithm(), is_array($options) ? $options : []);
    }

    /** Argon2id when this PHP build has it, bcrypt otherwise. */
    public static function algorithm(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    /** Random password for admin-created accounts. Avoids look-alike characters. */
    public static function generate(int $length = 20): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }
}
