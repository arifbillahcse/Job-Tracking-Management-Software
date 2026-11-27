<?php
// Included at the top of every protected PAGE (root-level .php files).
// Redirects unauthenticated visitors to the login page.
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}
