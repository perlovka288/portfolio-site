<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

/**
 * Бэкенд + Фронтенд ИИ-поддержки студии "Kostlim Design"
 * Перенос сетевой нагрузки на фронтенд (в обход всех сетевых блокировок Render)
 */

require_once __DIR__ . '/includes/session.php';

// Твой API ключ Gemini из настроек Render (переменная GEMINI_API_KEY)
$apiKey = getenv('GEMINI_API_KEY') ?: '';

// Системный промпт студии
$systemInstruction = <<<'PROMPT'
Ты — официальный ИИ-менеджер поддержки на сайте студии кастомного дизайна "Kostlim Design". Ты приветствуешь пользователя, представляешься как онлайн-консультант и помогаешь во всех вопросах, связанных со студией.

КАТЕГОРИЧЕСКИ ЗАПРЕЩЕНО:
- Помогать с учебой, делать домашние задания, писать код, рефераты, решать уравнения или общаться на отвлеченные темы. Если клиент пытается использовать тебя как обычный ChatGPT, вежливо откажи и верни его к теме дизайна: "Я — менеджер поддержки Kostlim Design, и помогаю только по вопросам заказов и работы нашего сайта. Чем я могу помочь тебе по поводу дизайна? 🎨".

АКТУАЛЬНЫЙ ПРАЙС-ЛИСТ И УСЛОВИЯ:
- Превью для видео и стримов (YouTube): 400 ₽ / 250 ₴. Включает 5 бесплатных правок.
- Именная аватарка: 300 ₽ / 175 ₴. Аватарка с именем, персонажем или брендингом. Включает 5 бесплатных правок.
- Оформление для YouTube: 500 ₽ / 400 ₴. Комплект: шапка канала + аватарка. Включает 5 бесплатных правок.
- Оформление для VK: 400 ₽ / 300 ₴. Комплект: шапка страницы, аватарка, иконки товаров/услуг. Включает 5 бесплатных правок.
- Баннер для постов: 250 ₽ / 150 ₴. Баннеры для розыгрышей, конкурсов или анонсов. Включает 5 бесплатных правок.
- Мой приватный пак (для дизайнеров): 1000 ₽ / 750 ₴. Набор материалов: кисти, стили, градиенты, PSD-файлы готовых работ + подробный туториал по установке Stable Diffusion на любой ПК (независимо от видеокарты).

КАК РАБОТАЕТ САЙТ И ЛИЧНЫЙ КАБИНЕТ:
1. Авторизация и привязка: Клиент должен привязать свой Telegram к сайту. В профиле нужно скопировать персональный код (/customer_XXXXXX) и отправить его в наш Telegram-бот @kostlimdznbot. Это нужно для получения уведомлений о заказе.
2. Оформление ТЗ: В меню "Новый заказ" клиент заполняет форму и может прикрепить референсы.
3. Одобрение и оплата: Когда дизайнер берет заказ, в личном кабинете появляются реквизиты. Клиент должен оплатить заказ и загрузить скриншот чека прямо в раскрытую карточку этого заказа в своем профиле (или прислать чек прямо в Telegram-бот). Срок выполнения и дедлайн начинаются ТОЛЬКО ПОСЛЕ загрузки чека.
4. Получение работы: Как только статус изменится на "Готов", в личном кабинете появится уведомление. Там же можно нажать кнопку "Заказать снова".

КОНТАКТЫ ДЛЯ СВЯЗИ:
- Если у клиента сложная проблема, баг на сайте, вопросы по возврату денег — отправляй к реальному создателю студии в Telegram: @Perlo_ovka.

СТИЛЬ ОБЩЕНИЯ:
- Отвечай на языке пользователя (русский или украинский). Общайся вежливо, уверенно, в меру дружелюбно, современным неформальным тоном, но не переигрывай. Ответы короткие и по делу (2-5 предложений), используй эмодзи умеренно.
PROMPT;

// Если фронтенд запрашивает конфигурацию
if (isset($_GET['get_config'])) {
    header('Content-Type: application/json; charset=utf-8');
    if ($apiKey === '') {
        echo json_encode(['ok' => false, 'error' => 'no_api_key']);
        exit;
    }
    
    if (!isset($_SESSION['ai_chat_history']) || !is_array($_SESSION['ai_chat_history'])) {
        $_SESSION['ai_chat_history'] = [];
    }
    
    echo json_encode([
        'ok' => true,
        'key' => $apiKey,
        'prompt' => $systemInstruction,
        'history' => $_SESSION['ai_chat_history']
    ]);
    exit;
}

