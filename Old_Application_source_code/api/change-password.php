<?php
require_once '../config/db.php';
require_once '../includes/auth-api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonOut(['error' => 'POST only'], 405);

$d = jsonIn();
$current = $d['currentPassword'] ?? '';
$new     = $d['newPassword']     ?? '';
$confirm = $d['confirmPassword'] ?? '';

if ($current === '' || $new === '' || $confirm === '') {
    jsonOut(['error' => 'All fields are required.'], 400);
}
if (strlen($new) < 8) {
    jsonOut(['error' => 'New password must be at least 8 characters.'], 400);
}
if ($new !== $confirm) {
    jsonOut(['error' => 'New passwords do not match.'], 400);
}

$db   = getDB();
$stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
$stmt->execute([$_SESSION['admin_id']]);
$user = $stmt->fetch();

if (!$user || !password_verify($current, $user['password_hash'])) {
    jsonOut(['error' => 'Current password is incorrect.'], 403);
}

$hash = password_hash($new, PASSWORD_BCRYPT);
$db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $_SESSION['admin_id']]);

jsonOut(['ok' => true]);
