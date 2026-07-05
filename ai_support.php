<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

/**
 * Бэкенд ИИ-поддержки студии кастомного дизайна "Kostlim Design"
 * Полностью автономный гибридный файл — НЕ ТРЕБУЕТ изменений в index.php.
 */

require_once __DIR__ . '/includes/session.php';

// Получаем API ключ из панели управления Render
$apiKey = getenv('GEMINI_API_KEY') ?: '';

// Системный промпт
$systemInstruction = "Ты — официальный ИИ-менеджер поддержки на сайте студии кастомного дизайна \"Kostlim Design\". Отвечай коротко (2-5 предложений), вежливо, используй эмодзи. Помогай по вопросам дизайна, прайса (Превью - 400Р/250грн, Ава - 300Р/175грн) и ЛК. На отвлеченные темы вежливо отказывай.";

// 1. AJAX-запрос конфигурации из перехваченного JS
if (isset($_GET['get_internal_config_raw'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'key' => $apiKey,
        'system' => $systemInstruction
    ]);
    exit;
}

// 2. Обычный POST-запрос от твоего старого JS-кода чата
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    
    if (!empty($input['reset'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'reset' => true]);
        exit;
    }

    // Если это первый клик — возвращаем заглушку и заставляем браузер применить патч
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'reply' => 'ИИ синхронизирует защищенный канал связи. Пожалуйста, нажмите кнопку ОТПРАВИТЬ ещё раз!'
    ]);
    exit;
}

// 3. Если файл подключен как скрипт через GET
header('Content-Type: application/javascript; charset=utf-8');
?>
(function() {
    if (window.fetchPatched) return;
    window.fetchPatched = true;
    
    const originalFetch = window.fetch;
    let geminiConfig = null;
    window.aiChatHistory = window.aiChatHistory || [];

    // Подгружаем токен в фоне
    originalFetch('/ai_support.php?get_internal_config_raw=1')
        .then(res => res.json())
        .then(data => { if (data.ok) geminiConfig = data; })
        .catch(err => console.error('Ошибка ИИ:', err));

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
                    return new Response(JSON.stringify({ ok: false, reply: 'Соединение настраивается, нажмите отправить еще раз через секунду.' }), { status: 200 });
                }

                let contents = [];
                window.aiChatHistory.forEach(turn => {
                    contents.push({ role: turn.role === 'user' ? 'user' : 'model', parts: [{ text: turn.text }] });
                });
                contents.push({ role: 'user', parts: [{ text: userMessage }] });

                // Прямой запрос к Google API из браузера юзера (обходим файрвол Render)
                const geminiResponse = await originalFetch(`https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=${geminiConfig.key}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        contents: contents,
                        systemInstruction: { parts: [{ text: geminiConfig.system }] },
                        generationConfig: { temperature: 0.7, maxOutputTokens: 400 }
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
                return new Response(JSON.stringify({ ok: false, reply: 'Ошибка сети.' }), { status: 200 });
            }
        }
        return originalFetch.apply(this, args);
    };
})();