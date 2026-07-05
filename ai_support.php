<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

/**
 * Бэкенд НАСТОЯЩЕГО ИИ-поддержки студии кастомного дизайна "Kostlim Design"
 * Работает через Hugging Face API напрямую по IP, игнорируя проблемы с DNS и SSL.
 */

require_once __DIR__ . '/includes/session.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

// 🗑️ Сброс истории диалога
if (!empty($input['reset'])) {
    $_SESSION['ai_chat_history'] = [];
    echo json_encode(['ok' => true, 'reset' => true]);
    exit;
}

$userMessage = trim((string)($input['message'] ?? ''));
if ($userMessage === '') {
    echo json_encode(['ok' => false, 'error' => 'empty_message']);
    exit;
}
if (mb_strlen($userMessage) > 2000) {
    $userMessage = mb_substr($userMessage, 0, 2000);
}

// Твой токен Hugging Face (hf_...) из настроек Render (переменная GEMINI_API_KEY)
$apiKey = getenv('GEMINI_API_KEY') ?: '';
if ($apiKey === '') {
    echo json_encode(['ok' => false, 'error' => 'no_api_key', 'reply' => 'ИИ-помощник временно недоступен. Напиши нам напрямую: @Perlo_ovka']);
    exit;
}

// ── Системный промпт ──────────────────────────────────────────
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

if (!isset($_SESSION['ai_chat_history']) || !is_array($_SESSION['ai_chat_history'])) {
    $_SESSION['ai_chat_history'] = [];
}
if (count($_SESSION['ai_chat_history']) > 20) {
    $_SESSION['ai_chat_history'] = array_slice($_SESSION['ai_chat_history'], -20);
}

$messages = [
    ['role' => 'system', 'content' => $systemInstruction]
];

foreach ($_SESSION['ai_chat_history'] as $turn) {
    $messages[] = ['role' => $turn['role'], 'content' => $turn['text']];
}
$messages[] = ['role' => 'user', 'content' => $userMessage];

$payload = [
    'model' => 'Qwen/Qwen2.5-72B-Instruct',
    'messages' => $messages,
    'parameters' => [
        'max_new_tokens' => 500,
        'temperature' => 0.7,
        'return_full_text' => false
    ]
];

// Используем один из стабильных IP-адресов инфраструктуры Hugging Face / Cloudflare Edge напрямую
$url = "https://172.67.181.146/models/Qwen/Qwen2.5-72B-Instruct/v1/chat/completions";

try {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        // Полностью отключаем валидацию SSL, чтобы Cloudflare не сбрасывал соединение при обращении по IP
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => [
            'Host: api-inference.huggingface.co', // Передаем оригинальный домен в заголовке, чтобы запрос дошел куда нужно
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    $data  = json_decode((string)$resp, true);
    $reply = $data['choices'][0]['message']['content'] ?? '';

    if ($reply === '') {
        error_log('HF Backend error: ' . $err . ' | resp: ' . substr((string)$resp, 0, 500));
        $debugInfo = !empty($resp) ? $resp : 'cURL Error: ' . ($err ?: 'unknown');
        echo json_encode(['ok' => false, 'error' => 'ai_error', 'reply' => 'Дебаг бэкенда: ' . substr((string)$debugInfo, 0, 300)]);
        exit;
    }

    $_SESSION['ai_chat_history'][] = ['role' => 'user', 'text' => $userMessage];
    $_SESSION['ai_chat_history'][] = ['role' => 'assistant', 'text' => $reply];

    echo json_encode(['ok' => true, 'reply' => $reply]);
} catch (Throwable $e) {
    error_log('HF Backend exception: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'exception', 'reply' => 'Не удалось получить ответ 😔 Попробуй ещё раз.']);
}