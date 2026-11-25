<?php
require_once '../config/db.php';
require_once '../includes/auth-api.php';

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

// ── GET: list all companies ───────────────────
if ($method === 'GET') {
    $rows = $db->query("SELECT * FROM companies ORDER BY name")->fetchAll();
    jsonOut(array_map('mapCompany', $rows));
}

// ── POST: create company ──────────────────────
if ($method === 'POST') {
    $d = jsonIn();
    $stmt = $db->prepare(
        "INSERT INTO companies (name, address, city, phone, dot_number, mc_number)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $d['name']      ?? '',
        $d['address']   ?? '',
        $d['city']      ?? '',
        $d['phone']     ?? '',
        $d['dotNumber'] ?? '',
        $d['mcNumber']  ?? '',
    ]);
    jsonOut(['id' => (int)$db->lastInsertId()]);
}

// ── PUT: update company ───────────────────────
if ($method === 'PUT') {
    $id = (int)($_GET['id'] ?? 0);
    $d  = jsonIn();
    $stmt = $db->prepare(
        "UPDATE companies SET name=?, address=?, city=?, phone=?, dot_number=?, mc_number=? WHERE id=?"
    );
    $stmt->execute([
        $d['name']      ?? '',
        $d['address']   ?? '',
        $d['city']      ?? '',
        $d['phone']     ?? '',
        $d['dotNumber'] ?? '',
        $d['mcNumber']  ?? '',
        $id,
    ]);
    jsonOut(['ok' => true]);
}

// ── DELETE: remove company ────────────────────
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    $db->prepare("DELETE FROM companies WHERE id=?")->execute([$id]);
    jsonOut(['ok' => true]);
}

// ── Helper ────────────────────────────────────
function mapCompany(array $row): array {
    return [
        'id'        => (int)$row['id'],
        'name'      => $row['name'],
        'address'   => $row['address']    ?? '',
        'city'      => $row['city']       ?? '',
        'phone'     => $row['phone']      ?? '',
        'dotNumber' => $row['dot_number'] ?? '',
        'mcNumber'  => $row['mc_number']  ?? '',
    ];
}
