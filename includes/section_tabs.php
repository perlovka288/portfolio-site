<?php
/**
 * Навигация по трём разделам мини-аппы/сайта, встроенная в единый
 * компактный хедер (логотип + меню в одном блоке):
 * Главная (index.php) / Магазин (profile.php — раздел заказов) / Поддержка (support.php).
 *
 * Подключение (внутри <header>, под логотипом):
 *   $sectionTabsActive = 'home' | 'orders' | 'support';
 *   include __DIR__ . '/section_tabs.php';
 */
$sectionTabsActive = $sectionTabsActive ?? 'home';
?>
<nav class="section-tabs" aria-label="Разделы">
    <a href="index.php" class="section-tab <?= $sectionTabsActive === 'home' ? 'active' : '' ?>">Главная</a>
    <a href="profile.php#orders-section" class="section-tab <?= $sectionTabsActive === 'orders' ? 'active' : '' ?>">Магазин</a>
    <a href="support.php" class="section-tab <?= $sectionTabsActive === 'support' ? 'active' : '' ?>">Поддержка</a>
</nav>
