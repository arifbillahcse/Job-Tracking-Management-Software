<?php
// Included at the top of every protected API file (api/*.php).
// Returns a 401 JSON response for unauthenticated requests.
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
