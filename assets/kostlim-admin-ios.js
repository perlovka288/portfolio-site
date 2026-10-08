/*!
 * Kostlim Admin iOS — единый «iOS-стиль настроек» (как во вкладке «Ключи и API») для ВСЕЙ админки.
 * Скрипт сам превращает обычные формы «подпись + поле» в строки .ios-row, а подряд идущие строки группирует
 * в карточки .ios-card (разрыв группы — <hr>, кнопки, заголовки). Поля не пересоздаются, а переносятся, поэтому
 * name/id/обработчики/отправка форм работают как раньше. Динамические формы (AJAX, выезжающие панели) тоже подхватываются.
 * Работает только на страницах с <body class="kui-admin"> (там подключён kostlim-ui.css со стилями .ios-*).
 * Исключить блок: data-no-ios. Уже готовые iOS-разделы («Ключи и API», аналитика) не трогаются.
 */
(function () {
    'use strict';
    var SEL = 'form, .edit-drawer, [data-ios-auto]';
    var TEXTLIKE = /^(text|number|date|datetime-local|time|email|url|password|tel|search|month|week|)$/;

    function ready(fn) { if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn); else fn(); }

    function isCtl(el) {
        if (!el || el.nodeType !== 1) return false;
        if (el.tagName === 'SELECT' || el.tagName === 'TEXTAREA') return true;
        return el.tagName === 'INPUT' && el.type !== 'hidden' && el.type !== 'submit' && el.type !== 'button' && el.type !== 'checkbox' && el.type !== 'radio';
    }
    function isHint(el) { return el && el.nodeType === 1 && el.tagName === 'DIV' && (el.classList.contains('avatar-hint') || el.classList.contains('field-hint')); }
    function plainDiv(el) { return el && el.nodeType === 1 && el.tagName === 'DIV' && !el.classList.contains('two-cols') && !isHint(el); }
    function wrapsCtl(el) {
        return plainDiv(el) && !el.querySelector('label') && !!el.querySelector('input:not([type=hidden]),select,textarea') && !el.querySelector('button, form');
    }
    function isRegularLabel(el) {
        return el.nodeType === 1 && el.tagName === 'LABEL' && !el.classList.contains('tg-checkbox') && !el.querySelector('input[type=checkbox],input[type=radio]');
    }
    function isCheckLabel(el) {
        return el.nodeType === 1 && el.tagName === 'LABEL' && (el.classList.contains('tg-checkbox') || !!el.querySelector('input[type=checkbox]')) && !el.querySelector('select,textarea,input:not([type=checkbox])');
    }
    // контейнер «ячеек» (two-cols, pm-grid …): все дети — DIV, и каждый содержит подпись и поле
    function isCells(el) {
        if (!plainDiv(el) && !(el.classList && el.classList.contains('two-cols'))) return false;
        var kids = Array.prototype.filter.call(el.children, function (c) { return c.nodeType === 1; });
        if (!kids.length) return false;
        if (el.classList.contains('two-cols')) return true;
        return kids.every(function (c) { return c.tagName === 'DIV' && c.querySelector(':scope > label') && c.querySelector(':scope > input,:scope > select,:scope > textarea'); });
    }

    function styleCtl(c) {
        if (c.tagName === 'SELECT') { c.classList.add('ios-in', 'ios-select'); }
        else if (c.tagName === 'TEXTAREA') { c.classList.add('ios-ta'); }
        else if (c.tagName === 'INPUT' && TEXTLIKE.test(c.type || '')) { c.classList.add('ios-in'); }
    }
    function needsStack(ctls) {
        return ctls.some(function (c) {
            var t = c.tagName === 'DIV' ? c.querySelector('textarea,input[type=file]') : c;
            return t && (t.tagName === 'TEXTAREA' || t.type === 'file');
        }) || ctls.length > 1;
    }

    function buildRow(cardEl, label, ctls, hints) {
        var row = document.createElement('div');
        row.className = 'ios-row' + (needsStack(ctls) ? ' ios-stack' : '');
        // содержимое подписи (иконка + текст) — в одну строку, подсказки — мелким шрифтом под ней
        var lt = document.createElement('span'); lt.className = 'ios-lt';
        while (label.firstChild) lt.appendChild(label.firstChild);
        label.appendChild(lt);
        label.classList.add('ios-label');
        hints.forEach(function (h) { var sm = document.createElement('small'); sm.innerHTML = h.innerHTML; label.appendChild(sm); h.remove(); });
        var ctl = document.createElement('div'); ctl.className = 'ios-ctl';
        ctls.forEach(function (c) {
            if (c.tagName === 'DIV') { Array.prototype.forEach.call(c.querySelectorAll('input,select,textarea'), styleCtl); } else { styleCtl(c); }
            ctl.appendChild(c);
        });
        row.appendChild(label); row.appendChild(ctl);
        cardEl.appendChild(row);
    }

    function upgrade(container) {
        if (!container || container.__iosDone || container.closest('[data-no-ios],.ios-form,.ios-card,.ios-auto')) return;
        container.__iosDone = true;
        var kids = Array.prototype.slice.call(container.children), card = null, made = 0;
        function open(before) {
            if (!card) { card = document.createElement('div'); card.className = 'ios-card ios-auto'; container.insertBefore(card, before); }
            return card;
        }
        function close() { card = null; }

        function fromCell(cell, anchor) {
            var parts = Array.prototype.slice.call(cell.children), lb = null, ctls = [], hints = [];
            parts.forEach(function (n) {
                if (!lb && isRegularLabel(n)) lb = n;
                else if (lb && (isCtl(n) || wrapsCtl(n))) ctls.push(n);
                else if (lb && isHint(n)) hints.push(n);
            });
            if (lb && ctls.length) { buildRow(open(anchor), lb, ctls, hints); made++; }
        }

        for (var i = 0; i < kids.length; i++) {
            var el = kids[i];
            if (el.parentNode !== container || el.nodeType !== 1) continue;
            if (el.tagName === 'HR') { el.remove(); close(); continue; }

            if (isCells(el)) {
                var cells = Array.prototype.filter.call(el.children, function (c) { return c.nodeType === 1; });
                cells.forEach(function (cell) { fromCell(cell, el); });
                if (!el.children.length || !el.querySelector('input,select,textarea,label')) el.remove();
                continue;
            }
            if (isRegularLabel(el)) {
                var ctls = [], hints = [], j = i + 1;
                while (j < kids.length) {
                    var n = kids[j];
                    if (isCtl(n) || wrapsCtl(n)) { ctls.push(n); j++; }
                    else if (isHint(n)) { hints.push(n); j++; }
                    else break;
                }
                if (ctls.length) { buildRow(open(el), el, ctls, hints); made++; i = j - 1; continue; }
                close(); continue;
            }
            if (isCheckLabel(el)) {
                el.classList.add('ios-row', 'ios-row-switch', 'ios-checklabel');
                var chk = el.querySelector('input[type=checkbox]'); if (chk) chk.classList.add('ios-switch');
                var nx = kids[i + 1];
                if (isHint(nx)) { var sm = document.createElement('small'); sm.innerHTML = nx.innerHTML; el.appendChild(sm); nx.remove(); i++; }
                open(el).appendChild(el); made++;
                continue;
            }
            // обёртка с подписями внутри (например, блок, который показывается по условию)
            if (plainDiv(el) && el.querySelector(':scope > label') && el.querySelector(':scope > input,:scope > select,:scope > textarea')) {
                upgrade(el); close(); continue;
            }
            close();
        }
        if (made) {
            container.classList.add('ios-upgraded');
            var panel = container.closest('.panel');
            if (panel && !panel.querySelector('.card-grid,.grid-wrap,table,.item-card')) panel.classList.add('has-ios');
        }
    }

    function scan(root) {
        if (!document.body || !document.body.classList.contains('kui-admin')) return;
        root = root || document;
        if (root.nodeType === 1 && root.matches && root.matches(SEL)) upgrade(root);
        if (root.querySelectorAll) Array.prototype.forEach.call(root.querySelectorAll(SEL), upgrade);
    }

    ready(function () {
        scan(document);
        if (!document.body || !document.body.classList.contains('kui-admin')) return;
        var t = 0;
        new MutationObserver(function (muts) {
            var nodes = [];
            muts.forEach(function (m) { Array.prototype.forEach.call(m.addedNodes, function (n) { if (n.nodeType === 1) nodes.push(n); }); });
            if (!nodes.length) return;
            clearTimeout(t);
            t = setTimeout(function () { nodes.forEach(function (n) { if (n.isConnected) scan(n); }); }, 30);
        }).observe(document.body, { childList: true, subtree: true });
    });

    window.KIOS = { scan: scan };
})();
