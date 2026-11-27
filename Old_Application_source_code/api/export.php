<?php
// Export all data as a ZIP file containing 4 CSV files.
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['admin_id'])) {
    header('Location: ../login.php');
    exit;
}
require_once '../config/db.php';

// Format date from YYYY-MM-DD to MM-DD-YYYY
function formatDate($dateStr) {
    if (!$dateStr) return '';
    $parts = explode('-', $dateStr);
    if (count($parts) === 3) {
        return "{$parts[1]}-{$parts[2]}-{$parts[0]}";
    }
    return $dateStr;
}

$db = getDB();

// ── Build each CSV in memory ──────────────────

$files = [];

// ── 1. companies.csv ─────────────────────────
$rows = $db->query("SELECT * FROM companies ORDER BY name")->fetchAll();
$csv  = csvLine(['ID','Name','Address','City','Phone','DOT #','MC #']);
foreach ($rows as $r) {
    $csv .= csvLine([$r['id'], $r['name'], $r['address'], $r['city'], $r['phone'], $r['dot_number'], $r['mc_number']]);
}
$files['companies.csv'] = $csv;

// ── 2. drivers.csv ───────────────────────────
$rows = $db->query("SELECT * FROM drivers ORDER BY last_name, first_name")->fetchAll();
$csv  = csvLine(['ID','First Name','Last Name','Phone','License #']);
foreach ($rows as $r) {
    $csv .= csvLine([$r['id'], $r['first_name'], $r['last_name'], $r['phone'], $r['license']]);
}
$files['drivers.csv'] = $csv;

// ── 3. company-invoices.csv ──────────────────
$invoices = $db->query(
    "SELECT ci.*, c.name AS company_name
     FROM company_invoices ci
     LEFT JOIN companies c ON c.id = ci.company_id
     ORDER BY ci.date DESC, ci.id DESC"
)->fetchAll();

$csv = csvLine([
    'Invoice #','Company','Date',
    'Job #','Driver','Customer','From','To','CF','Rate','Job Total','Bal Due','New Bal','Remarks',
    'Subtotal','Carrier Fee (10%)','Invoice Total'
]);
foreach ($invoices as $inv) {
    $items = $db->prepare(
        "SELECT ii.*, CONCAT(d.first_name,' ',d.last_name) AS driver_name
         FROM company_invoice_items ii
         LEFT JOIN drivers d ON d.id = ii.driver_id
         WHERE ii.invoice_id = ?
         ORDER BY ii.sort_order, ii.id"
    );
    $items->execute([$inv['id']]);
    $rows = $items->fetchAll();

    if (empty($rows)) {
        // Invoice with no line items — still output one row
        $csv .= csvLine([
            'CI-'.$inv['id'], $inv['company_name'], formatDate($inv['date']),
            '','','','','','','','','','','',
            number_format($inv['subtotal'],2),
            number_format($inv['carrier_fee'],2),
            number_format($inv['total'],2),
        ]);
    } else {
        foreach ($rows as $i => $r) {
            $jobTotal = ($r['cubic_feet'] * $r['rate']);
            $csv .= csvLine([
                'CI-'.$inv['id'],
                $i === 0 ? $inv['company_name'] : '',   // only first row shows invoice-level fields
                $i === 0 ? formatDate($inv['date']) : '',
                $r['job_number'],
                $r['driver_name'] ?? '',
                $r['customer_name'],
                $r['from_location'],
                $r['to_location'],
                $r['cubic_feet'],
                number_format($r['rate'],4),
                number_format($jobTotal,2),
                number_format($r['balance_due'],2),
                number_format($r['new_balance'],2),
                $r['remarks'],
                $i === 0 ? number_format($inv['subtotal'],2)    : '',
                $i === 0 ? number_format($inv['carrier_fee'],2) : '',
                $i === 0 ? number_format($inv['total'],2)       : '',
            ]);
        }
    }
}
$files['company-invoices.csv'] = $csv;

// ── 4. driver-invoices.csv ───────────────────
$invoices = $db->query(
    "SELECT di.*, CONCAT(d.first_name,' ',d.last_name) AS driver_name
     FROM driver_invoices di
     LEFT JOIN drivers d ON d.id = di.driver_id
     ORDER BY di.date DESC, di.id DESC"
)->fetchAll();

$csv = csvLine([
    'Invoice #','Driver','Date',
    'Job #','Company','Customer','From','To','CF','Rate','Job Total','Bal Due','New Bal','Remarks',
    'Subtotal','Carrier Fee (10%)','Invoice Total'
]);
foreach ($invoices as $inv) {
    $items = $db->prepare(
        "SELECT ii.*, c.name AS company_name
         FROM driver_invoice_items ii
         LEFT JOIN companies c ON c.id = ii.company_id
         WHERE ii.invoice_id = ?
         ORDER BY ii.sort_order, ii.id"
    );
    $items->execute([$inv['id']]);
    $rows = $items->fetchAll();

    if (empty($rows)) {
        $csv .= csvLine([
            'DI-'.$inv['id'], $inv['driver_name'], formatDate($inv['date']),
            '','','','','','','','','','','',
            number_format($inv['subtotal'],2),
            number_format($inv['carrier_fee'],2),
            number_format($inv['total'],2),
        ]);
    } else {
        foreach ($rows as $i => $r) {
            $jobTotal = ($r['cubic_feet'] * $r['rate']);
            $csv .= csvLine([
                'DI-'.$inv['id'],
                $i === 0 ? $inv['driver_name'] : '',
                $i === 0 ? formatDate($inv['date']) : '',
                $r['job_number'],
                $r['company_name'] ?? '',
                $r['customer_name'],
                $r['from_location'],
                $r['to_location'],
                $r['cubic_feet'],
                number_format($r['rate'],4),
                number_format($jobTotal,2),
                number_format($r['balance_due'],2),
                number_format($r['new_balance'],2),
                $r['remarks'],
                $i === 0 ? number_format($inv['subtotal'],2)    : '',
                $i === 0 ? number_format($inv['carrier_fee'],2) : '',
                $i === 0 ? number_format($inv['total'],2)       : '',
            ]);
        }
    }
}
$files['driver-invoices.csv'] = $csv;

// ── Package into a ZIP and send ───────────────

$zipFile = tempnam(sys_get_temp_dir(), 'invoice_export_');
$zip     = new ZipArchive();
$zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

foreach ($files as $name => $content) {
    $zip->addFromString($name, $content);
}
$zip->close();

$date = date('Y-m-d');
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="invoice-export-' . $date . '.zip"');
header('Content-Length: ' . filesize($zipFile));
header('Cache-Control: no-cache');

readfile($zipFile);
unlink($zipFile);
exit;

// ── Helper ────────────────────────────────────
function csvLine(array $fields): string {
    $escaped = array_map(function ($v) {
        $v = (string)$v;
        if (strpbrk($v, '",\r\n') !== false) {
            $v = '"' . str_replace('"', '""', $v) . '"';
        }
        return $v;
    }, $fields);
    return implode(',', $escaped) . "\r\n";
}
