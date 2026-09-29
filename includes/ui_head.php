<?php
/**
 * KUI (Kostlim UI) — подключение стилей нового интерфейса.
 * Вставлять в <head> ПОСЛЕДНИМ (после style.css, kostlim-upgrade.css и inline <style>).
 * Также в <meta viewport> должно быть viewport-fit=cover (для iPhone).
 */
$__kuiCss = __DIR__ . '/../assets/kostlim-ui.css';
?>
<link rel="stylesheet" href="/assets/kostlim-ui.css?v=<?= @filemtime($__kuiCss) ?: time() ?>">
