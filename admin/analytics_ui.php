<?php /** Виджеты аналитики для «Обзора»: онлайн, метрики, график. Данные: admin/analytics_api.php, отрисовка: assets/kostlim-analytics.js */ ?>
<div class="kui-an" id="kuiAn">
    <div class="kui-an-head">
        <h2>📈 Аналитика</h2>
        <div class="ios-seg" id="anRange" role="group" aria-label="Период">
            <button type="button" data-r="7d" class="on">7 дней</button>
            <button type="button" data-r="30d">30 дней</button>
            <button type="button" data-r="12m">12 мес</button>
        </div>
    </div>

    <div class="kui-an-grid">
        <div class="kui-an-card kui-an-online">
            <div class="kui-an-lbl"><span class="kui-pulse"></span>Онлайн</div>
            <div class="kui-an-big" id="anOnline">–</div>
            <div class="kui-an-sub" id="anOnlineSub">сейчас на сайте</div>
            <div class="kui-an-top" id="anTop"></div>
        </div>
        <div class="kui-an-card" title="Визит — один приход на сайт. Пока человек листает страницы, это один визит; если он вернулся спустя 30+ минут — новый визит."><div class="kui-an-lbl">Визиты</div><div class="kui-an-big" id="anVisits">–</div><span class="kui-delta" id="anVisitsD">—</span><div class="kui-an-sub" id="anPages">приходы на сайт</div></div>
        <div class="kui-an-card" title="Сколько РАЗНЫХ устройств/браузеров заходило. Один человек, сколько бы раз ни заходил с одного устройства, — это один уникальный."><div class="kui-an-lbl">Уникальные</div><div class="kui-an-big" id="anUnique">–</div><span class="kui-delta" id="anUniqueD">—</span><div class="kui-an-sub">разные устройства</div></div>
        <div class="kui-an-card"><div class="kui-an-lbl" title="Заказы за период ÷ уникальные посетители. Заказы из Telegram-бота тоже входят.">Конверсия в заказ</div><div class="kui-an-big" id="anConv">–</div><span class="kui-delta" id="anConvD">—</span><div class="kui-an-sub" id="anOrders"></div></div>
    </div>

    <div class="kui-an-card kui-an-chartcard">
        <div class="kui-an-legend"><span><i style="background:var(--accent)"></i>Визиты</span><span><i style="background:#34c759"></i>Уникальные</span></div>
        <div class="kui-an-chart" id="anChart"><div class="kui-an-empty">Загрузка…</div></div>
    </div>

    <div class="ios-card kui-an-set">
        <div class="ios-row ios-row-switch">
            <label class="ios-label" for="anIgnore">Не считать мои заходы<small id="anIgnoreHint">С этого устройства — чтобы твои проверки не попадали в статистику</small></label>
            <div class="ios-ctl"><input type="checkbox" class="ios-switch no-switch-reset" id="anIgnore"></div>
        </div>
    </div>

    <details class="kui-an-help">
        <summary>ℹ️ Как читать эти цифры</summary>
        <div class="kui-an-helpbody">
            <p><b>Онлайн</b> — сколько людей открыто на сайте прямо сейчас (активны в последние ~минуту).</p>
            <p><b>Визиты</b> — сколько раз люди <i>приходили</i> на сайт. Открыл главную, прайс и заказ подряд — это <u>один</u> визит. Вернулся через 30+ минут — уже второй.</p>
            <p><b>Уникальные</b> — сколько <i>разных устройств</i> заходило. Если ты сам зайдёшь 10 раз с телефона — это 10 визитов, но 1 уникальный. С телефона и с компьютера — уже 2 уникальных (сайт не знает, что это один человек). Режим инкогнито или очистка cookies тоже считаются как новый уникальный.</p>
            <p><b>Конверсия</b> — какая доля уникальных посетителей оформила заказ (заказы ÷ уникальные). При маленькой посещаемости она «скачет»: 1 заказ на 2 человек = 50%. Заказы из Telegram-бота тоже входят.</p>
            <p><b>Стрелки ▲▼</b> — сравнение с таким же по длине прошлым периодом. «—» — за прошлый период данных ещё нет.</p>
            <p>Твои собственные заходы не считаются, если включён переключатель выше (или ты вошёл как админ на сайте).</p>
            <button type="button" class="ios-btn danger-btn" id="anReset">Сбросить всю статистику</button>
        </div>
    </details>
</div>
