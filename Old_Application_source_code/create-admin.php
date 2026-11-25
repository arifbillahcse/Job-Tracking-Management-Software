<?php
// ─────────────────────────────────────────────────────────────────────────────
// ONE-TIME ADMIN SETUP SCRIPT
// Visit this page in your browser to create the first admin account.
// DELETE THIS FILE immediately after creating the admin user!
// ─────────────────────────────────────────────────────────────────────────────
require_once 'config/db.php';

$db = getDB();

// Block access if any admin already exists
$count = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
if ($count > 0) {
    die('<div style="font-family:sans-serif;padding:40px;color:#dc2626;font-size:18px;">
        Admin user already exists.<br><br>
        <strong>Delete this file (create-admin.php) from your server immediately.</strong>
    </div>');
}

$success = false;
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username  = trim($_POST['username']  ?? '');
    $password  = $_POST['password']  ?? '';
    $password2 = $_POST['password2'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Username and password are required.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $password2) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $db->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)")
           ->execute([$username, $hash]);
        $success = true;
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Admin</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { display:flex; align-items:center; justify-content:center; min-height:100vh; padding:20px; }
        .card { background:var(--bg2); border:1px solid var(--border); border-radius:14px; padding:40px; width:100%; max-width:440px; box-shadow:0 25px 60px rgba(0,0,0,.5); }
        h2 { margin-bottom:6px; }
        .sub { color:var(--text3); font-size:13px; margin-bottom:28px; }
        .err  { background:rgba(248,113,113,.15); border:1px solid var(--red);   color:var(--red);   padding:11px 14px; border-radius:7px; font-size:13px; margin-bottom:18px; }
        .warn { background:rgba(245,158,11,.12);  border:1px solid var(--amber); color:var(--amber); padding:14px 16px; border-radius:7px; font-size:13px; margin-top:22px; line-height:1.7; }
        .ok   { background:rgba(74,222,128,.12);  border:1px solid var(--green); color:var(--green); padding:14px 16px; border-radius:7px; font-size:14px; margin-bottom:18px; }
        .fg   { margin-bottom:18px; }
        .btn-full { width:100%; padding:13px; font-size:15px; margin-top:4px; border:none; border-radius:7px; cursor:pointer; }
    </style>
</head>
<body>
<div class="card">
    <h2>Create Admin Account</h2>
    <p class="sub">This page is only available when no admin exists.</p>

    <?php if ($error): ?>
    <div class="err"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="ok">Admin account created successfully! You can now <a href="login.php" style="color:inherit;font-weight:bold;">sign in</a>.</div>
    <div class="warn">
        <strong>Important:</strong> Delete <code>create-admin.php</code> from your server now.<br>
        Leaving it accessible is a security risk.
    </div>
    <?php else: ?>
    <form method="POST">
        <div class="form-group fg">
            <label>Username</label>
            <input type="text" name="username" required autofocus
                   value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                   placeholder="e.g. admin">
        </div>
        <div class="form-group fg">
            <label>Password <span style="color:var(--text3);font-weight:400;">(min. 8 characters)</span></label>
            <input type="password" name="password" required placeholder="Choose a strong password">
        </div>
        <div class="form-group fg">
            <label>Confirm Password</label>
            <input type="password" name="password2" required placeholder="Repeat password">
        </div>
        <button type="submit" class="btn btn-primary btn-full">Create Admin</button>
    </form>
    <?php endif; ?>
</div>
</body>
</html>
