<?php
/**
 * Виджет "KOSTLIM AI SUPPORT" — плавающая кнопка + выезжающий чат.
 * Подключается через: <?php include __DIR__ . '/includes/ai_widget.php'; ?>
 * Всё общение через AJAX (ai_support.php), без перезагрузки страницы.
 */
?>
<div id="ai-widget-root">
    <div id="ai-widget-overlay"></div>

    <div id="ai-widget-bubble" class="ai-widget-bubble">
        <button type="button" id="ai-widget-bubble-close" aria-label="Скрыть подсказку">&times;</button>
        <div class="ai-widget-bubble-text">Привет! Нужна помощь с заказом?</div>
        <button type="button" id="ai-widget-bubble-ask" class="ai-widget-bubble-btn">Задать вопрос</button>
    </div>
    <button type="button" id="ai-widget-fab" aria-label="Открыть ИИ-поддержку">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"/><circle cx="12" cy="5" r="2"/><path d="M12 7v4"/><line x1="8" y1="16" x2="8" y2="16"/><line x1="16" y1="16" x2="16" y2="16"/></svg>
    </button>

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
            <div class="ai-widget-msg ai-widget-msg-bot">Привет! Я ИИ-помощник Kostlim Design 👋 Отвечу на вопросы по заказам, ценам и сайту. Чем помочь?</div>
        </div>

        <div id="ai-widget-quick" class="ai-widget-quick">
            <button type="button" class="ai-widget-quick-btn" data-q="Узнать прайс-лист">💰 Узнать прайс-лист</button>
            <button type="button" class="ai-widget-quick-btn" data-q="Как привязать Telegram?">🤖 Как привязать Telegram?</button>
            <button type="button" class="ai-widget-quick-btn" data-q="Как оплатить заказ?">💳 Как оплатить заказ?</button>
        </div>

        <div class="ai-widget-footer">
            <a href="https://t.me/Perlo_ovka" target="_blank" rel="noopener" class="ai-widget-manager-btn" title="Задать вопрос менеджеру">👤</a>
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
    position: fixed; top: 0; right: -420px; height: 100vh; width: 380px; max-width: 92vw;
    background: #1a1a22; border-left: 2px solid #33333f;
    display: flex; flex-direction: column; z-index: 9600;
    box-shadow: -16px 0 60px rgba(0,0,0,.7), 0 0 0 1px rgba(249,115,22,.08);
    transition: right .32s cubic-bezier(.2,.8,.3,1);
}
.ai-widget-panel.open { right: 0; }
.ai-widget-swipe-hint {
    width: 40px; height: 4px; border-radius: 3px; background: #3a3a46;
    margin: 10px auto 0; display: none; flex-shrink: 0;
}
@media (max-width: 480px) { .ai-widget-swipe-hint { display: block; } }

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
    flex: 1; overflow-y: auto; -webkit-overflow-scrolling: touch; overscroll-behavior: contain;
    padding: 16px; display: flex; flex-direction: column; gap: 10px; background: #17171f;
}
.ai-widget-msg { max-width: 86%; padding: 10px 13px; border-radius: 14px; font-size: 13px; line-height: 1.55; word-break: break-word; white-space: pre-wrap; }
.ai-widget-msg-bot { align-self: flex-start; background: #24242e; color: #e8e8ee; border-bottom-left-radius: 4px; border: 1px solid #2e2e3a; }
.ai-widget-msg-user { align-self: flex-end; background: linear-gradient(135deg,#fb923c,#f97316); color: #fff; border-bottom-right-radius: 4px; }

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
    flex: 1; background: #24242e; border: 1px solid #33333f; border-radius: 20px;
    padding: 10px 16px; color: #fff; font-size: 13px; font-family: inherit; min-width: 0;
}
#ai-widget-input:focus { outline: none; border-color: rgba(249,115,22,.5); }
#ai-widget-send {
    width: 36px; height: 36px; border-radius: 50%; border: none; cursor: pointer; flex-shrink: 0;
    background: linear-gradient(135deg,#fb923c,#f97316); color: #fff; display: flex; align-items: center; justify-content: center;
    transition: transform .15s;
}
#ai-widget-send:hover { transform: scale(1.08); }

#ai-widget-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 9550; display: none; }
#ai-widget-overlay.open { display: block; }

@media (max-width: 480px) {
    .ai-widget-panel { width: 100vw; max-width: 100vw; }
    #ai-widget-root { bottom: 16px; right: 16px; }
}
</style>

<script>
(function() {
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

    function openPanel() {
        bubble.classList.add('hidden');
        panel.classList.add('open');
        overlay.classList.add('open');
        setTimeout(function() { input.focus(); }, 350);
    }
    function closePanel() {
        panel.classList.remove('open');
        overlay.classList.remove('open');
    }

    fab.addEventListener('click', openPanel);
    bubbleAsk.addEventListener('click', openPanel);
    bubbleClose.addEventListener('click', function(e) { e.stopPropagation(); bubble.classList.add('hidden'); });
    closeBtn.addEventListener('click', closePanel);
    overlay.addEventListener('click', closePanel);

    // Клики внутри самой панели никогда не должны закрывать чат — раньше
    // из-за бага со стэком z-index оверлей "перекрывал" панель, и любой
    // клик (даже по полю ввода) считался кликом "мимо", закрывая чат.
    panel.addEventListener('click', function(e) { e.stopPropagation(); });

    // ── Свайп вправо для закрытия на телефоне ──
    // На мобильном панель занимает весь экран, поэтому "кликнуть мимо"
    // физически негде — вместо этого закрываем свайпом вправо по панели.
    var touchStartX = null, touchStartY = null;
    panel.addEventListener('touchstart', function(e) {
        touchStartX = e.touches[0].clientX;
        touchStartY = e.touches[0].clientY;
    }, { passive: true });
    panel.addEventListener('touchend', function(e) {
        if (touchStartX === null) return;
        var dx = e.changedTouches[0].clientX - touchStartX;
        var dy = Math.abs(e.changedTouches[0].clientY - touchStartY);
        if (dx > 80 && dy < 60) closePanel();
        touchStartX = null;
    }, { passive: true });

    setTimeout(function() {
        if (!panel.classList.contains('open')) bubble.classList.remove('hidden');
    }, 2000);

    function addMessage(text, who) {
        var div = document.createElement('div');
        div.className = 'ai-widget-msg ai-widget-msg-' + who;
        div.textContent = text;
        messages.appendChild(div);
        messages.scrollTop = messages.scrollHeight;
    }

    function showTyping() {
        var div = document.createElement('div');
        div.className = 'ai-widget-msg ai-widget-msg-bot';
        div.id = 'ai-widget-typing';
        div.textContent = 'печатает…';
        messages.appendChild(div);
        messages.scrollTop = messages.scrollHeight;
    }
    function hideTyping() {
        var t = document.getElementById('ai-widget-typing');
        if (t) t.remove();
    }

    var sending = false;
    function sendMessage(text) {
        text = (text || '').trim();
        if (text === '' || sending) return;
        sending = true;
        quick.classList.add('hidden');
        addMessage(text, 'user');
        input.value = '';
        showTyping();
        fetch('/ai_support.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ message: text }),
        }).then(function(res) { return res.json(); }).then(function(data) {
            hideTyping();
            addMessage(data.reply || 'Не удалось получить ответ 😔', 'bot');
            sending = false;
        }).catch(function() {
            hideTyping();
            addMessage('Ошибка соединения. Попробуй ещё раз или напиши: @Perlo_ovka', 'bot');
            sending = false;
        });
    }

    sendBtn.addEventListener('click', function() { sendMessage(input.value); });
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') sendMessage(input.value);
    });
    quick.querySelectorAll('.ai-widget-quick-btn').forEach(function(btn) {
        btn.addEventListener('click', function() { sendMessage(btn.dataset.q); });
    });

    resetBtn.addEventListener('click', function() {
        fetch('/ai_support.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ reset: true }),
        }).catch(function(){}).finally(function() {
            messages.innerHTML = '<div class="ai-widget-msg ai-widget-msg-bot">Привет! Я ИИ-помощник Kostlim Design 👋 Отвечу на вопросы по заказам, ценам и сайту. Чем помочь?</div>';
            quick.classList.remove('hidden');
        });
    });
})();
</script>