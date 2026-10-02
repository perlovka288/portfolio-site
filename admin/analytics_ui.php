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
        <div class="kui-an-card"><div class="kui-an-lbl">Всего визитов</div><div class="kui-an-big" id="anVisits">–</div><span class="kui-delta" id="anVisitsD">—</span></div>
        <div class="kui-an-card"><div class="kui-an-lbl">Уникальные</div><div class="kui-an-big" id="anUnique">–</div><span class="kui-delta" id="anUniqueD">—</span></div>
        <div class="kui-an-card"><div class="kui-an-lbl">Конверсия в заказ</div><div class="kui-an-big" id="anConv">–</div><span class="kui-delta" id="anConvD">—</span><div class="kui-an-sub" id="anOrders"></div></div>
    </div>

    <div class="kui-an-card kui-an-chartcard">
        <div class="kui-an-legend"><span><i style="background:var(--accent)"></i>Визиты</span><span><i style="background:#34c759"></i>Уникальные</span></div>
        <div class="kui-an-chart" id="anChart"><div class="kui-an-empty">Загрузка…</div></div>
    </div>
</div>
