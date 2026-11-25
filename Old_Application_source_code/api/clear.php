<?php
// Deletes all data from the database
require_once '../config/db.php';
require_once '../includes/auth-api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['error' => 'POST only'], 405);
}

$db = getDB();
$db->beginTransaction();

try {
    $db->exec("DELETE FROM company_invoice_items");
    $db->exec("DELETE FROM company_invoices");
    $db->exec("DELETE FROM driver_invoice_items");
    $db->exec("DELETE FROM driver_invoices");
    $db->exec("DELETE FROM drivers");
    $db->exec("DELETE FROM companies");

    $db->commit();
    jsonOut(['ok' => true]);

} catch (Throwable $e) {
    $db->rollBack();
    jsonOut(['error' => $e->getMessage()], 500);
}
