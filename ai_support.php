<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/session.php';
$apiKey = getenv('GEMINI_API_KEY') ?: '';
$systemInstruction = "Ты — официальный ИИ-менеджер поддержки Kostlim Design.";

if (isset($_GET['get_internal_config_raw'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'key' => $apiKey, 'system' => $systemInstruction]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'reply' => 'Синхронизация...']);
    exit;
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
        .then(data => { if (data.ok) geminiConfig = data; });

    window.fetch = async function(...args) {
        const url = args[0];
        const options = args[1];

        if (typeof url === 'string' && url.includes('ai_support.php') && options && options.method === 'POST') {
            try {
                const bodyData = JSON.parse(options.body || '{}');
                const userMessage = bodyData.message || '';

                if (!geminiConfig || !geminiConfig.key) {
                    return new Response(JSON.stringify({ ok: false, reply: 'Ожидайте ключа...' }), { status: 200 });
                }

                let contents = [{ role: 'user', parts: [{ text: userMessage }] }];

                // Стучимся в v1
                const geminiResponse = await originalFetch(`https://generativelanguage.googleapis.com/v1/models/gemini-1.5-flash:generateContent?key=${geminiConfig.key}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        contents: contents,
                        systemInstruction: { parts: [{ text: geminiConfig.system }] }
                    })
                });

                const geminiData = await geminiResponse.json();
                
                // ВЫВОДИМ ПОЛНЫЙ ОТВЕТ ОШИБКИ В ЧАТ
                if (geminiData.error) {
                    return new Response(JSON.stringify({ 
                        ok: false, 
                        reply: `ДЕБАГ v1: [${geminiData.error.status}] ${geminiData.error.message}` 
                    }), { status: 200 });
                }

                const aiReply = geminiData.candidates[0].content.parts[0].text;
                return new Response(JSON.stringify({ ok: true, reply: aiReply }), { status: 200 });

            } catch (e) {
                return new Response(JSON.stringify({ ok: false, reply: 'Ошибка от фронтенда.' }), { status: 200 });
            }
        }
        return originalFetch.apply(this, args);
    };
})();