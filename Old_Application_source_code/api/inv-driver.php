<?php
require_once '../config/db.php';
require_once '../includes/auth-api.php';

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

// Auto-migrate: add missing columns if they don't exist yet
try {
    $db->exec("ALTER TABLE driver_invoices ADD COLUMN IF NOT EXISTS paid       DECIMAL(12,2) DEFAULT 0");
    $db->exec("ALTER TABLE driver_invoices ADD COLUMN IF NOT EXISTS paid_date  DATE          DEFAULT NULL");
    $db->exec("ALTER TABLE driver_invoices ADD COLUMN IF NOT EXISTS labor_cost      DECIMAL(12,2) DEFAULT 0");
    $db->exec("ALTER TABLE driver_invoices ADD COLUMN IF NOT EXISTS pads            DECIMAL(12,2) DEFAULT 0");
    $db->exec("ALTER TABLE driver_invoices ADD COLUMN IF NOT EXISTS invoice_remarks TEXT          DEFAULT ''");
    $db->exec("ALTER TABLE driver_invoice_items ADD COLUMN IF NOT EXISTS phone VARCHAR(30) DEFAULT ''");
} catch (PDOException $e) { /* already exists */ }

// ── GET: list all driver invoices ─────────────
if ($method === 'GET') {
    $invoices = $db->query(
        "SELECT * FROM driver_invoices ORDER BY date DESC, id DESC"
    )->fetchAll();

    foreach ($invoices as &$inv) {
        $inv['id']         = (int)$inv['id'];
        $inv['driverId']   = (int)$inv['driver_id'];
        $inv['subtotal']   = (float)$inv['subtotal'];
        $inv['carrierFee'] = (float)$inv['carrier_fee'];
        $inv['laborCost']  = (float)($inv['labor_cost'] ?? 0);
        $inv['pads']       = (float)($inv['pads'] ?? 0);
        $inv['paid']           = (float)($inv['paid'] ?? 0);
        $inv['paidDate']       = $inv['paid_date'] ?? '';
        $inv['invoiceRemarks'] = $inv['invoice_remarks'] ?? '';
        $inv['total']          = (float)$inv['total'];

        $items = $db->prepare(
            "SELECT * FROM driver_invoice_items WHERE invoice_id=? ORDER BY sort_order, id"
        );
        $items->execute([$inv['id']]);
        $inv['lineItems'] = array_map('mapDrItem', $items->fetchAll());

        unset($inv['driver_id'], $inv['carrier_fee'], $inv['labor_cost'], $inv['paid_date'], $inv['invoice_remarks'], $inv['created_at']);
    }
    unset($inv);

    jsonOut($invoices);
}

// ── POST: create driver invoice ───────────────
if ($method === 'POST') {
    $d = jsonIn();
    $db->beginTransaction();

    $stmt = $db->prepare(
        "INSERT INTO driver_invoices (driver_id, date, subtotal, carrier_fee, labor_cost, pads, paid, paid_date, invoice_remarks, total)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        (int)$d['driverId'],
        $d['date'],
        (float)($d['subtotal']   ?? 0),
        (float)($d['carrierFee'] ?? 0),
        (float)($d['laborCost']  ?? 0),
        (float)($d['pads']       ?? 0),
        (float)($d['paid']       ?? 0),
        ($d['paidDate'] ?? '') ?: null,
        $d['invoiceRemarks']     ?? '',
        (float)($d['total']      ?? 0),
    ]);
    $id = (int)$db->lastInsertId();

    insertDrItems($db, $id, $d['lineItems'] ?? []);
    $db->commit();

    jsonOut(['id' => $id]);
}

// ── PUT: update driver invoice ────────────────
if ($method === 'PUT') {
    $id = (int)($_GET['id'] ?? 0);
    $d  = jsonIn();
    $db->beginTransaction();

    $db->prepare(
        "UPDATE driver_invoices SET driver_id=?, date=?, subtotal=?, carrier_fee=?, labor_cost=?, pads=?, paid=?, paid_date=?, invoice_remarks=?, total=? WHERE id=?"
    )->execute([
        (int)$d['driverId'],
        $d['date'],
        (float)($d['subtotal']   ?? 0),
        (float)($d['carrierFee'] ?? 0),
        (float)($d['laborCost']  ?? 0),
        (float)($d['pads']       ?? 0),
        (float)($d['paid']       ?? 0),
        ($d['paidDate'] ?? '') ?: null,
        $d['invoiceRemarks']     ?? '',
        (float)($d['total']      ?? 0),
        $id,
    ]);

    // Replace all line items
    $db->prepare("DELETE FROM driver_invoice_items WHERE invoice_id=?")->execute([$id]);
    insertDrItems($db, $id, $d['lineItems'] ?? []);
    $db->commit();

    jsonOut(['ok' => true]);
}

// ── DELETE: remove driver invoice ─────────────
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    // Items deleted automatically via ON DELETE CASCADE
    $db->prepare("DELETE FROM driver_invoices WHERE id=?")->execute([$id]);
    jsonOut(['ok' => true]);
}

// ── Helpers ───────────────────────────────────
function insertDrItems(PDO $db, int $invoiceId, array $items): void {
    $stmt = $db->prepare(
        "INSERT INTO driver_invoice_items
         (invoice_id, sort_order, job_number, company_id, customer_name, phone,
          from_location, to_location, cubic_feet, rate, balance_due, new_balance, remarks)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    foreach ($items as $i => $r) {
        $stmt->execute([
            $invoiceId,
            $i,
            $r['jobNumber']    ?? '',
            ($r['companyId'] ? (int)$r['companyId'] : null),
            $r['customerName'] ?? '',
            $r['phone']        ?? '',
            $r['from']         ?? '',
            $r['to']           ?? '',
            (float)($r['cubicFeet']  ?? 0),
            (float)($r['rate']       ?? 0),
            (float)($r['balanceDue'] ?? 0),
            (float)($r['newBalance'] ?? 0),
            $r['remarks']      ?? '',
        ]);
    }
}

function mapDrItem(array $row): array {
    return [
        'jobNumber'    => $row['job_number']    ?? '',
        'companyId'    => $row['company_id'] ? (int)$row['company_id'] : '',
        'customerName' => $row['customer_name'] ?? '',
        'phone'        => $row['phone']         ?? '',
        'from'         => $row['from_location'] ?? '',
        'to'           => $row['to_location']   ?? '',
        'cubicFeet'    => (float)$row['cubic_feet'],
        'rate'         => (float)$row['rate'],
        'balanceDue'   => (float)$row['balance_due'],
        'newBalance'   => (float)$row['new_balance'],
        'remarks'      => $row['remarks']       ?? '',
    ];
}
