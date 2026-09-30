<?php
/**
 * KUI — оболочка АДМИН-ПАНЕЛИ. Вставляется в admin/*.php сразу после <body class="kui kui-admin">.
 *   $kuiAdminTitle = 'Админ-панель';   // необязательно
 *   include __DIR__ . '/../includes/ui_admin_shell.php';
 *
 * Шапка: [←На сайт] · логотип по центру · [профиль].
 * Нижнее меню (телефон) и шторка «Ещё» строятся скриптом assets/kostlim-admin.js
 * прямо из существующих кнопок вкладок .admin-tab (activateAdminTab остаётся главным).
 */
$kuiAdminTitle = $kuiAdminTitle ?? 'Админ-панель';
$__ava = '/assets/img/logo.png';
if (!empty($currentAvatarFile) && function_exists('imgSrc')) {
    $__try = imgSrc((string)$currentAvatarFile, '../uploads/');
    if ($__try) { $__ava = $__try; }
}
?>
<header class="kui-top kui-admin-top">
    <a class="kui-pill" href="../index.php">← На сайт</a>
    <a class="kui-logo" href="index.php" aria-label="Админ-панель"><img src="/assets/img/logo.png" alt=""></a>
    <div class="kui-top-r">
        <a class="kui-pill kui-admin-badge" href="profile.php"><img src="<?= htmlspecialchars($__ava) ?>" alt="" onerror="this.src='/assets/img/logo.png'">ADMIN</a>
    </div>
</header>
<div class="kui-admin-head"><h1 class="kui-h1"><?= htmlspecialchars($kuiAdminTitle) ?></h1></div>

<!-- нижнее меню и шторка «Ещё» заполняются скриптом -->
<nav class="kui-nav" id="kuiAdminNav" aria-label="Разделы админки"></nav>
<div class="kui-more" id="kuiMore"><div class="kui-more-sheet" id="kuiAdminMore"><div class="kui-grab"></div></div></div>
