<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

/**
 * Бэкенд ИИ-поддержки студии кастомного дизайна "Kostlim Design"
 * Работает через прокси-маршрутизацию Google API для обхода ограничений Render.
 */

require_once __DIR__ . '/includes/session.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

// Сброс истории по требованию старого фронтенда
if (!empty($input['reset'])) {
    $_SESSION['ai_chat_history'] = [];
    echo json_encode(['ok' => true, 'reset' => true]);
    exit;
}

// Старый фронтенд шлет ключ 'message'
$userMessage = trim((string)($input['message'] ?? ''));
if ($userMessage === '') {
    echo json_encode(['ok' => false, 'error' => 'empty_message', 'reply' => 'Введите сообщение...']);
    exit;
}

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
4. Получение работы: Как статус изменится на "Готов", в личном кабинете появится уведомление. Там же можно нажать кнопку "Заказать снова".

КОНТАКТЫ ДЛЯ СВЯЗИ:
- Если у клиента сложная проблема, баг на сайте, вопросы по возврату денег — отправляй к реальному создателю студии в Telegram: @Perlo_ovka.

СТИЛЬ ОБЩЕНИЯ:
- Отвечай на языке пользователя (русский или украинский). Общайся вежливо, уверенно, в меру дружелюбно, современным неформальным тоном, но не переигрывай. Ответы короткие и по делу (2-5 предложений), используй эмодзи умеренно.
PROMPT;

if (!isset($_SESSION['ai_chat_history']) || !is_array($_SESSION['ai_chat_history'])) {
    $_SESSION['ai_chat_history'] = [];
}

$contents = [];
foreach ($_SESSION['ai_chat_history'] as $turn) {
    $contents[] = [
        'role' => $turn['role'] === 'user' ? 'user' : 'model',
        'parts' => [['text' => $turn['text']]]
    ];
}
$contents[] = [
    'role' => 'user',
    'parts' => [['text' => $userMessage]]
];

$payload = [
    'contents' => $contents,
    'systemInstruction' => [
        'parts' => [['text' => $systemInstruction]]
    ],
    'generationConfig' => [
        'temperature' => 0.7,
        'maxOutputTokens' => 500
    ]
];

// Используем публичный CORS/HTTP прокси для гарантированного обхода DNS-проблем Render
$proxyUrl = "https://corsproxy.io/?" . urlencode("https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $apiKey);

try {
    $ch = curl_init($proxyUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    $data = json_decode((string)$resp, true);
    
    if (isset($data['error'])) {
        echo json_encode(['ok' => false, 'reply' => 'Ошибка ключа или лимитов Google API.']);
        exit;
    }

    $reply = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

    if ($reply === '') {
        echo json_encode(['ok' => false, 'reply' => 'Сервер временно перегружен, попробуй еще раз!']);
        exit;
    }

    $_SESSION['ai_chat_history'][] = ['role' => 'user', 'text' => $userMessage];
    $_SESSION['ai_chat_history'][] = ['role' => 'assistant', 'text' => $reply];

    // Формат ответа под твой старый JS-код
    echo json_encode(['ok' => true, 'reply' => $reply]);

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'reply' => 'Не удалось связаться с ИИ. Попробуй позже.']);
}