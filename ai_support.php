<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

/**
 * Бэкенд ИИ-поддержки студии кастомного дизайна "Kostlim Design"
 * Сборка с расширенным дебагом авторизации API Google.
 */

require_once __DIR__ . '/includes/session.php';

$apiKey = getenv('GEMINI_API_KEY') ?: '';

$systemInstruction = "Ты — официальный ИИ-менеджер поддержки на сайте студии кастомного дизайна \"Kostlim Design\". Отвечай коротко (2-5 предложений), вежливо, используй эмодзи. Помогай по вопросам дизайна, прайса (Превью - 400Р/250грн, Ава - 300Р/175грн) и ЛК. На отвлеченные темы вежливо отказывай.";

// 1. AJAX-запрос конфигурации
if (isset($_GET['get_internal_config_raw'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'key' => $apiKey,
        'system' => $systemInstruction
    ]);
    exit;
}

// 2. Обычный POST-запрос (заглушка)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!empty($input['reset'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'reset' => true]);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'reply' => 'Синхронизация... Нажмите еще раз.']);
    exit;
}

// 3. GET-запрос (скрипт)
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
                    return new Response(JSON.stringify({ ok: false, reply: 'Ключ еще не подгрузился, подождите секунду.' }), { status: 200 });
                }

                let contents = [];
                window.aiChatHistory.forEach(turn => {
                    contents.push({ role: turn.role === 'user' ? 'user' : 'model', parts: [{ text: turn.text }] });
                });
                contents.push({ role: 'user', parts: [{ text: userMessage }] });

                // Запрос к Google
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
                
                // ВЫВОДИМ РЕАЛЬНУЮ ОШИБКУ ОТ GOOGLE ПРЯМО В ЧАТ ДЛЯ ДЕБАГА
                if (geminiData.error) {
                    const errMsg = geminiData.error.message || 'Unknown error';
                    const errStatus = geminiData.error.status || 'UNAUTHORIZED';
                    return new Response(JSON.stringify({ 
                        ok: false, 
                        reply: `Дебаг Google API: [${errStatus}] ${errMsg}. Проверь токен в Render!` 
                    }), { status: 200 });
                }

                const aiReply = geminiData.candidates[0].content.parts[0].text || 'Не удалось получить ответ.';
                window.aiChatHistory.push({ role: 'user', text: userMessage });
                window.aiChatHistory.push({ role: 'model', text: aiReply });

                return new Response(JSON.stringify({ ok: true, reply: aiReply }), {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' }
                });

            } catch (e) {
                return new Response(JSON.stringify({ ok: false, reply: 'Ошибка сети на стороне клиента.' }), { status: 200 });
            }
        }
        return originalFetch.apply(this, args);
    };
})();