<?php
// Required variables before including this file:
//   $pageTitle  (string) - page title shown in <title>
//   $activePage (string) - key matching one of the nav items below

$navItems = [
    'dashboard'   => ['href' => 'dashboard.php',   'icon' => '📊', 'label' => 'Dashboard'],
    'companies'   => ['href' => 'companies.php',   'icon' => '🏢', 'label' => 'Companies'],
    'drivers'     => ['href' => 'drivers.php',     'icon' => '🚗', 'label' => 'Drivers'],
    'inv-driver'  => ['href' => 'inv-driver.php',  'icon' => '🚗', 'label' => 'Invoice / Driver'],
    'inv-company' => ['href' => 'inv-company.php', 'icon' => '🏢', 'label' => 'Invoice / Company'],
];
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - Invoice Management System</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="app-wrapper">

    <!-- Top Menu (Driver Tracking & Job Tracking) -->
    <div class="top-menu">
        <a class="top-menu-item" href="https://app.bhrelocationinc.com/driver-tracking.php">🗺️ Driver Tracking</a>
        <a class="top-menu-item" href="https://app.americanprovanline.com/">📍 Job Tracking</a>
    </div>

    <div class="header">
        <div class="header-brand">
            <img src="assets/mv.png" alt="Logo" class="header-logo">
        </div>
        <div class="header-actions">
            <a class="btn btn-success" href="api/export.php">📥 Export CSV</a>
            <button class="btn btn-secondary" onclick="document.getElementById('changePwModal').classList.add('active')">🔑 Change Password</button>
            <a class="btn btn-danger" href="logout.php">🚪 Logout</a>
        </div>
    </div>

<!-- Change Password Modal -->
<div id="changePwModal" class="modal">
    <div class="modal-box modal-sm">
        <div class="modal-hdr">
            <h2>Change Password</h2>
            <button class="close-btn" onclick="document.getElementById('changePwModal').classList.remove('active')">&times;</button>
        </div>
        <form id="changePwForm" onsubmit="submitChangePassword(event)">
            <div class="form-group" style="margin-bottom:16px;">
                <label>Current Password</label>
                <input type="password" id="cpCurrent" required placeholder="Your current password">
            </div>
            <div class="form-group" style="margin-bottom:16px;">
                <label>New Password <span style="color:var(--text3);font-weight:400;">(min. 8 characters)</span></label>
                <input type="password" id="cpNew" required placeholder="New password">
            </div>
            <div class="form-group" style="margin-bottom:4px;">
                <label>Confirm New Password</label>
                <input type="password" id="cpConfirm" required placeholder="Repeat new password">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('changePwModal').classList.remove('active')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="cpSubmitBtn">Update Password</button>
            </div>
        </form>
    </div>
</div>

    <div class="nav-tabs">
        <?php foreach ($navItems as $key => $item): ?>
        <a class="nav-tab<?php echo ($activePage === $key) ? ' active' : ''; ?>" href="<?php echo $item['href']; ?>">
            <?php echo $item['icon']; ?> <?php echo $item['label']; ?>
        </a>
        <?php endforeach; ?>
    </div>
