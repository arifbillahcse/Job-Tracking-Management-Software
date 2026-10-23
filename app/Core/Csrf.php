<?php

declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    private const KEY = '_csrf';

    public static function token(): string
    {
        $token = Session::get(self::KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::set(self::KEY, $token);
        }
        return $token;
    }

    public static function validate(?string $submitted): bool
    {
        $token = Session::get(self::KEY);
        return is_string($token) && $token !== '' && is_string($submitted) && hash_equals($token, $submitted);
    }

    public static function rotate(): void
    {
        Session::forget(self::KEY);
    }
}
