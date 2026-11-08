<?php

declare(strict_types=1);

/**
 * Client request (v1.6.4): every job field is a column in the Jobs lists, with a dash when there is nothing to show;
 * negative amounts read -$5.00. Included by tests/run.php.
 */

use App\Core\Clock;
use App\Core\Database;

section('Jobs list: every field is a column, a dash when empty');
fresh_db();
fresh_session();
Clock::fake('2026-11-08');
$mgr = make_user('Manager', 'p19-mgr@example.com');
sign_in($mgr);
check(fmt_money('-799.76') === '-$799.76' && fmt_money('799.76') === '$799.76' && fmt_money('0') === '$0.00' && fmt_money('-0.001') === '$0.00', 'amounts: -$799.76 (not $-799.76), $0.00 for zero');
call('POST', '/jobs', job_input(['job_number' => 'P19-FULL', 'broker' => 'Top Nation', 'job_lot' => 'L-7', 'color' => 'Blue', 'job_location' => 'Dock 4', 'estimated_cf' => '800', 'cf' => '900',
    'estimated_total' => '3000', 'deposit' => '500', 'grand_total' => '2500', 'paid' => '1000', 'balance' => '', 'storage_fee_override' => '']));
call('POST', '/jobs', job_input(['job_number' => 'P19-BARE', 'job_lot' => '', 'color' => '', 'job_location' => '', 'cf' => '', 'balance' => '', 'storage_fee_override' => '']));
call('POST', '/jobs', job_input(['job_number' => 'P19-NEG', 'grand_total' => '10', 'deposit' => '0', 'paid' => '810', 'balance' => '', 'storage_fee_override' => '']));
$html = call('GET', '/jobs')->body();
$heads = [];
preg_match_all('~<th[^>]*scope="col"[^>]*>(.*?)</th>~s', $html, $m);
foreach ($m[1] as $h) {
    $heads[] = trim(preg_replace('/\s+/', ' ', strip_tags($h)));
}
foreach (['Job #', 'Customer', 'Broker / Lot / Color', 'Pickup / 1st available', 'Route / Job location', 'Foreman / trip', 'E-CF / A-CF', 'E-Total / Deposit / Grand total', 'Total to BH / Paid', 'Balance due / Storage fee', 'Status'] as $want) {
    check(in_array($want, $heads, true), "All Jobs has a \"{$want}\" column heading");
}
check(count($heads) === 12, 'the list has 11 columns (related fields are stacked and labelled) plus the actions column: ' . count($heads));
foreach (['broker', 'lot', 'color', 'pickup', 'available', 'from', 'location', 'ecf', 'cf', 'etotal', 'deposit', 'grand', 'tobh', 'paid', 'balance', 'storage'] as $key) {
    check(str_contains($html, 'sort=' . $key . '&') || str_contains($html, 'sort=' . $key . '"') || str_contains($html, 'sort=' . $key . '&amp;'), "...and each stacked field keeps its own sort link ({$key})");
}
$rowOf = static function (string $n) use ($html): string {
    preg_match('~<tr>(?:(?!</tr>).)*?>' . preg_quote($n, '~') . '</a>.*?</tr>~s', $html, $r);
    return plain($r[0] ?? '');
};
$full = $rowOf('P19-FULL');
check(str_contains($full, 'Top Nation') && str_contains($full, 'L-7') && str_contains($full, 'Blue') && str_contains($full, 'Dock 4') && str_contains($full, '$3,000.00') && str_contains($full, '$500.00') && str_contains($full, '$2,500.00') && str_contains($full, '$1,000.00'), 'a filled job shows broker, lot, color, location, E-Total, Deposit, Grand total and Paid');
$bare = $rowOf('P19-BARE');
check(substr_count($bare, '—') >= 9, 'a job with no extra information shows a dash in every empty field (' . substr_count($bare, '—') . ' dashes)');
check(str_contains($rowOf('P19-NEG'), '-$800.00'), 'a job paid more than it owes shows the balance as -$800.00');
foreach (['/jobs/ready', '/jobs/more-than-30', '/jobs/in-transit', '/jobs/delivered'] as $uri) {
    $r = call('GET', $uri);
    check($r->status() === 200, "{$uri} still opens with the wider layout");
}
// sorting by the new columns never errors
foreach (['lot', 'color', 'location', 'ecf', 'etotal', 'deposit', 'grand', 'tobh', 'paid'] as $s) {
    check(call('GET', '/jobs', [], ['sort' => $s, 'dir' => 'desc'])->status() === 200, "sorting by {$s} works");
}
sign_in(make_user('Staff', 'p19-stf@example.com'));
$staff = plain(call('GET', '/jobs')->body());
check(!str_contains($staff, 'E-Total / Deposit / Grand total') && !str_contains($staff, 'Total to BH / Paid') && !str_contains($staff, 'Balance due / Storage fee') && !str_contains($staff, '$2,500.00') && str_contains($staff, 'Broker / Lot / Color'), 'Staff see the lot, color and location columns but no money column');
sign_in($mgr);
check(str_contains(call('GET', '/jobs')->body(), 'id="menu-toggle"'), 'the top bar has a button to hide or show the left menu (desktop)');
