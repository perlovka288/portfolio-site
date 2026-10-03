/* assets/payment-methods.js
 * Подсвечивает рекомендованную систему и активную валюту при смене валюты.
 * Вызывается автоматически из switchCurrency(curr), если она есть на сайте.
 */
(function () {
  var RECOMMENDED = { UAH: 'monobank', RUB: 'donationalerts', BYN: 'donationalerts', KZT: 'donationalerts' };

  function highlightPaymentCurrency(cur) {
    cur = String(cur || '').toUpperCase();
    var block = document.getElementById('pmBlock');
    if (!block) return;
    block.setAttribute('data-active-currency', cur);
    var rec = RECOMMENDED[cur] || 'cryptobot';

    block.querySelectorAll('.pm-card').forEach(function (card) {
      var list = (card.getAttribute('data-currencies') || '').split(',');
      var supports = list.indexOf(cur) !== -1;
      card.classList.toggle('pm-recommended', card.dataset.method === rec);
      card.classList.toggle('pm-dim', !supports);
      var tag = card.querySelector('.pm-rec-tag');
      if (tag) tag.hidden = card.dataset.method !== rec;
    });
    block.querySelectorAll('.pm-chip').forEach(function (chip) {
      chip.classList.toggle('pm-chip-active', chip.getAttribute('data-cur') === cur);
    });
  }
  window.highlightPaymentCurrency = highlightPaymentCurrency;

  // Подхватываем существующую switchCurrency(curr)
  var orig = window.switchCurrency;
  window.switchCurrency = function (cur) {
    if (typeof orig === 'function') orig.apply(this, arguments);
    highlightPaymentCurrency(cur);
  };

  // Начальная валюта из localStorage / data-атрибута
  document.addEventListener('DOMContentLoaded', function () {
    var saved = null;
    try { saved = localStorage.getItem('currency'); } catch (e) {}
    var block = document.getElementById('pmBlock');
    highlightPaymentCurrency(saved || (block && block.dataset.activeCurrency) || 'UAH');
  });
})();
