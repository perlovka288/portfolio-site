/* kostlim-dock.js — строка-промпт ИИ + док-навигация.
   • Док: на ПК соседние иконки плавно увеличиваются под курсором (как в macOS).
   • Промпт: Enter / стрелка открывают окно ИИ-чата и сразу отправляют вопрос.
     На телефоне касание строки сразу открывает чат (клавиатура и окно подстраиваются там).
   Чат — тот же виджет includes/ai_widget.php (запросы идут в ai_support.php). */
(function () {
  'use strict';
  if (window.__kuiDock) return; window.__kuiDock = true;

  var fine = window.matchMedia && window.matchMedia('(hover:hover) and (pointer:fine)').matches;

  /* ── Док: увеличение иконок ── */
  function initDock() {
    var nav = document.querySelector('.kd-stack .kui-nav');
    if (!nav || !fine) return;
    var items = Array.prototype.slice.call(nav.children).filter(function (el) { return el.matches('a,button'); });
    function scaleFor(d) { return d === 0 ? 1.4 : d === 1 ? 1.2 : d === 2 ? 1.1 : 1; }
    function apply(idx) { items.forEach(function (el, i) { el.style.setProperty('--kd-s', idx === null ? 1 : scaleFor(Math.abs(idx - i))); }); }
    items.forEach(function (el, i) { el.addEventListener('mouseenter', function () { apply(i); }); });
    nav.addEventListener('mouseleave', function () { apply(null); });
  }

  /* ── Промпт → ИИ-чат ── */
  function widget() {
    return {
      input: document.getElementById('ai-widget-input'),
      send: document.getElementById('ai-widget-send')
    };
  }
  function openChat() {
    if (typeof window.openAiWidgetPanel === 'function') { window.openAiWidgetPanel(); return true; }
    var t = document.querySelector('[data-open-ai-chat]');
    if (t) { t.click(); return true; }
    return false;
  }
  function initPrompt() {
    var form = document.getElementById('kdPrompt');
    var ta = document.getElementById('kdInput');
    if (!form || !ta) return;
    var sendBtn = form.querySelector('.kd-send');

    function grow() { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 88) + 'px'; }
    function state() { form.classList.toggle('has-text', ta.value.trim() !== ''); }
    ta.addEventListener('input', function () { grow(); state(); });

    function submit() {
      var text = ta.value.trim();
      if (!openChat()) { location.href = '/support.php'; return; }
      var w = widget();
      if (text && w.input && w.send) {
        w.input.value = text;
        w.send.click();
      }
      ta.value = ''; grow(); state(); ta.blur();
    }
    form.addEventListener('submit', function (e) { e.preventDefault(); submit(); });
    ta.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); submit(); }
    });

    // телефон: касание строки сразу открывает окно чата — там своя строка ввода, которая корректно поднимается над клавиатурой
    if (!fine) {
      ta.addEventListener('focus', function () {
        var carry = ta.value;
        if (!openChat()) return;
        ta.blur();
        var w = widget();
        if (w.input) { if (carry) { w.input.value = carry; ta.value = ''; grow(); state(); } setTimeout(function () { w.input.focus(); }, 380); }
      });
    }

    // быстрые кнопки
    form.querySelectorAll('[data-kd]').forEach(function (b) {
      b.addEventListener('click', function () {
        var k = b.getAttribute('data-kd');
        if (!openChat()) { location.href = '/support.php'; return; }
        var sel = { ideas: '.ai-widget-quick-btn[data-action="ideas"]', ctr: '.ai-widget-quick-btn[data-action="ctr"]',
                    price: '.ai-widget-quick-btn[data-q="Узнать прайс-лист"]', attach: '#ai-widget-attach-btn' }[k];
        var el = sel && document.querySelector(sel);
        if (el) el.click();
      });
    });
  }

  function init() { initDock(); initPrompt(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
