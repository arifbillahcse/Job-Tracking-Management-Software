<?php
require_once '../config/db.php';
require_once '../includes/auth-api.php';

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

// ── GET: list all drivers ─────────────────────
if ($method === 'GET') {
    $rows = $db->query("SELECT * FROM drivers ORDER BY last_name, first_name")->fetchAll();
    jsonOut(array_map('mapDriver', $rows));
}

// ── POST: create driver ───────────────────────
if ($method === 'POST') {
    $d = jsonIn();
    $stmt = $db->prepare(
        "INSERT INTO drivers (first_name, last_name, phone, license) VALUES (?, ?, ?, ?)"
    );
    $stmt->execute([
        $d['firstName'] ?? '',
        $d['lastName']  ?? '',
        $d['phone']     ?? '',
        $d['license']   ?? '',
    ]);
    jsonOut(['id' => (int)$db->lastInsertId()]);
}

// ── PUT: update driver ────────────────────────
if ($method === 'PUT') {
    $id = (int)($_GET['id'] ?? 0);
    $d  = jsonIn();
    $stmt = $db->prepare(
        "UPDATE drivers SET first_name=?, last_name=?, phone=?, license=? WHERE id=?"
    );
    $stmt->execute([
        $d['firstName'] ?? '',
        $d['lastName']  ?? '',
        $d['phone']     ?? '',
        $d['license']   ?? '',
        $id,
    ]);
    jsonOut(['ok' => true]);
}

// ── DELETE: remove driver ─────────────────────
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    $db->prepare("DELETE FROM drivers WHERE id=?")->execute([$id]);
    jsonOut(['ok' => true]);
}

// ── Helper ────────────────────────────────────
function mapDriver(array $row): array {
    return [
        'id'        => (int)$row['id'],
        'firstName' => $row['first_name'] ?? '',
        'lastName'  => $row['last_name']  ?? '',
        'phone'     => $row['phone']      ?? '',
        'license'   => $row['license']    ?? '',
    ];
}
