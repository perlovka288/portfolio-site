<?php
/**
 * Заготовка для ЛЮБОЙ страницы. Вставить сразу после <body class="... kui">:
 *   $kuiActive = 'price';                       // какой пункт меню подсветить
 *   $kuiTitle  = 'Прайс';                       // заголовок страницы (необязательно)
 *   include __DIR__ . '/includes/ui_page_start.php';
 *   ... контент страницы (используй классы KUI: .kui-card, .kui-btn, .kui-row ...) ...
 *   include __DIR__ . '/includes/ui_page_end.php';
 */
$aiWidgetHideFab = true; // круглую ИИ-кнопку заменяет иконка ✦ в шапке
include __DIR__ . '/ui_shell.php';
?>
<main class="kui-main">
    <?php if (!empty($kuiTitle)): ?><h1 class="kui-h1"><?= htmlspecialchars($kuiTitle) ?></h1><?php endif; ?>
