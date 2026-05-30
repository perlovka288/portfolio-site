<?php
require_once '../config/db.php';
session_start();

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
    <link rel="stylesheet" href="../style.css">
</head>
<body>
<div class="container">
    <div class="form-container">
        <h2>Вход для Kostlim</h2>

        <?php if ($error): ?>
            <div class="form-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <div class="form-group">
                <label>Логин администратора</label>
                <input type="text" name="username" class="form-input" value="Kostlim" placeholder="Kostlim" required>
            </div>

            <div class="form-group">
                <label>Email адрес</label>
                <input type="email" name="email" class="form-input" value="jeffkostlim@gmail.com" placeholder="jeffkostlim@gmail.com" required>
            </div>

            <div class="form-group">
                <label>Пароль доступа</label>
                <input type="password" name="password" class="form-input" placeholder="60667543" required>
            </div>

            <button type="submit" class="btn-submit">Авторизоваться</button>
        </form>
    </div>
</div>
</body>
</html>
