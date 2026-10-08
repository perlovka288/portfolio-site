/* Kostlim Testimonials — порт InfiniteSlider (direction="vertical", speed, speedOnHover) на чистый JS.
   Источник: скрытый список карточек .kt-source. Колонки строятся по ширине экрана: 3 (≥1024), 2 (≥768), 1.
   Скорости как в компоненте: 30/15, 50/25, 35/17 пикс/сек (обычная / при наведении). */
(function () {
    'use strict';
    var wrap = document.querySelector('[data-kt]');
    if (!wrap) return;
    var source = wrap.querySelector('.kt-source');
    var colsBox = wrap.querySelector('.kt-cols');
    if (!source || !colsBox) return;
    var cards = Array.prototype.slice.call(source.children);
    if (!cards.length) return;
    wrap.classList.add('kt-js');

    var SPEEDS = [[30, 15], [50, 25], [35, 17]];
    var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
    var sliders = [], raf = 0, lastT = 0, built = -1;

    function colCount() {
        var n = window.innerWidth >= 1024 ? 3 : (window.innerWidth >= 768 ? 2 : 1);
        return Math.min(n, cards.length);
    }

    function Slider(root, speed, hover) {
        this.root = root; this.track = root.firstElementChild; this.set = this.track.firstElementChild;
        this.speed = speed; this.hover = hover; this.cur = speed; this.target = speed; this.offset = 0; this.h = 0; this.vis = true;
        var self = this;
        root.addEventListener('mouseenter', function () { self.target = self.hover; });
        root.addEventListener('mouseleave', function () { self.target = self.speed; });
        if ('IntersectionObserver' in window) {
            new IntersectionObserver(function (e) { self.vis = e[0].isIntersecting; }).observe(root);
        }
    }
    Slider.prototype.measure = function () {
        var t = this.track;
        Array.prototype.forEach.call(t.querySelectorAll('[data-clone]'), function (n) { n.remove(); });
        this.h = this.set.offsetHeight + 16;                         // набор + gap
        if (!this.h || !this.root.offsetParent) { this.h = 0; return; }
        var need = Math.ceil((colsBox.clientHeight || 640) / this.h) + 1;
        for (var i = 0; i < need; i++) {
            var c = this.set.cloneNode(true);
            c.setAttribute('data-clone', '1'); c.setAttribute('aria-hidden', 'true');
            t.appendChild(c);
        }
    };
    Slider.prototype.step = function (dt) {
        if (!this.h || !this.vis) return;
        this.cur += (this.target - this.cur) * Math.min(1, dt * 6);
        this.offset = (this.offset + this.cur * dt) % this.h;
        this.track.style.transform = 'translate3d(0,' + (-this.offset).toFixed(2) + 'px,0)';
    };

    function loop(t) {
        var dt = lastT ? Math.min(0.1, (t - lastT) / 1000) : 0;
        lastT = t;
        for (var i = 0; i < sliders.length; i++) sliders[i].step(dt);
        raf = requestAnimationFrame(loop);
    }

    function build() {
        var n = colCount();
        if (n === built) return;
        built = n;
        cancelAnimationFrame(raf); raf = 0; lastT = 0; sliders = [];
        colsBox.innerHTML = '';
        colsBox.classList.remove('kt-static');
        var buckets = []; for (var i = 0; i < n; i++) buckets.push([]);
        cards.forEach(function (c, idx) { buckets[idx % n].push(c.cloneNode(true)); });
        // мало отзывов — не крутим: статичные карточки
        var animate = !reduce && cards.length >= 4;
        buckets.forEach(function (list, i) {
            var root = document.createElement('div'); root.className = 'kt-slider';
            var track = document.createElement('div'); track.className = 'kt-track';
            var set = document.createElement('div'); set.className = 'kt-set';
            list.forEach(function (c) { set.appendChild(c); });
            track.appendChild(set); root.appendChild(track); colsBox.appendChild(root);
            if (animate) { var sp = SPEEDS[i % 3]; sliders.push(new Slider(root, sp[0], sp[1])); }
        });
        if (!animate) { colsBox.classList.add('kt-static'); return; }
        sliders.forEach(function (s) { s.measure(); });
        raf = requestAnimationFrame(loop);
    }

    build();
    var rt = 0;
    window.addEventListener('resize', function () {
        clearTimeout(rt);
        rt = setTimeout(function () {
            if (colCount() !== built) build(); else sliders.forEach(function (s) { s.measure(); });
        }, 150);
    });
    window.addEventListener('load', function () { sliders.forEach(function (s) { s.measure(); }); });
    if (window.KEI) KEI.replace(wrap);
})();
