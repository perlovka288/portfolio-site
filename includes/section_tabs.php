<?php
/**
 * Верхняя навигация по трём разделам мини-аппы/сайта:
 * Главная (index.php) / Заказы (profile.php — раздел заказов) / Поддержка (support.php).
 *
 * Подключение:
 *   $sectionTabsActive = 'home' | 'orders' | 'support';
 *   include __DIR__ . '/section_tabs.php';
 */
$sectionTabsActive = $sectionTabsActive ?? 'home';
?>
<nav class="section-tabs" aria-label="Разделы">
    <a href="index.php" class="section-tab <?= $sectionTabsActive === 'home' ? 'active' : '' ?>">Главная</a>
    <a href="profile.php#orders-section" class="section-tab <?= $sectionTabsActive === 'orders' ? 'active' : '' ?>">Заказы</a>
    <a href="support.php" class="section-tab <?= $sectionTabsActive === 'support' ? 'active' : '' ?>">Поддержка</a>
</nav>
