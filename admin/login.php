<?php
session_start();
require_once '../config/db.php';

const ADMIN_USERNAME = 'Kostlim';
const ADMIN_EMAIL = 'jeffkostlim@gmail.com';
const ADMIN_PASSWORD = '60667543';
const ADMIN_TELEGRAM_ID = '1710365896';

$error = '';

if (isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === ADMIN_USERNAME && $email === ADMIN_EMAIL && $password === ADMIN_PASSWORD) {
        $_SESSION['admin_logged'] = true;
        $_SESSION['admin_user'] = ADMIN_USERNAME;
        $_SESSION['admin_email'] = ADMIN_EMAIL;
        $_SESSION['admin_telegram_id'] = ADMIN_TELEGRAM_ID;
        header('Location: index.php');
        exit;
    }

    $error = 'Неверный логин, email или пароль.';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход в Control Panel</title>
    <link rel="icon" type="image/png" href="/assets/notify/fav.png" sizes="16x16">
    <link rel="stylesheet" href="assets/admin-theme.css">
    <style>
        .login-shell { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .login-card { width: 100%; max-width: 380px; }
        .login-brand { display: flex; align-items: center; gap: 10px; margin-bottom: 22px; }
        .login-brand .at-card-icon { width: 40px; height: 40px; }
        .login-brand .at-card-icon svg { width: 20px; height: 20px; }
        .login-brand h2 { font-size: 18px; font-weight: 800; margin: 0; }
        .login-brand span { display: block; font-size: 12px; color: var(--text-muted); margin-top: 2px; }
    </style>
</head>
<body class="at-root">
<div class="login-shell">
    <div class="login-card at-card">
        <div class="login-brand">
            <div class="at-card-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </div>
            <div>
                <h2>Control Panel</h2>
                <span>Kostlim Design · вход администратора</span>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="at-msg at-msg-err"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <div class="at-field">
                <label class="at-label">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    Логин администратора
                </label>
                <input type="text" name="username" class="at-input" value="Kostlim" placeholder="Kostlim" required>
            </div>

            <div class="at-field">
                <label class="at-label">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>
                    Email адрес
                </label>
                <input type="email" name="email" class="at-input" value="jeffkostlim@gmail.com" placeholder="jeffkostlim@gmail.com" required>
            </div>

            <div class="at-field">
                <label class="at-label">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    Пароль доступа
                </label>
                <input type="password" name="password" class="at-input" placeholder="••••••••" required>
            </div>

            <button type="submit" class="at-btn at-btn-primary at-btn-block">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/></svg>
                Авторизоваться
            </button>
        </form>
    </div>
</div>
</body>
</html>