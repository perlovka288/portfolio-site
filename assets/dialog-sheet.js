/* Kostlim: окна на телефоне — шторка снизу, закрывается свайпом вниз.
   Работает со всеми <dialog class="rd-dlg"> на странице. На ПК ничего не меняет
   (там окна просто по центру, см. dialog-sheet.css). */
(function () {
    var mq = window.matchMedia('(max-width: 640px)');
    function isSheet() { return mq.matches; }

    function reset(dlg) {
        dlg.style.transform = ''; dlg.style.transition = ''; dlg.style.animation = '';
    }

    // Закрытие с анимацией «уезжает вниз» (на ПК — обычное закрытие)
    function closeAnimated(dlg) {
        if (!dlg || !dlg.open) return;
        if (!isSheet()) { dlg.close(); return; }
        var done = false;
        function fin() { if (done) return; done = true; dlg.close(); }
        dlg.style.animation = 'none';
        void dlg.offsetHeight; // зафиксировать текущее положение перед переходом
        dlg.style.transition = 'transform .22s ease-in';
        dlg.style.transform = 'translateY(100%)';
        dlg.addEventListener('transitionend', function h(e) {
            if (e.target !== dlg) return;
            dlg.removeEventListener('transitionend', h); fin();
        });
        setTimeout(fin, 320);
    }
    window.rdCloseDialog = closeAnimated;

    // Крестик / «Отмена» / клик по затемнению — с анимацией на телефоне.
    // Capture-фаза: срабатывает раньше обычного обработчика и подменяет его.
    document.addEventListener('click', function (ev) {
        if (!isSheet()) return;
        var t = ev.target, dlg = null;
        if (t.closest) {
            var c = t.closest('[data-close]');
            if (c) dlg = c.closest('dialog.rd-dlg');
        }
        if (!dlg && t.tagName === 'DIALOG' && t.classList.contains('rd-dlg')) dlg = t;
        if (!dlg) return;
        ev.preventDefault(); ev.stopPropagation();
        closeAnimated(dlg);
    }, true);

    document.querySelectorAll('dialog.rd-dlg').forEach(function (dlg) {
        // после закрытия вернуть окно в исходное состояние (чтобы при открытии снова играла анимация)
        dlg.addEventListener('close', function () { reset(dlg); });
        // Esc (на планшетах/с клавиатурой) — тоже плавно
        dlg.addEventListener('cancel', function (e) { if (isSheet()) { e.preventDefault(); closeAnimated(dlg); } });

        var startY = 0, lastY = 0, lastT = 0, vel = 0, tracking = false, dragging = false, scroller = null;

        dlg.addEventListener('touchstart', function (e) {
            if (!isSheet() || e.touches.length !== 1) { tracking = false; return; }
            // не перехватывать работу с полями, видео, редактором
            if (e.target.closest('input,textarea,select,iframe,video,[contenteditable="true"],.ql-editor')) { tracking = false; return; }
            scroller = dlg.querySelector('.rf-form--dlg');
            tracking = true; dragging = false;
            startY = lastY = e.touches[0].clientY; lastT = Date.now(); vel = 0;
        }, { passive: true });

        dlg.addEventListener('touchmove', function (e) {
            if (!tracking) return;
            var y = e.touches[0].clientY, dy = y - startY;
            if (!dragging) {
                if (dy < -6) { tracking = false; return; }                      // листают вверх — это скролл
                if (dy > 6) {
                    if (scroller && scroller.scrollTop > 0) { tracking = false; return; } // сначала дочитать скролл
                    dragging = true; startY = y; dy = 0;
                    dlg.style.animation = 'none'; dlg.style.transition = 'none';
                } else return;
            }
            e.preventDefault();
            var now = Date.now();
            if (now > lastT) vel = (y - lastY) / (now - lastT);
            lastY = y; lastT = now;
            dlg.style.transform = 'translateY(' + Math.max(0, dy) + 'px)';
        }, { passive: false });

        function end() {
            if (!tracking) return;
            tracking = false;
            if (!dragging) return;
            dragging = false;
            var m = /translateY\(([\d.]+)px\)/.exec(dlg.style.transform || '');
            var dy = m ? parseFloat(m[1]) : 0;
            if (dy > 110 || (vel > 0.55 && dy > 20)) { closeAnimated(dlg); return; }
            // не дотянули — вернуть на место
            dlg.style.transition = 'transform .2s ease-out';
            dlg.style.transform = 'translateY(0)';
            setTimeout(function () { dlg.style.transition = ''; dlg.style.transform = ''; }, 210);
        }
        dlg.addEventListener('touchend', end);
        dlg.addEventListener('touchcancel', end);
    });
})();
