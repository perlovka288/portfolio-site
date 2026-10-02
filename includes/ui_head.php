<?php
/**
 * KUI (Kostlim UI) — подключение стилей нового интерфейса.
 * Вставлять в <head> ПОСЛЕДНИМ (после style.css, kostlim-upgrade.css и inline <style>).
 * Также в <meta viewport> должно быть viewport-fit=cover (для iPhone).
 */
$__kuiCss = __DIR__ . '/../assets/kostlim-ui.css';
?>
<link rel="stylesheet" href="/assets/kostlim-modal.css?v=<?= @filemtime(__DIR__ . '/../assets/kostlim-modal.css') ?: time() ?>">
<link rel="stylesheet" href="/assets/kostlim-ui.css?v=<?= @filemtime($__kuiCss) ?: time() ?>">
<script src="/assets/kostlim-lock.js?v=<?= @filemtime(__DIR__ . '/../assets/kostlim-lock.js') ?: time() ?>" defer></script>
<script src="/assets/kostlim-nav.js?v=<?= @filemtime(__DIR__ . '/../assets/kostlim-nav.js') ?: time() ?>" defer></script>
<script src="/assets/kostlim-track.js?v=<?= @filemtime(__DIR__ . '/../assets/kostlim-track.js') ?: time() ?>" defer></script>
