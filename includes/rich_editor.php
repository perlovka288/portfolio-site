<?php
/**
 * Переиспользуемый редактор текста с форматированием (Блок «правки во всех
 * разделах, где нужно что-то писать» — сейчас подключён в SD-гайде
 * (resources.php) и в статьях «Полезностей» (useful_posts.php)).
 *
 * На Quill.js (CDN, без сборки) — жирный/курсив/подчёркивание/зачёркивание,
 * цвет текста и фона, шрифт, списки, выравнивание, цитата, код, ссылка,
 * картинка (грузится на ImgBB через upload_editor_image.php), таблица.
 * Плюс отдельная всплывающая мини-панель с кнопкой «📋 Копировать»,
 * которая появляется при выделении текста где угодно на странице — не
 * только внутри самого редактора, а во ВСЁМ документе (гайды/статьи это
 * тоже читают, не только пишут).
 *
 * Использование:
 *   renderRichEditor('sd_guide', $currentHtml);   // $fieldName, $initialHtmlValue
 *   ...внутри <form method="post">...
 *   // При сабмите формы в $_POST[$fieldName] придёт HTML-разметка.
 *
 * renderRichEditorAssets() подключает Quill (CSS+JS) и общий JS-модуль
 * один раз на страницу — вызови его один раз в <head> или перед </body>,
 * даже если renderRichEditor() используется несколько раз на одной странице.
 */