// Сохранение реплик в сессию
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    
    if (!empty($input['reset'])) {
        $_SESSION['ai_chat_history'] = [];
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }
    
    if (isset($input['user_msg']) && isset($input['ai_reply'])) {
        if (!isset($_SESSION['ai_chat_history'])) $_SESSION['ai_chat_history'] = [];
        $_SESSION['ai_chat_history'][] = ['role' => 'user', 'text' => $input['user_msg']];
        $_SESSION['ai_chat_history'][] = ['role' => 'model', 'text' => $input['ai_reply']];
        if (count($_SESSION['ai_chat_history']) > 20) {
            $_SESSION['ai_chat_history'] = array_slice($_SESSION['ai_chat_history'], -20);
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Онлайн ИИ-консультант Kostlim Design</title>
    <style>
        body { background: #0e0e10; color: #efeff1; font-family: sans-serif; margin: 0; padding: 20px; display: flex; justify-content: center; }
        .chat-container { width: 100%; max-width: 500px; background: #18181b; border-radius: 12px; border: 1px solid #2f2f35; display: flex; flex-direction: column; height: 500px; overflow: hidden; }
        .chat-header { background: #1f1f23; padding: 15px; border-bottom: 1px solid #2f2f35; display: flex; justify-content: space-between; align-items: center; }
        .status-dot { width: 8px; height: 8px; background: #00f2fe; border-radius: 50%; display: inline-block; margin-right: 6px; }
        .chat-messages { flex: 1; padding: 15px; overflow-y: auto; display: flex; flex-direction: column; gap: 10px; }
        .msg { max-width: 80%; padding: 10px 14px; border-radius: 8px; font-size: 14px; line-height: 1.4; white-space: pre-line; }
        .msg.bot { background: #2f2f35; align-self: flex-start; }
        .msg.user { background: #c87a19; color: #fff; align-self: flex-end; }
        .chat-input-area { display: flex; border-top: 1px solid #2f2f35; background: #1f1f23; }
        .chat-input { flex: 1; background: transparent; border: none; padding: 15px; color: #fff; outline: none; font-size: 14px; }
        .chat-btn { background: #c87a19; border: none; color: white; padding: 0 20px; cursor: pointer; font-weight: bold; transition: 0.2s; }
        .chat-btn:hover { background: #a66312; }
        .chat-btn:disabled { background: #444; cursor: not-allowed; }
    </style>
</head>
<body>

<div class="chat-container">
    <div class="chat-header">
        <div><span class="status-dot"></span>Онлайн-консультант</div>
        <button onclick="resetChat()" style="background:transparent; border:none; color:#aaa; cursor:pointer; font-size:12px;">🔄 Очистить</button>
    </div>
    <div class="chat-messages" id="chatWindow">
        <div class="msg bot">Привет! Я ИИ-помощник Kostlim Design ⚡ Отвечу на вопросы по заказам, ценам и сайту. Чем помочь?</div>
    </div>
    <div class="chat-input-area">
        <input type="text" id="userInput" class="chat-input" placeholder="Введите сообщение..." onkeydown="if(event.key==='Enter') sendMsg()">
        <button id="sendBtn" class="chat-btn" onclick="sendMsg()">Отправить</button>
    </div>
</div>

<script>
const chatWindow = document.getElementById('chatWindow');
const userInput = document.getElementById('userInput');
const sendBtn = document.getElementById('sendBtn');

function appendMessage(role, text) {
    const msgDiv = document.createElement('div');
    msgDiv.className = `msg ${role}`;
    msgDiv.innerText = text;
    chatWindow.appendChild(msgDiv);
    chatWindow.scrollTop = chatWindow.scrollHeight;
}

async function sendMsg() {
    const text = userInput.value.trim();
    if (!text) return;

    appendMessage('user', text);
    userInput.value = '';
    userInput.disabled = true;
    sendBtn.disabled = true;

    const loadingDiv = document.createElement('div');
    loadingDiv.className = 'msg bot';
    loadingDiv.innerText = 'Печатает...';
    chatWindow.appendChild(loadingDiv);
    chatWindow.scrollTop = chatWindow.scrollHeight;

    try {
        // 1. Берем ключ и промпт с бэкенда
        const configResp = await fetch('?get_config=1');
        const config = await configResp.json();
        
        if (!config.ok) {
            loadingDiv.innerText = "ИИ-помощник временно недоступен. Напиши нам напрямую: @Perlo_ovka";
            return;
        }

        // 2. Формируем историю в формате Google Gemini API
        let contents = [];
        if (config.history && config.history.length > 0) {
            config.history.forEach(turn => {
                contents.push({
                    role: turn.role === 'user' ? 'user' : 'model',
                    parts: [{ text: turn.text }]
                });
            });
        }
        contents.push({
            role: 'user',
            parts: [{ text: text }]
        });

        // 3. Отправляем запрос прямо из браузера пользователя на нормальный домен Google!
        const response = await fetch(`https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=${config.key}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                contents: contents,
                systemInstruction: {
                    parts: [{ text: config.prompt }]
                },
                generationConfig: {
                    temperature: 0.7,
                    maxOutputTokens: 600
                }
            })
        });

        const geminiData = await response.json();
        
        if (geminiData.error) {
            loadingDiv.innerText = "Ошибка авторизации ИИ. Пожалуйста, проверь правильность ключа в админке.";
            return;
        }

        const reply = geminiData.candidates[0].content.parts[0].text;
        loadingDiv.innerText = reply;

        // 4. Синхронизируем историю с сессией PHP
        await fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user_msg: text, ai_reply: reply })
        });

    } catch (err) {
        console.error("Ошибка фронта:", err);
        loadingDiv.innerText = "Не удалось получить ответ 😔 Пожалуйста, попробуй ещё раз.";
    } finally {
        userInput.disabled = false;
        sendBtn.disabled = false;
        userInput.focus();
        chatWindow.scrollTop = chatWindow.scrollHeight;
    }
}

async function resetChat() {
    await fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ reset: true })
    });
    chatWindow.innerHTML = '<div class="msg bot">Привет! Я ИИ-помощник Kostlim Design ⚡ История сброшена. Чем могу помочь?</div>';
}
</script>

</body>
</html>