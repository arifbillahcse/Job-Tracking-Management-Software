<?php
// ─────────────────────────────────────────────
// Database Configuration
// Edit these values to match your MySQL setup
// ─────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'bhrelocation_app');
define('DB_PASS', 'Qi8KqJVQ2AbyVU');
define('DB_NAME', 'bhrelocation_app');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
        exit;
    }

    return $pdo;
}

// Send JSON response and exit
function jsonOut($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Read and decode JSON request body
function jsonIn(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}
