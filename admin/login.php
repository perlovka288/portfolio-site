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
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: #0F1117;
            color: #fff;
            font-family: 'Inter', 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        .login-card {
            width: 100%;
            max-width: 380px;
            background: #171A22;
            border: 1px solid rgba(255,255,255,.06);
            border-radius: 16px;
            padding: 26px;
            box-shadow: 0 4px 24px rgba(0,0,0,.28);
        }
        .login-brand { display: flex; align-items: center; gap: 12px; margin-bottom: 22px; }
        .login-brand .icon {
            width: 40px; height: 40px; flex-shrink: 0;
            border-radius: 10px;
            background: rgba(255,138,42,.12);
            color: #FF8A2A;
            display: flex; align-items: center; justify-content: center;
        }
        .login-brand .icon svg { width: 20px; height: 20px; display: block; }
        .login-brand h2 { font-size: 18px; font-weight: 800; margin: 0; }
        .login-brand span { display: block; font-size: 12px; color: #9AA4B2; margin-top: 2px; }

        .field { margin-bottom: 16px; }
        .field:last-of-type { margin-bottom: 0; }
        label {
            display: flex; align-items: center; gap: 6px;
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .06em; color: #9AA4B2; margin-bottom: 7px;
        }
        label svg { width: 13px; height: 13px; flex-shrink: 0; opacity: .8; display: block; }
        input {
            width: 100%; height: 48px; padding: 0 14px;
            background: #12141C; border: 1px solid rgba(255,255,255,.08);
            border-radius: 12px; color: #fff; font-size: 14px;
            font-family: inherit; outline: none;
            transition: border-color .15s ease;
        }
        input::placeholder { color: #566072; }
        input:focus { border-color: #FF8A2A; }

        .btn {
            width: 100%; height: 48px; margin-top: 22px;
            border: none; border-radius: 12px;
            background: #FF8A2A; color: #17110A;
            font-size: 14px; font-weight: 700; cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            transition: background .15s ease, transform .12s ease;
            font-family: inherit;
        }
        .btn svg { width: 17px; height: 17px; display: block; }
        .btn:hover { background: #FF9C48; }
        .btn:active { transform: scale(.98); }

        .msg-err {
            background: rgba(255,77,94,.1);
            border: 1px solid rgba(255,77,94,.3);
            color: #FF4D5E;
            padding: 12px 14px;
            border-radius: 12px;
            font-size: 13px;
            margin-bottom: 18px;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-brand">
            <div class="icon">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </div>
            <div>
                <h2>Control Panel</h2>
                <span>Kostlim Design · вход администратора</span>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="msg-err"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <div class="field">
                <label>
                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    Логин администратора
                </label>
                <input type="text" name="username" value="Kostlim" placeholder="Kostlim" required>
            </div>

            <div class="field">
                <label>
                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>
                    Email адрес
                </label>
                <input type="email" name="email" value="jeffkostlim@gmail.com" placeholder="jeffkostlim@gmail.com" required>
            </div>

            <div class="field">
                <label>
                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    Пароль доступа
                </label>
                <input type="password" name="password" placeholder="••••••••" required>
            </div>

            <button type="submit" class="btn">
                <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/></svg>
                Авторизоваться
            </button>
        </form>
    </div>
</body>
</html>