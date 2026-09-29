<?php
/**
 * ЗАГОТОВКА СТРАНИЦЫ в новом интерфейсе (KUI). Копируй под нужный раздел:
 *   cp templates/kui_page_template.php price_new.php   → правь → когда готово, замени price.php
 *
 * Порядок как в index.php: сессия/БД → переменные роли → HTML.
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/pack_role.php';

// TODO: те же переменные, что считает index.php (см. блок вверху файла):
// $isAdmin, $isPackDesigner, $isLinked, $tgProfile — нужны только для меню/аватара.
$isAdmin = $isAdmin ?? false;  $isPackDesigner = $isPackDesigner ?? false;
$isLinked = $isLinked ?? false; $tgProfile = $tgProfile ?? [];

$kuiActive = 'price';          // home | price | orders | profile | support | ppk | useful | admin
$kuiTitle  = 'Название раздела';
?><!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($kuiTitle) ?> — Kostlim Design</title>
    <link rel="icon" type="image/png" href="/assets/img/logo.png">
    <link rel="stylesheet" href="/style.css">
    <link rel="stylesheet" href="/assets/kostlim-upgrade.css">
    <?php include __DIR__ . '/../includes/ui_head.php'; /* ← всегда последним */ ?>
</head>
<body class="kui">
<?php include __DIR__ . '/../includes/ui_page_start.php'; ?>

    <!-- ══ Контент раздела — примеры компонентов KUI ══ -->
    <div class="kui-card accent">
        <span class="kui-badge">PPK</span>
        <p>Карточка с акцентом.</p>
        <a class="kui-btn" href="order.php">Действие ›</a>
        <button class="kui-btn ghost" type="button">Второстепенное</button>
    </div>

    <h2 class="kui-h2">Список</h2>
    <a class="kui-row" href="#"><b>Строка-ссылка</b><small>подпись</small></a>
    <a class="kui-row" href="#"><b>Ещё строка</b><small>›</small></a>

    <h2 class="kui-h2">Форма</h2>
    <input class="kui-field" type="text" placeholder="Поле ввода">

    <h2 class="kui-h2">Сетка (2 колонки на ПК)</h2>
    <div class="kui-grid">
        <div class="kui-card">Блок 1</div>
        <div class="kui-card">Блок 2</div>
    </div>

<?php include __DIR__ . '/../includes/ui_page_end.php'; ?>
<?php include __DIR__ . '/../includes/ai_widget.php'; ?>
</body>
</html>
