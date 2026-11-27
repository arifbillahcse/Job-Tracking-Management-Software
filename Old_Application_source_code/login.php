<?php
session_start();

// Already logged in → go straight to dashboard
if (!empty($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit;
}

require_once 'config/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        $stmt = getDB()->prepare("SELECT id, password_hash FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id']   = $user['id'];
            $_SESSION['admin_user'] = $username;
            header('Location: dashboard.php');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Invoice Management System</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; }
        .login-card {
            background: var(--bg2); border: 1px solid var(--border);
            border-radius: 14px; padding: 44px 40px; width: 100%; max-width: 420px;
            box-shadow: 0 25px 60px rgba(0,0,0,.5);
        }
        .login-logo { text-align: center; margin-bottom: 28px; }
        .login-logo .icon { font-size: 48px; display: block; margin-bottom: 10px; }
        .login-logo h1 { font-size: 20px; color: var(--text); }
        .login-logo p  { font-size: 13px; color: var(--text3); margin-top: 4px; }
        .login-error {
            background: rgba(248,113,113,.15); border: 1px solid var(--red);
            color: var(--red); padding: 11px 14px; border-radius: 7px;
            font-size: 13px; margin-bottom: 20px;
        }
        .login-btn { width: 100%; padding: 13px; font-size: 15px; margin-top: 8px; border: none; border-radius: 7px; cursor: pointer; }
    </style>
</head>
<body>
<div class="login-card">
    <div class="login-logo">
        <span class="icon">📋</span>
        <h1>Invoice Management System</h1>
        <p>Sign in to continue</p>
    </div>

    <?php if ($error): ?>
    <div class="login-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="POST" action="login.php">
        <div class="form-group" style="margin-bottom:18px;">
            <label>Username</label>
            <input type="text" name="username" required autofocus
                   value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                   placeholder="Enter username">
        </div>
        <div class="form-group" style="margin-bottom:24px;">
            <label>Password</label>
            <input type="password" name="password" required placeholder="Enter password">
        </div>
        <button type="submit" class="btn btn-primary login-btn">Sign In</button>
    </form>
</div>
</body>
</html>
