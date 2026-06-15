<?php
/**
 * apply_patch.php
 * Запусти один раз: php apply_patch.php
 * Добавляет кнопку YouTube Парсер в admin/index.php
 */

$file = __DIR__ . '/admin/index.php';

if (!file_exists($file)) {
    die("❌ Файл не найден: $file\n");
}

$src = file_get_contents($file);
$original = $src;

// ── 1. Кнопка в навигации ─────────────────────────────────────────────────
$navAnchor = '<button type="button" class="admin-tab"        data-tab="avatar"     onclick="activateAdminTab(\'avatar\')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> Аватарка</button>';

$navInsert = $navAnchor . "\n            " .
'<button type="button" class="admin-tab"        data-tab="youtube"    onclick="activateAdminTab(\'youtube\')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="10" rx="2"/><polygon points="10 9 15 12 10 15 10 9" fill="currentColor" stroke="none"/></svg> YouTube</button>';

if (strpos($src, 'data-tab="youtube"') !== false) {
    echo "⚠️  Кнопка YouTube уже добавлена, пропускаю шаг 1.\n";
} elseif (strpos($src, $navAnchor) !== false) {
    $src = str_replace($navAnchor, $navInsert, $src);
    echo "✅ Шаг 1: кнопка добавлена в навигацию.\n";
} else {
    echo "❌ Шаг 1: якорь навигации не найден. Добавь кнопку вручную после кнопки 'Аватарка'.\n";
}

// ── 2. Панель YouTube (iframe) перед закрывающим </section> основного контента
$panelAnchor = "<!-- ════ АВАТАРКА САЙТА ════ -->";
$panelInsert = <<<'HTML'

                    <!-- ════ YOUTUBE ПАРСЕР ════ -->
                    <section class="panel" data-panel="youtube-panel" style="padding:0;overflow:hidden;border-radius:14px;min-height:600px;">
                        <iframe id="yt-parser-frame"
                            src=""
                            data-src="youtube_parser.php"
                            style="width:100%;height:calc(100vh - 160px);min-height:600px;border:none;display:block;border-radius:14px;background:#111116;"
                            loading="lazy">
                        </iframe>
                    </section>

HTML;

if (strpos($src, 'data-panel="youtube-panel"') !== false) {
    echo "⚠️  Панель YouTube уже добавлена, пропускаю шаг 2.\n";
} elseif (strpos($src, $panelAnchor) !== false) {
    $src = str_replace($panelAnchor, $panelInsert . $panelAnchor, $src);
    echo "✅ Шаг 2: панель (iframe) добавлена.\n";
} else {
    echo "❌ Шаг 2: якорь панели не найден. Вставь iframe-панель вручную в блок aside.\n";
}

// ── 3. JavaScript: обработка таба youtube в activateAdminTab ──────────────
$jsAnchor = "else if (tab === 'avatar')    { show('avatar'); }";
$jsInsert = $jsAnchor . "\n    else if (tab === 'youtube')  {
        show('youtube-panel');
        // Ленивая загрузка iframe — загружаем только при первом открытии
        const fr = document.getElementById('yt-parser-frame');
        if (fr && !fr.src && fr.dataset.src) {
            fr.src = fr.dataset.src;
        }
    }";

if (strpos($src, "tab === 'youtube'") !== false) {
    echo "⚠️  JS-обработчик YouTube уже добавлен, пропускаю шаг 3.\n";
} elseif (strpos($src, $jsAnchor) !== false) {
    $src = str_replace($jsAnchor, $jsInsert, $src);
    echo "✅ Шаг 3: JS-обработчик таба добавлен.\n";
} else {
    echo "❌ Шаг 3: якорь JS не найден. Добавь обработчик вручную в activateAdminTab().\n";
}

// ── Сохранение ─────────────────────────────────────────────────────────────
if ($src !== $original) {
    // Бекап
    file_put_contents($file . '.bak', $original);
    file_put_contents($file, $src);
    echo "\n✅ Патч применён! Бекап: admin/index.php.bak\n";
} else {
    echo "\nℹ️  Файл не изменён (всё уже было применено или якоря не найдены).\n";
}
