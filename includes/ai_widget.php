<?php
/**
 * Виджет "KOSTLIM AI SUPPORT" — выезжающий чат с ИИ.
 * Подключается через: <?php include __DIR__ . '/includes/ai_widget.php'; ?>
 * Всё общение через AJAX (ai_support.php), без перезагрузки страницы.
 *
 * Перед include можно задать переменные:
 *   $aiWidgetHideFab = true;   // не показывать большую плавающую иконку/пузырь
 *                              // (панель чата всё равно доступна — открывается
 *                              // программно через window.openAiWidgetPanel())
 *   $aiWidgetContext = 'tz';   // страница заказа: при первом открытии в этом
 *                              // режиме ИИ сам поймёт, что он тут помогает
 *                              // составить ТЗ, и начнёт разговор с вопросов.
 */
$aiWidgetHideFab = $aiWidgetHideFab ?? false;
$aiWidgetContext = $aiWidgetContext ?? '';
?>
<!--
    ВАЖНО: раньше этот тег стоял только на index.php, поэтому на order.php и
    profile.php окно чата всегда падало в "Ошибка соединения" — window.fetch
    никто не патчил, и запрос уходил напрямую в PHP-эндпоинт, который для
    обычного сообщения ничего не возвращает. Теперь подключаем скрипт прямо
    вместе с виджетом, чтобы он работал на любой странице, где есть include.
-->
<script src="/ai_support.php"></script>
<div id="ai-widget-root" data-context="<?= htmlspecialchars($aiWidgetContext) ?>">
    <div id="ai-widget-overlay"></div>

    <?php if (!$aiWidgetHideFab): ?>
    <div id="ai-widget-bubble" class="ai-widget-bubble">
        <button type="button" id="ai-widget-bubble-close" aria-label="Скрыть подсказку">&times;</button>
        <div class="ai-widget-bubble-text">Привет! Нужна помощь с заказом?</div>
        <button type="button" id="ai-widget-bubble-ask" class="ai-widget-bubble-btn">Задать вопрос</button>
    </div>
    <button type="button" id="ai-widget-fab" aria-label="Открыть ИИ-поддержку">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"/><circle cx="12" cy="5" r="2"/><path d="M12 7v4"/><line x1="8" y1="16" x2="8" y2="16"/><line x1="16" y1="16" x2="16" y2="16"/></svg>
    </button>
    <?php endif; ?>

    <div id="ai-widget-panel" class="ai-widget-panel">
        <div class="ai-widget-swipe-hint"></div>
        <div class="ai-widget-header">
            <div class="ai-widget-avatar">🤖</div>
            <div class="ai-widget-header-text">
                <div class="ai-widget-name">KOSTLIM AI SUPPORT</div>
                <div class="ai-widget-status"><span class="ai-widget-dot"></span> Онлайн-консультант</div>
            </div>
            <button type="button" id="ai-widget-reset" title="Сбросить чат">🗑️</button>
            <button type="button" id="ai-widget-close" title="Закрыть">&times;</button>
        </div>

        <div id="ai-widget-messages" class="ai-widget-messages">
            <div class="ai-widget-msg-wrap ai-widget-msg-wrap-bot"><div class="ai-widget-msg ai-widget-msg-bot">Привет! Я ИИ-помощник Kostlim Design 👋 Отвечу на вопросы по заказам, ценам и сайту. Чем помочь?</div></div>
        </div>

        <div id="ai-widget-quick" class="ai-widget-quick">
            <button type="button" class="ai-widget-quick-btn" data-action="ideas">💡 Придумать идеи и CTR-заголовки</button>
            <button type="button" class="ai-widget-quick-btn" data-action="ctr">📊 Оценить CTR моего превью</button>
            <button type="button" class="ai-widget-quick-btn" data-q="Узнать прайс-лист">💰 Узнать прайс-лист</button>
            <button type="button" class="ai-widget-quick-btn" data-q="Как привязать Telegram?">✈️ Как привязать Telegram?</button>
        </div>

        <div id="ai-widget-attach-preview" class="ai-widget-attach-preview hidden">
            <img id="ai-widget-attach-preview-img" alt="превью">
            <span class="ai-widget-attach-preview-name" id="ai-widget-attach-preview-name"></span>
            <button type="button" id="ai-widget-attach-remove" aria-label="Убрать фото">✕</button>
        </div>

        <div class="ai-widget-footer">
            <a href="https://t.me/Perlo_ovka" target="_blank" rel="noopener" class="ai-widget-manager-btn" title="Задать вопрос менеджеру">👤</a>
            <button type="button" id="ai-widget-attach-btn" class="ai-widget-attach-btn" title="Прикрепить фото превью для оценки CTR">📎</button>
            <input type="file" id="ai-widget-photo-input" accept="image/*" style="display:none">
            <input type="text" id="ai-widget-input" placeholder="Напиши сообщение…" maxlength="2000" autocomplete="off">
            <button type="button" id="ai-widget-send" aria-label="Отправить">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
            </button>
        </div>
    </div>
</div>

<style>
#ai-widget-root { position: fixed; bottom: 22px; right: 22px; z-index: 9500; font-family: Montserrat, Arial, sans-serif; }

