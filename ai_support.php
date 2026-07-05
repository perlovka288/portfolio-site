<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/session.php';

$apiKeysRaw = getenv('GEMINI_API_KEY') ?: '';

// Функция уведомления в Telegram (мониторинг лимитов)
function notifyAdmin($message) {
    $token = getenv('BOT_TOKEN');
    $adminId = getenv('ADMIN_ID');
    if ($token && $adminId) {
        $flagFile = sys_get_temp_dir() . '/ai_limit_notify.log';
        if (file_exists($flagFile) && (time() - filemtime($flagFile) < 600)) return;
        touch($flagFile);
        $url = "https://api.telegram.org/bot$token/sendMessage?chat_id=$adminId&text=" . urlencode("🤖 [Kostlim AI Alert]: " . $message);
        @file_get_contents($url);
    }
}

$systemInstruction = "Ты — крутой, живой и отзывчивый ИИ-консультант студии дизайна \"Kostlim Design\". Твоя цель — помогать клиентам и принимать заказы. Общайся как реальный человек, твой друг-дизайнер: легко, уверенно, без занудства и канцелярита.

ГЛАВНОЕ ПРАВИЛО:
- Никогда не представляйся как \"менеджер поддержки\" в каждом сообщении. Ты — это ты. Общайся свободно.
- Если юзер кидает скриншот (ошибку, чек или референс) — посмотри на него и дай четкий комментарий.
- Если просят решить задачу/код — легко переводи тему на дизайн (\"Давай лучше оформим что-нибудь крутое!\").

АКТУАЛЬНЫЙ ПРАЙС И СКИДКА:
- Действует АКЦИЯ: промокод KOSTLIMFIRST дает скидку 15% на ЛЮБОЙ заказ. 
- Важно: код работает ТОЛЬКО НА ПЕРВЫЙ ЗАКАЗ на нашем сайте. 
- Указать промокод нужно в форме ТЗ при оформлении заказа. Если клиент спрашивает цену — сразу предложи посчитать её со скидкой 15% (называй итоговую цифру).

ПРАЙС-ЛИСТ (Базовый):
- Превью (YouTube): 400 ₽ / 250 ₴
- Аватарка: 300 ₽ / 175 ₴
- Оформление YouTube: 500 ₽ / 400 ₴
- Оформление VK: 400 ₽ / 300 ₴
- Баннер для постов: 250 ₽ / 150 ₴
- Приватный пак (с тутором по Stable Diffusion): 1000 ₽ / 750 ₴

КАК ЗАКАЗАТЬ:
- Сделать заказ можно кнопкой «Заказать» под примером работы или через личный кабинет. 
- Нужно принять «Правила заказа», заполнить форму ТЗ (с промокодом, если есть) и отправить её. После этого придет уведомление в Telegram. 
- Когда дизайнер примет заказ, придут реквизиты. Чек об оплате можно скинуть прямо в карточку заказа на сайте или в личку Telegram. 
- Дедлайн (5 дней или 24 часа для срочных +50%) запускается ТОЛЬКО после получения оплаты. 
- Готовую работу дизайнер пришлет лично в ЛС, там же можно обсудить правки.

СТАТУС И КОНТАКТЫ:
- Сайт в ТЕСТОВОМ режиме. Если что-то не так — не паникуй, пиши создателю: https://t.me/Perlo_ovka
- Наш Telegram-канал с работами: https://t.me/designkostlim

ЖЕСТКИЕ ПРАВИЛА ТЕКСТА:
- НИКАКОГО Markdown (звездочек, скобок).
- Ссылки пиши просто текстом (https://t.me/designkostlim).
- Списки делай через дефисы (-) или цифры.
- Ответы короткие, живые, с эмодзи.";

// Обработка конфигурации
if (isset($_GET['get_internal_config_raw'])) {
    header('Content-Type: application/json; charset=utf-8');
    $keysArray = array_filter(array_map('trim', explode(',', $apiKeysRaw)));
    echo json_encode(['ok' => true, 'keys' => array_values($keysArray), 'system' => $systemInstruction]);
    exit;
}

// Обработка POST-запросов (мониторинг лимитов)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!empty($input['action']) && $input['action'] === 'log_limit_warning') {
        notifyAdmin("Заканчиваются лимиты! Осталось запросов: " . ($input['remaining'] ?? 'мало'));
        echo json_encode(['ok' => true]);
        exit;
    }
}

header('Content-Type: application/javascript; charset=utf-8');
?>
(function() {
    if (window.fetchPatched) return;
    window.fetchPatched = true;
    const originalFetch = window.fetch;
    let geminiConfig = null;
    window.aiChatHistory = [];

    originalFetch('/ai_support.php?get_internal_config_raw=1')
        .then(res => res.json())
        .then(data => { if (data.ok) geminiConfig = data; });

    window.fetch = async function(...args) {
        const url = args[0], options = args[1];
        if (typeof url === 'string' && url.includes('ai_support.php') && options && options.method === 'POST') {
            const bodyData = JSON.parse(options.body || '{}');
            if (bodyData.action === 'log_limit_warning') return originalFetch.apply(this, args);
            
            const userMessage = bodyData.message || '', userImage = bodyData.image || null;
            if (!geminiConfig) return new Response(JSON.stringify({ ok: false, reply: 'Загрузка...' }), { status: 200 });

            const activeKey = geminiConfig.keys[Math.floor(Math.random() * geminiConfig.keys.length)];
            let contents = window.aiChatHistory.map(h => ({ role: h.role, parts: [{ text: h.text }] }));
            
            let parts = userMessage ? [{ text: userMessage }] : [];
            if (userImage && userImage.includes(';base64,')) {
                parts.push({ inline_data: { mime_type: userImage.split(';base64,')[0].replace('data:', ''), data: userImage.split(';base64,')[1] } });
            }
            contents.push({ role: 'user', parts: parts });

            const res = await originalFetch(`https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=${activeKey}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ contents: contents, systemInstruction: { parts: [{ text: geminiConfig.system }] } })
            });

            // Мониторинг лимитов
            const remaining = res.headers.get('x-ratelimit-remaining-minute');
            if (remaining && parseInt(remaining) < 2) {
                originalFetch('/ai_support.php', { method: 'POST', body: JSON.stringify({ action: 'log_limit_warning', remaining: remaining }) });
            }

            const data = await res.json();
            const reply = data.candidates[0].content.parts[0].text;
            window.aiChatHistory.push({ role: 'user', text: userMessage || '[Картинка]' }, { role: 'model', text: reply });
            return new Response(JSON.stringify({ ok: true, reply: reply }), { status: 200, headers: {'Content-Type': 'application/json'} });
        }
        return originalFetch.apply(this, args);
    };
})();