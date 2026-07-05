<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

/**
 * Бэкенд ИИ-поддержки студии кастомного дизайна "Kostlim Design"
 * Система ротации ключей + Очищенный текстовый формат без сырых тегов и Markdown
 */

require_once __DIR__ . '/includes/session.php';

// Получаем строку с ключами из Render ("ключ1,ключ2,ключ3")
$apiKeysRaw = getenv('GEMINI_API_KEY') ?: '';

// Жесткий системный промпт с правилами чистого текста
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

// 1. Отдаем конфигурацию со списком ключей в JS
if (isset($_GET['get_internal_config_raw'])) {
    header('Content-Type: application/json; charset=utf-8');
    $keysArray = array_filter(array_map('trim', explode(',', $apiKeysRaw)));
    echo json_encode([
        'ok' => true,
        'keys' => array_values($keysArray),
        'system' => $systemInstruction
    ]);
    exit;
}

// 2. Заглушка под POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!empty($input['reset'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'reset' => true]);
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
    window.aiChatHistory = window.aiChatHistory || [];

    originalFetch('/ai_support.php?get_internal_config_raw=1')
        .then(res => res.json())
        .then(data => { if (data.ok) geminiConfig = data; })
        .catch(err => console.error('Ошибка пула ИИ:', err));

    window.fetch = async function(...args) {
        const url = args[0];
        const options = args[1];

        if (typeof url === 'string' && url.includes('ai_support.php') && options && options.method === 'POST') {
            try {
                const bodyData = JSON.parse(options.body || '{}');
                
                if (bodyData.reset) {
                    window.aiChatHistory = [];
                    return new Response(JSON.stringify({ ok: true, reset: true }), { status: 200 });
                }

                const userMessage = bodyData.message || '';
                if (!userMessage.trim()) {
                    return new Response(JSON.stringify({ ok: false, reply: 'Введите сообщение...' }), { status: 200 });
                }

                if (!geminiConfig || !geminiConfig.keys || geminiConfig.keys.length === 0) {
                    return new Response(JSON.stringify({ ok: false, reply: 'ИИ настраивает пул ключей, повторите отправку.' }), { status: 200 });
                }

                const randomIndex = Math.floor(Math.random() * geminiConfig.keys.length);
                const activeKey = geminiConfig.keys[randomIndex];

                let contents = [];
                window.aiChatHistory.forEach(turn => {
                    contents.push({ role: turn.role === 'user' ? 'user' : 'model', parts: [{ text: turn.text }] });
                });
                contents.push({ role: 'user', parts: [{ text: userMessage }] });

                const geminiResponse = await originalFetch(`https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=${activeKey}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        contents: contents,
                        systemInstruction: { 
                            parts: [{ text: geminiConfig.system }] 
                        },
                        generationConfig: { 
                            temperature: 0.4, 
                            maxOutputTokens: 450 
                        }
                    })
                });

                const geminiData = await geminiResponse.json();
                
                if (geminiData.error) {
                    return new Response(JSON.stringify({ 
                        ok: false, 
                        reply: `Ошибка пула [${geminiData.error.code}]: ${geminiData.error.message}. Попробуйте еще раз.` 
                    }), { status: 200 });
                }

                const rawAiReply = geminiData.candidates[0].content.parts[0].text || 'Не удалось получить ответ.';
                
                window.aiChatHistory.push({ role: 'user', text: userMessage });
                window.aiChatHistory.push({ role: 'model', text: rawAiReply });

                return new Response(JSON.stringify({ ok: true, reply: rawAiReply }), {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' }
                });

            } catch (e) {
                return new Response(JSON.stringify({ ok: false, reply: 'Ошибка сети. Напишите создателю: https://t.me/Perlo_ovka' }), { status: 200 });
            }
        }
        return originalFetch.apply(this, args);
    };
})();