function renderRichEditorAssets(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    ?>
    <link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
    <style>
        /* Тема редактора под сайт: #0D0D0D / #FF7A00 / #222222 */
        .ql-toolbar.ql-snow {
            background: #121212; border: 1px solid #222222 !important; border-radius: 10px 10px 0 0;
            padding: 8px 10px;
        }
        .ql-container.ql-snow {
            background: #0D0D0D; border: 1px solid #222222 !important; border-top: none !important;
            border-radius: 0 0 10px 10px; font-family: inherit; font-size: 14px; min-height: 160px;
        }
        .ql-editor { color: #F4F4F4; min-height: 160px; }
        .ql-editor.ql-blank::before { color: #6b6b6b; font-style: normal; }
        .ql-snow .ql-stroke { stroke: #C9C9C9; }
        .ql-snow .ql-fill, .ql-snow .ql-stroke.ql-fill { fill: #C9C9C9; }
        .ql-snow .ql-picker { color: #C9C9C9; }
        .ql-snow .ql-toolbar button:hover .ql-stroke,
        .ql-snow .ql-toolbar button.ql-active .ql-stroke,
        .ql-snow .ql-toolbar .ql-picker-label:hover .ql-stroke { stroke: #FF7A00 !important; }
        .ql-snow .ql-toolbar button:hover .ql-fill,
        .ql-snow .ql-toolbar button.ql-active .ql-fill { fill: #FF7A00 !important; }
        .ql-snow .ql-toolbar button.ql-active,
        .ql-snow .ql-toolbar .ql-picker-label.ql-active { color: #FF7A00; }
        .ql-snow .ql-picker-options {
            background: #121212 !important; border: 1px solid #222222 !important; border-radius: 8px;
        }
        .ql-snow .ql-tooltip {
            background: #121212 !important; border: 1px solid #222222 !important; color: #F4F4F4 !important;
            box-shadow: 0 8px 24px rgba(0,0,0,.4) !important; border-radius: 8px !important;
        }
        .ql-snow .ql-tooltip input[type=text] {
            background: #0D0D0D; border: 1px solid #222222; color: #F4F4F4; border-radius: 6px;
        }
        .ql-snow .ql-tooltip a.ql-action::after { color: #FF7A00; }
        .ql-editor table td { border: 1px solid #333; padding: 4px 8px; }
        .ql-editor a { color: #FF7A00; }
        .ql-editor blockquote { border-left: 3px solid #FF7A00; color: #C9C9C9; }
        .ql-editor pre.ql-syntax { background: #121212; border-radius: 8px; }

        /* Всплывающая кнопка «Копировать» при выделении текста (в т.ч. в
           уже опубликованных гайдах/статьях — не только в самом редакторе). */
        #richCopyPopup {
            position: fixed; z-index: 9999; display: none; background: #121212; border: 1px solid #FF7A00;
            border-radius: 8px; padding: 6px 12px; font-size: 12.5px; font-weight: 700; color: #F4F4F4;
            cursor: pointer; box-shadow: 0 4px 14px rgba(0,0,0,.4); user-select: none;
            transition: transform .1s;
        }
        #richCopyPopup:hover { background: #FF7A00; color: #0D0D0D; }
        #richCopyPopup.show { display: block; }
    </style>
    <div id="richCopyPopup">📋 Копировать</div>
    <script>
    (function() {
        // Всплывает при любом выделении текста на странице (включая гайды
        // и статьи «Полезностей», которые пишутся этим редактором, а
        // читаются как обычный текст) — не привязана к конкретному полю.
        const popup = document.getElementById('richCopyPopup');
        let hideTimeout;
        document.addEventListener('mouseup', () => {
            clearTimeout(hideTimeout);
            const sel = window.getSelection();
            const text = sel ? sel.toString().trim() : '';
            if (!text) { popup.classList.remove('show'); return; }
            const range = sel.getRangeAt(0);
            const rect = range.getBoundingClientRect();
            if (!rect || (rect.width === 0 && rect.height === 0)) { popup.classList.remove('show'); return; }
            popup.style.left = Math.max(8, rect.left + rect.width / 2 - 55) + 'px';
            popup.style.top = Math.max(8, rect.top - 38) + 'px';
            popup.dataset.text = text;
            popup.classList.add('show');
        });
        document.addEventListener('mousedown', (e) => {
            if (e.target !== popup) popup.classList.remove('show');
        });
        popup.addEventListener('mousedown', (e) => e.preventDefault()); // не сбрасывать выделение до клика
        popup.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(popup.dataset.text || '');
                popup.textContent = '✅ Скопировано';
                setTimeout(() => { popup.textContent = '📋 Копировать'; popup.classList.remove('show'); }, 900);
            } catch (e) {
                popup.textContent = '⚠️ Не удалось';
            }
        });
    })();

    // Инициализация одного экземпляра Quill. Вызывается из шаблона,
    // который печатает renderRichEditor() — см. ниже.
    function initRichEditor(containerId, hiddenFieldId, uploadUrl) {
        const toolbarOptions = [
            ['bold', 'italic', 'underline', 'strike'],
            [{ 'color': [] }, { 'background': [] }],
            [{ 'font': [] }, { 'size': ['small', false, 'large', 'huge'] }],
            [{ 'header': [1, 2, 3, false] }],
            [{ 'list': 'ordered' }, { 'list': 'bullet' }],
            [{ 'align': [] }],
            ['blockquote', 'code-block'],
            ['link', 'image'],
            ['clean']
        ];
        const quill = new Quill('#' + containerId, {
            theme: 'snow',
            modules: { toolbar: toolbarOptions }
        });
        const hidden = document.getElementById(hiddenFieldId);
        if (hidden && hidden.value) quill.root.innerHTML = hidden.value;
        quill.on('text-change', () => { if (hidden) hidden.value = quill.root.innerHTML; });

        // Кастомная загрузка картинки — на ImgBB через общий эндпоинт,
        // вместо base64 прямо в HTML (раздувает страницу и БД).
        quill.getModule('toolbar').addHandler('image', () => {
            const input = document.createElement('input');
            input.type = 'file'; input.accept = 'image/*';
            input.onchange = async () => {
                const file = input.files[0];
                if (!file) return;
                const range = quill.getSelection(true);
                quill.insertText(range.index, 'Загрузка картинки…', { italic: true });
                const fd = new FormData(); fd.append('image', file);
                try {
                    const res = await fetch(uploadUrl, { method: 'POST', body: fd });
                    const data = await res.json();
                    quill.deleteText(range.index, 'Загрузка картинки…'.length);
                    if (data.ok) { quill.insertEmbed(range.index, 'image', data.url); if (data.warning) console.warn(data.warning); }
                    else alert(data.error || 'Не удалось загрузить картинку');
                } catch (e) {
                    quill.deleteText(range.index, 'Загрузка картинки…'.length);
                    alert('Ошибка загрузки');
                }
            };
            input.click();
        });
        return quill;
    }
    </script>
    <?php
}

/**
 * Печатает один экземпляр редактора: скрытое поле формы + контейнер Quill.
 * $fieldName — имя поля, под которым HTML придёт в $_POST при сабмите формы.
 * $initialHtml — текущее значение (HTML), например то, что уже сохранено в БД.
 */
function renderRichEditor(string $fieldName, string $initialHtml = ''): void
{
    static $counter = 0;
    $counter++;
    $containerId = 'richEditor_' . $counter;
    $hiddenId = 'richField_' . $counter;
    ?>
    <input type="hidden" name="<?= htmlspecialchars($fieldName) ?>" id="<?= $hiddenId ?>" value="<?= htmlspecialchars($initialHtml, ENT_QUOTES) ?>">
    <div id="<?= $containerId ?>"></div>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            initRichEditor('<?= $containerId ?>', '<?= $hiddenId ?>', '/upload_editor_image.php');
        });
    </script>
    <?php
}
