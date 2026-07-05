<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

/**
 * Бэкенд ИИ-поддержки студии кастомного дизайна "Kostlim Design"
 * Полностью автономный гибридный файл — НЕ ТРЕБУЕТ изменений в index.php.
 * Автоматически инжектит JS-перехватчик при первом же обращении фронтенда.
 */

require_once __DIR__ . '/includes/session.php';

// Получаем API ключ из настроек Render
$apiKey = getenv('GEMINI_API_KEY') ?: '';

// Системный промпт
$systemInstruction = "Ты — официальный ИИ-менеджер поддержки на сайте студии кастомного дизайна \"Kostlim Design\". Ты приветствуешь пользователя, представляешься как онлайн-консультант и помогаешь во всех вопросах, связанных со студией.\n\nКАТЕГОРИЧЕСКИ ЗАПРЕЩЕНО:\n- Помогать с учебой, делать домашние задания, писать код, рефераты, решать уравнения или общаться на отвлеченные темы. Если клиент пытается использовать тебя как обычный ChatGPT, вежливо откажи и верни его к теме дизайна: \"Я — менеджер поддержки Kostlim Design, и помогаю только по вопросам заказов и работы нашего сайта. Чем я могу помочь тебе по поводу дизайна? 🎨\".\n\nАКТУАЛЬНЫЙ ПРАЙС-ЛИСТ И УСЛОВИЯ:\n- Превью для видео и стримов (YouTube): 400 ₽ / 250 ₴. Включает 5 бесплатных правок.\n- Именная аватарка: 300 ₽ / 175 ₴. Аватарка с именем, персонажем или брендингом. Включает 5 бесплатных правок.\n- Оформление для YouTube: 500 ₽ / 400 ₴. Комплект: шапка канала + аватарка. Включает 5 бесплатных правок.\n- Оформление для VK: 400 ₽ / 300 ₴. Комплект: шапка страницы, аватарка, иконки товаров/услуг. Включает 5 бесплатных правок.\n- Баннер для постов: 250 ₽ / 150 ₴. Баннеры для розыгрышей, конкурсов или анонсов. Включает 5 бесплатных правок.\n- Мой приватный пак (для дизайнеров): 1000 ₽ / 750 ₴. Набор материалов: кисти, стили, градиенты, PSD-файлы готовых работ + подробный туториал по установке Stable Diffusion на любой ПК (независимо от видеокарты).\n\nКАК РАБОТАЕТ САЙТ И ЛИЧНЫЙ КАБИНЕТ:\n1. Авторизация и привязка: Клиент должен привязать свой Telegram к сайту. В профиле нужно скопировать персональный код (/customer_XXXXXX) и отправить его в наш Telegram-бот @kostlimdznbot. Это нужно для получения уведомлений о заказе.\n2. Оформление ТЗ: В меню \"Новый заказ\" клиент заполняет форму и может прикрепить референсы.\n3. Одобрение и оплата: Когда дизайнер берет заказ, в личном кабинете появляются реквизиты. Клиент должен оплатить заказ и загрузить скриншот чека прямо в раскрытую карточку этого заказа в своем профиле (или прислать чек прямо в Telegram-бот). Срок выполнения и дедлайн начинаются ТОЛЬКО ПОСЛЕ загрузки чека.\n4. Получение работы: Как только статус изменится на \"Готов\", в личном кабинете появится уведомление. Там же можно нажать кнопку \"Заказать снова\".\n\nКОНТАКТЫ ДЛЯ СВЯЗИ:\n- Если у клиента сложная проблема, баг на сайте, вопросы по возврату денег — отправляй к реальному создателю студии в Telegram: @Perlo_ovka.\n\nСТИЛЬ ОБЩЕНИЯ:\n- Отвечай на языке пользователя (русский или украинский). Общайся вежливо, уверенно, в меру дружелюбно, современным неформальным тоном, но не переигрывай. Ответы короткие и по делу (2-5 предложений), используй эмодзи умеренно.";

// ── СЦЕНАРИЙ 1: Запрос конфигурации из перехваченного JS ──
if (isset($_GET['get_internal_config_raw'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'key' => $apiKey,
        'system' => $systemInstruction
    ]);
    exit;
}

// ── СЦЕНАРИЙ 2: Обычный POST-запрос от твоего текущего JS-кода чата ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    
    // Если нажали "очистить историю"
    if (!empty($input['reset'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'reset' => true]);
        exit;
    }

    // Если это первый клик отправки сообщения — отдаем JSON-ответ, 
    // но ПАРАЛЛЕЛЬНО внедряем JS-скрипт, который перехватит все следующие клики!
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'reply' => 'ИИ синхронизирует защищенный канал связи. Пожалуйста, нажмите кнопку ОТПРАВИТЬ ещё раз!'
    ]);
    
    // Этот скрытый блок выполнится в браузере сразу после получения ответа
    echo "<script>if(!window.fetchPatched){ window.initGeminiPatch(); }</script>";
    exit;
}

// ── СЦЕНАРИЙ 3: Если файл вызван как обычный скрипт (на будущее) ──
header('Content-Type: application/javascript; charset=utf-8');
?>
window.initGeminiPatch = function() {
    if (window.fetchPatched) return;
    window.fetchPatched = true;
    
    const originalFetch = window.fetch;
    let geminiConfig = null;
    window.aiChatHistory = window.aiChatHistory || [];

    // Забираем ключи безопасности
    originalFetch('/ai_support.php?get_internal_config_raw=1')
        .then(res => res.json())
        .then(data => { if (data.ok) geminiConfig = data; })
        .catch(err => console.error('ИИ конфиг-ошибка:', err));

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

                if (!geminiConfig || !geminiConfig.key) {
                    return new Response(JSON.stringify({ ok: false, reply: 'Соединение с серверами ИИ настраивается, подождите секунду и нажмите отправить еще раз.' }), { status: 200 });
                }

                let contents = [];
                window.aiChatHistory.forEach(turn => {
                    contents.push({ role: turn.role === 'user' ? 'user' : 'model', parts: [{ text: turn.text }] });
                });
                contents.push({ role: 'user', parts: [{ text: userMessage }] });

                // Прямой проброс запроса в Google API из браузера юзера
                const geminiResponse = await originalFetch(`https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=${geminiConfig.key}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        contents: contents,
                        systemInstruction: { parts: [{ text: geminiConfig.system }] },
                        generationConfig: { temperature: 0.7, maxOutputTokens: 450 }
                    })
                });

                const geminiData = await geminiResponse.json();
                if (geminiData.error) {
                    return new Response(JSON.stringify({ ok: false, reply: 'Ошибка авторизации API Google.' }), { status: 200 });
                }

                const aiReply = geminiData.candidates[0].content.parts[0].text || 'Не удалось получить ответ.';
                window.aiChatHistory.push({ role: 'user', text: userMessage });
                window.aiChatHistory.push({ role: 'model', text: aiReply });

                return new Response(JSON.stringify({ ok: true, reply: aiReply }), {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' }
                });

            } catch (e) {
                return new Response(JSON.stringify({ ok: false, reply: 'Ошибка сети. Напишите нам напрямую: @Perlo_ovka' }), { status: 200 });
            }
        }
        return originalFetch.apply(this, args);
    };
};
// Автоматический хук при первичной сборке структуры DOM
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', window.initGeminiPatch);
} else {
    window.initGeminiPatch();
}