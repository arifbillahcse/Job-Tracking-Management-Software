<?php

declare(strict_types=1);

/** Tiny assertion helpers for tests/run.php. */

$GLOBALS['__tests'] = ['pass' => 0, 'fail' => 0, 'failures' => [], 'section' => ''];

function section(string $name): void
{
    $GLOBALS['__tests']['section'] = $name;
    $out = fopen('php://stderr', 'w');
    fwrite($out, "\n== {$name}\n");
}

function check(bool $condition, string $label): void
{
    $t = &$GLOBALS['__tests'];
    $out = fopen('php://stderr', 'w');
    if ($condition) {
        $t['pass']++;
        fwrite($out, "  ok   {$label}\n");
    } else {
        $t['fail']++;
        $t['failures'][] = $t['section'] . ': ' . $label;
        fwrite($out, "  FAIL {$label}\n");
    }
}

function throws(callable $fn, string $label): void
{
    try {
        $fn();
        check(false, $label . ' (nothing thrown)');
    } catch (Throwable) {
        check(true, $label);
    }
}

function finish(): never
{
    $t = $GLOBALS['__tests'];
    $out = fopen('php://stderr', 'w');
    fwrite($out, "\n{$t['pass']} passed, {$t['fail']} failed\n");
    foreach ($t['failures'] as $failure) {
        fwrite($out, "  - {$failure}\n");
    }
    exit($t['fail'] === 0 ? 0 : 1);
}
