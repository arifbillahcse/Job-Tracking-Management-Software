<?php
require_once 'config/db.php';
require_once 'includes/auth.php';   // only logged-in users can run this

$db = getDB();
$results = [];

$stmts = [
    "ALTER TABLE driver_invoices  ADD COLUMN IF NOT EXISTS paid      DECIMAL(12,2) DEFAULT 0",
    "ALTER TABLE driver_invoices  ADD COLUMN IF NOT EXISTS paid_date DATE          DEFAULT NULL",
    "ALTER TABLE company_invoices ADD COLUMN IF NOT EXISTS paid      DECIMAL(12,2) DEFAULT 0",
    "ALTER TABLE company_invoices ADD COLUMN IF NOT EXISTS paid_date DATE          DEFAULT NULL",
];

foreach ($stmts as $sql) {
    try {
        $db->exec($sql);
        $results[] = ['sql' => $sql, 'status' => 'OK'];
    } catch (PDOException $e) {
        $results[] = ['sql' => $sql, 'status' => 'ERROR: ' . $e->getMessage()];
    }
}

header('Content-Type: application/json');
echo json_encode($results, JSON_PRETTY_PRINT);