#ai-widget-fab {
    width: 58px; height: 58px; border-radius: 50%; border: none; cursor: pointer;
    background: linear-gradient(135deg, #fb923c, #f97316); color: #fff;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 8px 28px rgba(249,115,22,.45);
    transition: transform .18s, box-shadow .18s;
    position: relative; z-index: 2;
}
#ai-widget-fab:hover { transform: scale(1.06); box-shadow: 0 10px 34px rgba(249,115,22,.6); }

.ai-widget-bubble {
    position: absolute; bottom: 72px; right: 0; width: 240px;
    background: #16161d; border: 1px solid #26262f; border-radius: 16px 16px 4px 16px;
    padding: 14px 16px 16px; box-shadow: 0 12px 34px rgba(0,0,0,.5);
    animation: aiBubbleIn .35s ease .6s both;
}
.ai-widget-bubble.hidden { display: none; }
@keyframes aiBubbleIn { from { opacity:0; transform: translateY(10px) scale(.95); } to { opacity:1; transform: translateY(0) scale(1); } }
#ai-widget-bubble-close {
    position: absolute; top: 6px; right: 8px; background: none; border: none;
    color: #6a6a76; font-size: 16px; cursor: pointer; line-height: 1; padding: 4px;
}
#ai-widget-bubble-close:hover { color: #fff; }
.ai-widget-bubble-text { color: #e4e4ec; font-size: 13px; font-weight: 700; line-height: 1.5; margin: 6px 0 12px; padding-right: 10px; }
.ai-widget-bubble-btn {
    width: 100%; border: none; border-radius: 9px; padding: 10px; cursor: pointer;
    background: linear-gradient(135deg, #fb923c, #f97316); color: #fff; font-weight: 800;
    font-size: 12px; text-transform: uppercase; letter-spacing: .4px;
}

.ai-widget-panel {
    /* Правка ТЗ: раньше чат был "выезжающей шторкой" — справа на десктопе
       и снизу (bottom-sheet) на мобильном. Из-за того, как эта страница
       рендерится в некоторых предпросмотрах/Mini App-контейнерах, шторка,
       привязанная к краю экрана, могла показываться не полностью (часть
       окна оставалась "за кадром"), а снизу неё было видно кусок
       страницы — выглядело так, будто чат "прилип" к низу страницы, а не
       всплыл поверх неё. Теперь чат — это единое всплывающее ОКНО по
       центру экрана (как обычное модальное окно), одинаково на всех
       размерах экрана: не зависит от прокрутки и не "тянется" ни с какого
       края. */
    position: fixed;
    top: 50%; left: 50%;
    width: min(94vw, 420px);
    height: min(86vh, 680px);
    max-height: 86vh;
    background: #1a1a22; border: 1px solid #33333f;
    border-radius: 20px;
    display: flex; flex-direction: column; z-index: 9600;
    box-shadow: 0 24px 70px rgba(0,0,0,.7), 0 0 0 1px rgba(249,115,22,.08);
    opacity: 0; visibility: hidden;
    transform: translate(-50%, -50%) scale(.94);
    transition: transform .26s cubic-bezier(.2,.8,.3,1), opacity .22s ease, visibility .26s;
    /* Защита от авто-увеличения текста WebKit при фокусе на поле ввода —
       на случай, если эта разметка когда-нибудь окажется на странице без
       style.css (там такое же правило добавлено на уровне <html>). */
    -webkit-text-size-adjust: 100%; text-size-adjust: 100%;
}
.ai-widget-panel.open { opacity: 1; visibility: visible; transform: translate(-50%, -50%) scale(1); }
/* Хэндл для свайпа больше не нужен — окно не выезжает с края, которое
   можно было бы "утащить" обратно свайпом. */
.ai-widget-swipe-hint { display: none !important; }

.ai-widget-header { display: flex; align-items: center; gap: 12px; padding: 16px 16px; border-bottom: 1px solid #2a2a34; flex-shrink: 0; }
.ai-widget-avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg,#fb923c,#f97316); display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
.ai-widget-header-text { flex: 1; min-width: 0; }
.ai-widget-name { color: #fff; font-weight: 800; font-size: 13px; }
.ai-widget-status { color: #7ee787; font-size: 11px; display: flex; align-items: center; gap: 5px; margin-top: 2px; }
.ai-widget-dot { width: 6px; height: 6px; border-radius: 50%; background: #22c55e; box-shadow: 0 0 6px #22c55e; }
#ai-widget-reset, #ai-widget-close { background: none; border: none; color: #8a8a96; cursor: pointer; font-size: 15px; padding: 6px; border-radius: 8px; transition: color .15s, background .15s; }
#ai-widget-close { font-size: 22px; line-height: 1; }
#ai-widget-reset:hover, #ai-widget-close:hover { color: #fff; background: rgba(255,255,255,.08); }

.ai-widget-messages {
    flex: 1; min-height: 0; overflow-y: auto; -webkit-overflow-scrolling: touch; overscroll-behavior: contain;
    padding: 16px; display: flex; flex-direction: column; gap: 10px; background: #17171f;
}
.ai-widget-msg-wrap { max-width: 86%; display: flex; flex-direction: column; gap: 4px; }
.ai-widget-msg-wrap-bot { align-self: flex-start; }
.ai-widget-msg-wrap-user { align-self: flex-end; }
.ai-widget-msg { padding: 10px 13px; border-radius: 14px; font-size: 13px; line-height: 1.55; word-break: break-word; white-space: pre-wrap; }
.ai-widget-msg-bot { background: #24242e; color: #e8e8ee; border-bottom-left-radius: 4px; border: 1px solid #2e2e3a; }
.ai-widget-msg-user { background: linear-gradient(135deg,#fb923c,#f97316); color: #fff; border-bottom-right-radius: 4px; }
.ai-widget-msg-actions { display: flex; gap: 6px; flex-wrap: wrap; padding-left: 2px; }
.ai-widget-copy-btn {
    background: #1e1e26; border: 1px solid #2e2e3a; color: #9a9aa8; font-size: 10.5px; font-weight: 700;
    border-radius: 7px; padding: 4px 8px; cursor: pointer; transition: .15s;
}
.ai-widget-copy-btn:hover { color: #fdba74; border-color: rgba(249,115,22,.4); background: #24242e; }

.ai-widget-quick { display: flex; flex-wrap: wrap; gap: 6px; padding: 10px 16px; flex-shrink: 0; background: #1a1a22; border-top: 1px solid #2a2a34; }
.ai-widget-quick.hidden { display: none; }
.ai-widget-quick-btn {
    background: #2a2a35; border: 1px solid rgba(249,115,22,.35); color: #f0d0b8; font-size: 11.5px;
    font-weight: 600; padding: 8px 12px; border-radius: 20px; cursor: pointer; transition: border-color .15s, color .15s, background .15s;
}
.ai-widget-quick-btn:hover { border-color: rgba(249,115,22,.8); color: #fff; background: #33333f; }

.ai-widget-footer { display: flex; align-items: center; gap: 8px; padding: 12px 14px; border-top: 1px solid #2a2a34; flex-shrink: 0; background: #1a1a22; }
.ai-widget-manager-btn {
    width: 36px; height: 36px; border-radius: 50%; background: #24242e; border: 1px solid #33333f;
    display: flex; align-items: center; justify-content: center; text-decoration: none; font-size: 16px;
    flex-shrink: 0; transition: border-color .15s;
}
.ai-widget-manager-btn:hover { border-color: rgba(249,115,22,.5); }
#ai-widget-input {
    /* font-size ниже 16px — iOS Safari при фокусе на таком поле сам зумит
       страницу (считает, что текст слишком мелкий), и после этого зум не
       всегда корректно возвращается обратно — именно это и было на скрине:
       "приближает" при открытии клавиатуры. 16px — стандартный порог, при
       котором Safari зум не включает. */
    flex: 1; background: #24242e; border: 1px solid #33333f; border-radius: 20px;
    padding: 10px 16px; color: #fff; font-size: 16px; font-family: inherit; min-width: 0;
}
#ai-widget-input:focus { outline: none; border-color: rgba(249,115,22,.5); }
#ai-widget-send {
    width: 36px; height: 36px; border-radius: 50%; border: none; cursor: pointer; flex-shrink: 0;
    background: linear-gradient(135deg,#fb923c,#f97316); color: #fff; display: flex; align-items: center; justify-content: center;
    transition: transform .15s;
}
#ai-widget-send:hover { transform: scale(1.08); }

/* ── Прикреплённое фото над строкой ввода ── */
.ai-widget-attach-preview {
    display: flex; align-items: center; gap: 8px; padding: 8px 14px;
    background: #1e1e26; border-top: 1px solid #2a2a34; flex-shrink: 0;
}
.ai-widget-attach-preview.hidden { display: none; }
.ai-widget-attach-preview img { width: 34px; height: 34px; border-radius: 8px; object-fit: cover; flex-shrink: 0; }
.ai-widget-attach-preview-name { flex: 1; font-size: 11px; color: #9a9aa8; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
#ai-widget-attach-remove {
    background: none; border: none; color: #6a6a76; cursor: pointer; font-size: 15px; padding: 4px; line-height: 1;
}
#ai-widget-attach-remove:hover { color: #fb7185; }
.ai-widget-attach-btn {
    width: 36px; height: 36px; border-radius: 50%; background: #24242e; border: 1px solid #33333f;
    display: flex; align-items: center; justify-content: center; font-size: 16px; color: #9a9aa8;
    flex-shrink: 0; cursor: pointer; transition: border-color .15s, color .15s;
}
.ai-widget-attach-btn:hover { border-color: rgba(249,115,22,.5); color: #fdba74; }
.ai-widget-msg-img { max-width: 100%; border-radius: 12px; display: block; margin-bottom: 6px; }

/* ── Печатает: анимированные точки вместо статичного текста ── */
.ai-widget-typing-dots { display: inline-flex; gap: 4px; padding: 3px 0; }
.ai-widget-typing-dots span {
    width: 6px; height: 6px; border-radius: 50%; background: #8a8a96;
    animation: aiTypingBounce 1.1s infinite ease-in-out;
}
.ai-widget-typing-dots span:nth-child(2) { animation-delay: .15s; }
.ai-widget-typing-dots span:nth-child(3) { animation-delay: .3s; }
@keyframes aiTypingBounce { 0%, 60%, 100% { transform: translateY(0); opacity: .5; } 30% { transform: translateY(-4px); opacity: 1; } }
.ai-widget-typing-label { font-size: 11px; color: #6a6a76; margin-top: 3px; }

/* ── Структурированные карточки ответа (идеи / CTR-оценка) ── */
.ai-widget-card {
    background: #1e1e26; border: 1px solid #2e2e3a; border-radius: 14px; padding: 14px; width: 100%;
}
.ai-widget-card-title { font-size: 11.5px; font-weight: 800; color: #fdba74; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 10px; }
.ai-widget-headline-item {
    display: flex; align-items: center; gap: 8px; background: #24242e; border: 1px solid #2e2e3a;
    border-radius: 10px; padding: 9px 11px; margin-bottom: 6px; font-size: 12.5px; color: #e8e8ee;
}
.ai-widget-headline-item span { flex: 1; }
.ai-widget-headline-copy {
    background: none; border: none; color: #6a6a76; cursor: pointer; font-size: 13px; flex-shrink: 0; padding: 2px;
}
.ai-widget-headline-copy:hover { color: #fdba74; }
.ai-widget-concept-item { background: #24242e; border: 1px solid #2e2e3a; border-radius: 10px; padding: 11px; margin-bottom: 8px; }
.ai-widget-concept-title { font-size: 12.5px; font-weight: 800; color: #fff; margin-bottom: 4px; }
.ai-widget-concept-desc { font-size: 12px; color: #b4b4c0; line-height: 1.5; margin-bottom: 8px; }
.ai-widget-card-action-btn {
    width: 100%; background: rgba(249,115,22,.12); border: 1px solid rgba(249,115,22,.4); color: #fdba74;
    font-size: 11.5px; font-weight: 800; border-radius: 8px; padding: 8px; cursor: pointer; transition: .15s; font-family: inherit;
}
.ai-widget-card-action-btn:hover { background: rgba(249,115,22,.22); border-color: #f97316; }

.ai-widget-ctr-badge {
    display: inline-flex; align-items: center; gap: 6px; background: rgba(249,115,22,.14); border: 1px solid rgba(249,115,22,.4);
    color: #fdba74; font-size: 13px; font-weight: 900; border-radius: 20px; padding: 6px 14px; margin-bottom: 12px;
}
.ai-widget-ctr-row { display: flex; align-items: flex-start; gap: 7px; font-size: 12px; line-height: 1.5; margin-bottom: 6px; }
.ai-widget-ctr-row.plus { color: #86efac; }
.ai-widget-ctr-row.minus { color: #fca5a5; }
.ai-widget-ctr-tip { font-size: 11.5px; color: #9a9aa8; font-style: italic; margin: 8px 0 12px; padding-top: 8px; border-top: 1px solid #2e2e3a; }


#ai-widget-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 9550; display: none; }
#ai-widget-overlay.open { display: block; }

@media (max-width: 480px) {
    /* На совсем узких экранах модалка занимает почти весь экран по
       ширине/высоте — но остаётся ЦЕНТРИРОВАННЫМ окном (тот же принцип
       position:fixed + translate(-50%,-50%)), а не шторкой, приклеенной
       к нижнему краю. */
    .ai-widget-panel {
        width: 96vw;
        height: 90vh; height: 90dvh;
        max-height: 90vh; max-height: 90dvh;
        border-radius: 18px;
    }
    #ai-widget-root { bottom: 16px; right: 16px; }
}
body.ai-widget-lock { overflow: hidden; position: fixed; width: 100%; }
</style>

<script>
(function() {
    // ── Фикс клавиатуры на iOS/Android ──
    // Раньше здесь на КАЖДОЕ событие resize/scroll у visualViewport заново
    // считались height И transform: translateY(-offsetTop) — при открытии
    // клавиатуры это могло на мгновение схлопнуть блок переписки (у него
    // не было min-height:0, теперь есть) и дёргать панель. CSS dvh (см.
    // "height: 90dvh" в мобильном @media выше) сам корректно ужимает окно
    // под клавиатуру в обычном Safari — но сайт открывается и во встроенном
    // браузере Telegram (Mini App), а его WebView может формально уметь в
    // dvh, но считать его нестабильно. Поэтому JS-подстраховка ниже включена
    // ВСЕГДА (не только как фолбэк для старых браузеров) — inline
    // style.height от JS всегда побеждает CSS-класс, так что конфликта с
    // dvh нет, а поведение становится предсказуемым везде одинаково.
    // Никакого transform — только высота, без сдвига панели.
    function setupViewportFix(panel) {
        if (!window.visualViewport || panel.dataset.vvBound === '1') return;
        panel.dataset.vvBound = '1';
        var raf = null;
        function apply() {
            if (raf) cancelAnimationFrame(raf);
            raf = requestAnimationFrame(function() {
                var vv = window.visualViewport;
                var isMobile = window.innerWidth <= 480;
                // Окно теперь центрировано (position:fixed + translate(-50%,-50%)),
                // поэтому ограничиваем ТОЛЬКО max-height (чтобы клавиатура не
                // перекрывала поле ввода), а не задаём фиксированную height —
                // так модалка остаётся ровно по центру видимой области экрана.
                panel.style.maxHeight = Math.round(vv.height * (isMobile ? 0.9 : 0.86)) + 'px';
            });
        }
        window.visualViewport.addEventListener('resize', apply);
        apply();
    }

    var fab       = document.getElementById('ai-widget-fab');
    var bubble    = document.getElementById('ai-widget-bubble');
    var bubbleAsk = document.getElementById('ai-widget-bubble-ask');
    var bubbleClose = document.getElementById('ai-widget-bubble-close');
    var panel     = document.getElementById('ai-widget-panel');
    var overlay   = document.getElementById('ai-widget-overlay');
    var closeBtn  = document.getElementById('ai-widget-close');
    var resetBtn  = document.getElementById('ai-widget-reset');
    var messages  = document.getElementById('ai-widget-messages');
    var quick     = document.getElementById('ai-widget-quick');
    var input     = document.getElementById('ai-widget-input');
    var sendBtn   = document.getElementById('ai-widget-send');
    var root      = document.getElementById('ai-widget-root');
    var attachBtn      = document.getElementById('ai-widget-attach-btn');
    var photoInput      = document.getElementById('ai-widget-photo-input');
    var attachPreview   = document.getElementById('ai-widget-attach-preview');
    var attachPreviewImg  = document.getElementById('ai-widget-attach-preview-img');
    var attachPreviewName = document.getElementById('ai-widget-attach-preview-name');
    var attachRemoveBtn   = document.getElementById('ai-widget-attach-remove');

    // Прикреплённое клиентом фото превью (base64), ждёт отправки.
    var pendingImage = null; // { base64, name }

    function clearPendingImage() {
        pendingImage = null;
        photoInput.value = '';
        attachPreview.classList.add('hidden');
    }
    attachBtn && attachBtn.addEventListener('click', function() { photoInput.click(); });
    photoInput && photoInput.addEventListener('change', function() {
        var file = photoInput.files && photoInput.files[0];
        if (!file) return;
        if (!file.type || file.type.indexOf('image/') !== 0) {
            showAiToast('⚠️ Нужен файл-изображение');
            photoInput.value = '';
            return;
        }
        var reader = new FileReader();
        reader.onload = function() {
            pendingImage = { base64: reader.result, name: file.name };
            attachPreviewImg.src = reader.result;
            attachPreviewName.textContent = file.name;
            attachPreview.classList.remove('hidden');
            input.focus();
        };
        reader.readAsDataURL(file);
    });
    attachRemoveBtn && attachRemoveBtn.addEventListener('click', clearPendingImage);

    function showAiToast(text) {
        if (window.showToastMsg) { window.showToastMsg(text, '#ef4444'); return; }
        addMessage(text, 'bot');
    }

    // Текущий textarea ТЗ, в который нужно вставлять готовый текст от ИИ
    // (устанавливается кнопкой "Помочь составить ТЗ" на странице заказа).
    window.__aiTzTarget = null;
    window.__aiTzPrimed = false;

    function openPanel() {
        if (bubble) bubble.classList.add('hidden');
        panel.classList.add('open');
        overlay.classList.add('open');
        document.body.classList.add('ai-widget-lock');
        setupViewportFix(panel);
        setTimeout(function() { input.focus(); }, 350);
    }
    function closePanel() {
        panel.classList.remove('open');
        overlay.classList.remove('open');
        document.body.classList.remove('ai-widget-lock');
    }

    // Программное открытие панели снаружи (например, кнопкой в блоке ТЗ на
    // странице заказа). context === 'tz' — ИИ сам начинает разговор и знает,
    // что сейчас поможет составить техническое задание.
    window.openAiWidgetPanel = function(context, targetTextareaId) {
        if (targetTextareaId) {
            window.__aiTzTarget = document.getElementById(targetTextareaId);
        }
        openPanel();
        if (context === 'tz' && !window.__aiTzPrimed) {
            window.__aiTzPrimed = true;
            quick.classList.add('hidden');
            messages.innerHTML = '';
            showTyping();
            sendMessage(
                'Ты сейчас находишься прямо в блоке "Техническое задание" на странице оформления заказа. ' +
                'Помоги клиенту составить чёткое ТЗ: сначала поприветствуй и спроси, что он хочет заказать ' +
                '(превью, оформление канала, аватарка и т.д.) и какие у него пожелания по стилю, цветам, ' +
                'референсам, тексту. Когда информации хватит, оформи готовый текст ТЗ отдельным абзацем ' +
                'после фразы "Готовое ТЗ:", чтобы клиент мог его скопировать или вставить в форму одной кнопкой.',
                { silent: true }
            );
        }
    };

    fab && fab.addEventListener('click', openPanel);
    bubbleAsk && bubbleAsk.addEventListener('click', openPanel);
    bubbleClose && bubbleClose.addEventListener('click', function(e) { e.stopPropagation(); bubble.classList.add('hidden'); });
    closeBtn.addEventListener('click', closePanel);
    overlay.addEventListener('click', closePanel);

    // Подстраховка: любая кнопка с data-open-ai-chat открывает панель, даже
    // если по какой-то причине не сработал inline-обработчик (например,
    // клик случился раньше, чем этот скрипт успел выполниться на медленном
    // соединении) — делегирование на document ловит клик в любом случае.
    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-open-ai-chat]');
        if (trigger) openPanel();
    });

    // Клики внутри самой панели никогда не должны закрывать чат — раньше
    // из-за бага со стэком z-index оверлей "перекрывал" панель, и любой
    // клик (даже по полю ввода) считался кликом "мимо", закрывая чат.
    panel.addEventListener('click', function(e) { e.stopPropagation(); });

    // Свайп-закрытие убран вместе с шторкой снизу/справа — теперь окно
    // центрировано и всегда есть видимый оверлей вокруг, по которому можно
    // кликнуть/тапнуть, чтобы закрыть чат, плюс кнопка ✕ в шапке.

    if (bubble) {
        setTimeout(function() {
            if (!panel.classList.contains('open')) bubble.classList.remove('hidden');
        }, 2000);
    }

    function addMessage(text, who, opts) {
        opts = opts || {};
        var wrap = document.createElement('div');
        wrap.className = 'ai-widget-msg-wrap ai-widget-msg-wrap-' + who;

        var div = document.createElement('div');
        div.className = 'ai-widget-msg ai-widget-msg-' + who;

        if (opts.imageSrc) {
            var img = document.createElement('img');
            img.className = 'ai-widget-msg-img';
            img.src = opts.imageSrc;
            img.alt = 'превью';
            div.appendChild(img);
        }
        if (opts.cardEl) {
            div.appendChild(opts.cardEl);
        } else if (text) {
            var textNode = document.createElement('div');
            textNode.textContent = text;
            div.appendChild(textNode);
        }
        wrap.appendChild(div);

        // Под каждым текстовым сообщением ИИ (не карточкой — у карточек свои
        // action-кнопки внутри) — кнопка "Копировать", а в режиме подсказки
        // ТЗ — ещё и "Вставить в ТЗ" (прямо в textarea заказа).
        if (who === 'bot' && text && !opts.cardEl) {
            var actions = document.createElement('div');
            actions.className = 'ai-widget-msg-actions';

            var copyBtn = document.createElement('button');
            copyBtn.type = 'button';
            copyBtn.className = 'ai-widget-copy-btn';
            copyBtn.textContent = '📋 Копировать';
            copyBtn.addEventListener('click', function() {
                var toCopy = text;
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(toCopy);
                } else {
                    var ta = document.createElement('textarea');
                    ta.value = toCopy; document.body.appendChild(ta);
                    ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
                }
                copyBtn.textContent = '✅ Скопировано';
                setTimeout(function() { copyBtn.textContent = '📋 Копировать'; }, 1600);
            });
            actions.appendChild(copyBtn);

            if (window.__aiTzTarget) {
                var insertBtn = document.createElement('button');
                insertBtn.type = 'button';
                insertBtn.className = 'ai-widget-copy-btn';
                insertBtn.textContent = '➕ Вставить в ТЗ';
                insertBtn.addEventListener('click', function() {
                    var ta = window.__aiTzTarget;
                    if (!ta) return;
                    var clean = text.replace(/^[\s\S]*?Готовое ТЗ:\s*/i, '');
                    ta.value = (ta.value ? ta.value.trim() + '\n\n' : '') + clean.trim();
                    ta.dispatchEvent(new Event('input', { bubbles: true }));
                    insertBtn.textContent = '✅ Вставлено';
                    setTimeout(function() { insertBtn.textContent = '➕ Вставить в ТЗ'; }, 1600);
                });
                actions.appendChild(insertBtn);
            }
            wrap.appendChild(actions);
        }

        messages.appendChild(wrap);
        messages.scrollTop = messages.scrollHeight;
        return wrap;
    }

    function showTyping(label) {
        var wrap = document.createElement('div');
        wrap.className = 'ai-widget-msg-wrap ai-widget-msg-wrap-bot';
        wrap.id = 'ai-widget-typing';
        var div = document.createElement('div');
        div.className = 'ai-widget-msg ai-widget-msg-bot';
        var dots = document.createElement('div');
        dots.className = 'ai-widget-typing-dots';
        dots.innerHTML = '<span></span><span></span><span></span>';
        div.appendChild(dots);
        wrap.appendChild(div);
        if (label) {
            var lbl = document.createElement('div');
            lbl.className = 'ai-widget-typing-label';
            lbl.textContent = label;
            wrap.appendChild(lbl);
        }
        messages.appendChild(wrap);
        messages.scrollTop = messages.scrollHeight;
    }
    function hideTyping() {
        var t = document.getElementById('ai-widget-typing');
        if (t) t.remove();
    }

    // ── Вставка концепта в ТЗ / прикрепление фото к заказу ──
    // Если чат открыт прямо на странице заказа (order.php) — textarea ТЗ
    // уже есть в DOM, вставляем сразу. Если чат открыт на другой странице
    // (каталог, профиль) — сохраняем в sessionStorage и переходим на
    // order.php, где он подхватится сразу при загрузке (см. order.php).
    function insertConceptIntoTZ(conceptText) {
        var target = window.__aiTzTarget || document.getElementById('s1_details');
        if (target) {
            target.value = (target.value ? target.value.trim() + '\n\n' : '') + conceptText.trim();
            target.dispatchEvent(new Event('input', { bubbles: true }));
            closePanel();
            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
        try { sessionStorage.setItem('kostlim_ai_concept', conceptText.trim()); } catch (e) {}
        window.location.href = '/order.php';
    }
    function attachImageToOrderForm(base64) {
        var refsInput = document.getElementById('s1_refs');
        if (refsInput) {
            fetch(base64).then(function(r) { return r.blob(); }).then(function(blob) {
                var file = new File([blob], 'ctr-preview.jpg', { type: blob.type || 'image/jpeg' });
                var dt = new DataTransfer();
                Array.from(refsInput.files || []).forEach(function(f) { dt.items.add(f); });
                dt.items.add(file);
                refsInput.files = dt.files;
                refsInput.dispatchEvent(new Event('change', { bubbles: true }));
                closePanel();
                refsInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }).catch(function() {
                showAiToast('Не получилось прикрепить фото автоматически — прикрепи вручную в разделе "Референсы"');
            });
            return;
        }
        try { sessionStorage.setItem('kostlim_ai_ctr_image', base64); } catch (e) {
            showAiToast('Фото слишком большое для передачи между страницами — прикрепи его в форме заказа вручную');
            return;
        }
        window.location.href = '/order.php';
    }

    // ── Разбор структурированных ответов ИИ ──
    // Модель просят (см. промпты ниже) отвечать в строгом построчном
    // формате с метками — это надёжнее, чем просить настоящий JSON
    // (модель иногда обрамляет его текстом/markdown, ломая парсинг).
    // Если разметки нет — парсер вернёт null и сообщение покажется как
    // обычный текст (без потери ответа).
    function parseIdeasFormat(text) {
        var headlines = [];
        var concepts = [];
        text.split('\n').forEach(function(line) {
            var h = line.match(/^\s*ЗАГОЛОВОК\s*:\s*(.+)$/i);
            if (h) { headlines.push(h[1].trim()); return; }
            var c = line.match(/^\s*КОНЦЕПТ\s*:\s*(.+)$/i);
            if (c) {
                var parts = c[1].split('|');
                concepts.push({
                    title: (parts[0] || '').trim(),
                    desc: (parts.slice(1).join('|') || '').trim(),
                });
            }
        });
        if (headlines.length === 0 && concepts.length === 0) return null;
        return { headlines: headlines, concepts: concepts };
    }
    function parseCtrFormat(text) {
        var scoreMatch = text.match(/ОЦЕНКА\s*:\s*([\d.,]+)/i);
        if (!scoreMatch) return null;
        var pluses = [], minuses = [], tip = '';
        text.split('\n').forEach(function(line) {
            var p = line.match(/^\s*ПЛЮС\s*:\s*(.+)$/i);
            if (p) { pluses.push(p[1].trim()); return; }
            var m = line.match(/^\s*МИНУС\s*:\s*(.+)$/i);
            if (m) { minuses.push(m[1].trim()); return; }
            var t = line.match(/^\s*СОВЕТ\s*:\s*(.+)$/i);
            if (t) { tip = t[1].trim(); }
        });
        return { score: scoreMatch[1].replace(',', '.'), pluses: pluses, minuses: minuses, tip: tip };
    }

    function renderIdeasCard(parsed) {
        var card = document.createElement('div');
        card.className = 'ai-widget-card';

        if (parsed.headlines.length) {
            var t1 = document.createElement('div');
            t1.className = 'ai-widget-card-title';
            t1.textContent = '💡 Заголовки';
            card.appendChild(t1);
            parsed.headlines.forEach(function(h) {
                var item = document.createElement('div');
                item.className = 'ai-widget-headline-item';
                var span = document.createElement('span');
                span.textContent = h;
                item.appendChild(span);
                var cp = document.createElement('button');
                cp.type = 'button'; cp.className = 'ai-widget-headline-copy'; cp.textContent = '📋';
                cp.addEventListener('click', function() {
                    if (navigator.clipboard) navigator.clipboard.writeText(h);
                    cp.textContent = '✅'; setTimeout(function() { cp.textContent = '📋'; }, 1400);
                });
                item.appendChild(cp);
                card.appendChild(item);
            });
        }
        if (parsed.concepts.length) {
            var t2 = document.createElement('div');
            t2.className = 'ai-widget-card-title';
            t2.style.marginTop = parsed.headlines.length ? '14px' : '0';
            t2.textContent = '🎨 Визуальные концепты';
            card.appendChild(t2);
            parsed.concepts.forEach(function(c) {
                var item = document.createElement('div');
                item.className = 'ai-widget-concept-item';
                var title = document.createElement('div');
                title.className = 'ai-widget-concept-title';
                title.textContent = c.title;
                item.appendChild(title);
                if (c.desc) {
                    var desc = document.createElement('div');
                    desc.className = 'ai-widget-concept-desc';
                    desc.textContent = c.desc;
                    item.appendChild(desc);
                }
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'ai-widget-card-action-btn';
                btn.textContent = '➕ Вставить в ТЗ заказа';
                btn.addEventListener('click', function() {
                    insertConceptIntoTZ(c.title + (c.desc ? '. ' + c.desc : ''));
                });
                item.appendChild(btn);
                card.appendChild(item);
            });
        }
        return card;
    }

    function renderCtrCard(parsed, imageBase64) {
        var card = document.createElement('div');
        card.className = 'ai-widget-card';

        var badge = document.createElement('div');
        badge.className = 'ai-widget-ctr-badge';
        badge.textContent = '🎯 Оценка CTR: ' + parsed.score + '/10';
        card.appendChild(badge);

        parsed.pluses.forEach(function(p) {
            var row = document.createElement('div');
            row.className = 'ai-widget-ctr-row plus';
            row.textContent = '✅ ' + p;
            card.appendChild(row);
        });
        parsed.minuses.forEach(function(m) {
            var row = document.createElement('div');
            row.className = 'ai-widget-ctr-row minus';
            row.textContent = '⚠️ ' + m;
            card.appendChild(row);
        });
        if (parsed.tip) {
            var tip = document.createElement('div');
            tip.className = 'ai-widget-ctr-tip';
            tip.textContent = '💡 ' + parsed.tip;
            card.appendChild(tip);
        }
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'ai-widget-card-action-btn';
        btn.textContent = '🎨 Заказать переработку этого превью';
        btn.addEventListener('click', function() { attachImageToOrderForm(imageBase64); });
        card.appendChild(btn);
        return card;
    }

    var IDEAS_PROMPT = 'Пользователь хочет получить идеи и цепляющие заголовки для превью/тамбнейла. ' +
        'Если тема ещё не понятна из диалога — сначала спроси, на какую тему нужно превью (игра, влог, гайд и т.д.), ' +
        'ничего не генерируя в этом сообщении. Если тема уже понятна — сразу выдай результат СТРОГО построчно, ' +
        'без markdown и звёздочек, каждая строка с новой строки, ровно в таком формате:\n' +
        'ЗАГОЛОВОК: <текст заголовка>\n(повтори 3-5 раз для разных заголовков)\n' +
        'КОНЦЕПТ: <короткое название концепта> | <что на переднем плане, какой текст на картинке, какие эмоции — 1-2 предложения>\n' +
        '(повтори 2-3 раза для разных концептов)';
    var CTR_PROMPT = 'Пользователь прислал своё превью/тамбнейл и просит оценить его CTR-потенциал (кликабельность). ' +
        'Проанализируй композицию, читаемость текста, контраст, эмоции на лицах, соответствие теме. ' +
        'Ответь СТРОГО построчно, без markdown и звёздочек, ровно в таком формате:\n' +
        'ОЦЕНКА: <число от 1 до 10>\n' +
        'ПЛЮС: <первый плюс>\nПЛЮС: <второй плюс>\n' +
        'МИНУС: <первая проблема>\nМИНУС: <вторая проблема>\n' +
        'СОВЕТ: <краткая рекомендация одним предложением>';

    var sending = false;
    function sendMessage(text, opts) {
        opts = opts || {};
        text = (text || '').trim();
        var image = opts.image || null;
        if ((text === '' && !image) || sending) return;
        sending = true;
        quick.classList.add('hidden');

        var apiText = opts.apiText || text;
        var isCtrFlow = !!image;
        if (isCtrFlow) apiText = CTR_PROMPT + (text ? ('\n\nКомментарий клиента: ' + text) : '');

        if (!opts.silent) {
            addMessage(isCtrFlow ? (text || 'Оцени CTR этого превью') : text, 'user', image ? { imageSrc: image } : {});
            input.value = '';
            if (isCtrFlow) addMessage('Картинка получена! Запускаю ИИ-анализ CTR…', 'bot');
            showTyping(isCtrFlow ? 'KOSTLIM AI анализирует превью…' : 'KOSTLIM AI генерирует ответ…');
        }

        fetch('/ai_support.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ message: apiText, image: image }),
        }).then(function(res) { return res.json(); }).then(function(data) {
            hideTyping();
            var reply = data.reply || 'Не удалось получить ответ 😔';
            var ideas = !isCtrFlow ? parseIdeasFormat(reply) : null;
            var ctr = isCtrFlow ? parseCtrFormat(reply) : null;
            if (ideas) {
                addMessage('', 'bot', { cardEl: renderIdeasCard(ideas) });
            } else if (ctr) {
                addMessage('', 'bot', { cardEl: renderCtrCard(ctr, image) });
            } else {
                addMessage(reply, 'bot');
            }
            sending = false;
        }).catch(function() {
            hideTyping();
            addMessage('Ошибка соединения. Попробуй ещё раз или напиши: @Perlo_ovka', 'bot');
            sending = false;
        });
    }

    function sendFromInput() {
        var image = pendingImage ? pendingImage.base64 : null;
        var text = input.value;
        clearPendingImage();
        sendMessage(text, image ? { image: image } : {});
    }

    sendBtn.addEventListener('click', sendFromInput);
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') sendFromInput();
    });
    quick.querySelectorAll('.ai-widget-quick-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (btn.dataset.action === 'ideas') {
                sendMessage('Хочу идеи и заголовки для превью', { apiText: IDEAS_PROMPT });
            } else if (btn.dataset.action === 'ctr') {
                addMessage('Пришли скриншот своего превью — разберу его по CTR 📊', 'bot');
                quick.classList.add('hidden');
                photoInput.click();
            } else {
                sendMessage(btn.dataset.q);
            }
        });
    });

    resetBtn.addEventListener('click', function() {
        clearPendingImage();
        fetch('/ai_support.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ reset: true }),
        }).catch(function(){}).finally(function() {
            messages.innerHTML = '<div class="ai-widget-msg-wrap ai-widget-msg-wrap-bot"><div class="ai-widget-msg ai-widget-msg-bot">Привет! Я ИИ-помощник Kostlim Design 👋 Отвечу на вопросы по заказам, ценам и сайту. Чем помочь?</div></div>';
            quick.classList.remove('hidden');
            window.__aiTzPrimed = false;
        });
    });
})();
</script>