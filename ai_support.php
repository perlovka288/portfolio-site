<?php
/**
 * Бэкенд ИИ-поддержки студии "Kostlim Design"
 * Ультимативное решение: Перехват запросов (Monkey Patching) на фронтенде.
 * Полностью обходит ЛЮБЫЕ сетевые ограничения хостинга Render.
 */

// Переменные окружения
$apiKey = getenv('GEMINI_API_KEY') ?: '';

// Если это обычный POST-запрос, который все-таки долетел до бэкенда (на всякий случай)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    
    // Если фронт просит просто очистить историю
    if (!empty($input['reset'])) {
        echo json_encode(['ok' => true]);
        exit;
    }

    // Если сеть на Render легла намертво, этот фоллбэк сработает только если подмена JS не успела
    echo json_encode([
        'ok' => false, 
        'reply' => 'ИИ перезагружается, отправьте сообщение еще раз через секунду!'
    ]);
    exit;
}

// Промпт для ИИ
$systemInstruction = "Ты — официальный ИИ-менеджер поддержки на сайте студии кастомного дизайна \"Kostlim Design\". Отвечай коротко (2-5 предложений), вежливо, используй эмодзи. Помогай только по вопросам дизайна, прайса (Превью - 400Р/250грн, Ава - 300Р/175грн, Оформление YT - 500Р/400грн) и личного кабинета. На отвлеченные темы и помощь с учебой вежливо отказывай.";

// А вот теперь магия. Если чат загружается, мы незаметно вшиваем в страницу скрипт-перехватчик!
?>
<script>
(function() {
    // Запоминаем оригинальный fetch браузера
    const originalFetch = window.fetch;
    const geminiKey = <?php echo json_encode($apiKey); ?>;
    const sysPrompt = <?php echo json_encode($systemInstruction); ?>;
    
    // Локальная история диалога прямо в браузере юзера
    if (!window.aiChatHistory) {
        window.aiChatHistory = [];
    }

    // Подменяем fetch, чтобы перехватить запрос к ai_support.php
    window.fetch = async function(...args) {
        const url = args[0];
        const options = args[1];

        // Если скрипт чата пытается постучаться на наш заблокированный бэкенд
        if (typeof url === 'string' && url.includes('ai_support.php') && options && options.method === 'POST') {
            try {
                const bodyData = JSON.parse(options.body || '{}');
                
                // Если это запрос на сброс истории
                if (bodyData.reset) {
                    window.aiChatHistory = [];
                    return new Response(JSON.stringify({ ok: true, reset: true }), {
                        status: 200,
                        headers: { 'Content-Type': 'application/json' }
                    });
                }

                const userMessage = bodyData.message || '';
                if (!userMessage) {
                    return new Response(JSON.stringify({ ok: false, reply: 'Введите сообщение...' }), { status: 200 });
                }

                // Формируем историю для Google Gemini
                let contents = [];
                window.aiChatHistory.forEach(turn => {
                    contents.push({
                        role: turn.role === 'user' ? 'user' : 'model',
                        parts: [{ text: turn.text }]
                    });
                });
                contents.push({ role: 'user', parts: [{ text: userMessage }] });

                // Отправляем запрос напрямую из браузера клиента на домен Google (CORS разрешен!)
                const geminiResponse = await originalFetch(`https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=${geminiKey}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        contents: contents,
                        systemInstruction: { parts: [{ text: sysPrompt }] },
                        generationConfig: { temperature: 0.7, maxOutputTokens: 400 }
                    })
                });

                const geminiData = await geminiResponse.json();
                
                if (geminiData.error) {
                    return new Response(JSON.stringify({ ok: false, reply: 'Ошибка авторизации ключа Google.' }), { status: 200 });
                }

                const aiReply = geminiData.candidates[0].content.parts[0].text || 'Не удалось получить ответ.';

                // Сохраняем в локальную историю диалога
                window.aiChatHistory.push({ role: 'user', text: userMessage });
                window.aiChatHistory.push({ role: 'model', text: aiReply });

                // Возвращаем ответ в старом формате, который ожидает твой JS-код чата!
                return new Response(JSON.stringify({ ok: true, reply: aiReply }), {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' }
                });

            } catch (e) {
                console.error('Ошибка перехвата:', e);
                return new Response(JSON.stringify({ ok: false, reply: 'Ошибка соединения с ИИ.' }), { status: 200 });
            }
        }

        // Все остальные запросы сайта пропускаем без изменений
        return originalFetch.apply(this, args);
    };
})();
</script>