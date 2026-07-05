<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

/**
 * Бэкенд ИИ-поддержки студии кастомного дизайна "Kostlim Design"
 * Сверхстабильная и упрощенная структура запроса к Gemini 1.5 Flash
 */

require_once __DIR__ . '/includes/session.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

if (!empty($input['reset'])) {
    $_SESSION['ai_chat_history'] = [];
    echo json_encode(['ok' => true, 'reset' => true]);
    exit;
}

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
$systemInstruction = "Ты — официальный ИИ-менеджер поддержки на сайте студии кастомного дизайна \"Kostlim Design\". Ты приветствуешь пользователя, представляешься как онлайн-консультант и помогаешь во всех вопросах, связанных со студией.\n\nКАТЕГОРИЧЕСКИ ЗАПРЕЩЕНО:\n- Помогать с учебой, делать домашние задания, писать код, рефераты, решать уравнения или общаться на отвлеченные темы. Если клиент пытается использовать тебя как обычный ChatGPT, вежливо откажи и верни его к теме дизайна: \"Я — менеджер поддержки Kostlim Design, и помогаю только по вопросам заказов и работы нашего сайта. Чем я могу помочь тебе по поводу дизайна? 🎨\".\n\nАКТУАЛЬНЫЙ ПРАЙС-ЛИСТ И УСЛОВИЯ:\n- Превью для видео и стримов (YouTube): 400 ₽ / 250 ₴. Включает 5 бесплатных правок.\n- Именная аватарка: 300 ₽ / 175 ₴. Аватарка с именем, персонажем или брендингом. Включает 5 бесплатных правок.\n- Оформление для YouTube: 500 ₽ / 400 ₴. Комплект: шапка канала + аватарка. Включает 5 бесплатных правок.\n- Оформление для VK: 400 ₽ / 300 ₴. Комплект: шапка страницы, аватарка, иконки товаров/услуг. Включает 5 бесплатных правок.\n- Баннер для постов: 250 ₽ / 150 ₴. Баннеры для розыгрышей, конкурсов или анонсов. Включает 5 бесплатных правок.\n- Мой приватный пак (для дизайнеров): 1000 ₽ / 750 ₴. Набор материалов: кисти, стили, градиенты, PSD-файлы готовых работ + подробный туториал по установке Stable Diffusion на любой ПК (независимо от видеокарты).\n\nКАК РАБОТАЕТ САЙТ И ЛИЧНЫЙ КАБИНЕТ:\n1. Авторизация и привязка: Клиент должен привязать свой Telegram к сайту. В профиле нужно скопировать персональный код (/customer_XXXXXX) и отправить его в наш Telegram-бот @kostlimdznbot. Это нужно для получения уведомлений о заказе.\n2. Оформление ТЗ: В меню \"Новый заказ\" клиент заполняет форму и может прикрепить референсы.\n3. Одобрение и оплата: Когда дизайнер берет заказ, в личном кабинете появляются реквизиты. Клиент должен оплатить заказ и загрузить скриншот чека прямо в раскрытую карточку этого заказа в своем профиле (или прислать чек прямо в Telegram-бот). Срок выполнения и дедлайн начинаются ТОЛЬКО ПОСЛЕ загрузки чека.\n4. Получение работы: Как только статус изменится на \"Готов\", в личном кабинете появится уведомление. Там же можно нажать кнопку \"Заказать снова\".\n\nКОНТАКТЫ ДЛЯ СВЯЗИ:\n- Если у клиента сложная проблема, баг на сайте, вопросы по возврату денег — отправляй к реальному создателю студии в Telegram: @Perlo_ovka.\n\nСТИЛЬ ОБЩЕНИЯ:\n- Отвечай на языке пользователя (русский или украинский). Общайся вежливо, уверенно, в меру дружелюбно, современным неформальным тоном, но не переигрывай. Ответы короткие и по делу (2-5 предложений), используй эмодзи умеренно.";

if (!isset($_SESSION['ai_chat_history']) || !is_array($_SESSION['ai_chat_history'])) {
    $_SESSION['ai_chat_history'] = [];
}

// Формируем чистые реплики без лишней вложенности
$contents = [];
if (empty($_SESSION['ai_chat_history'])) {
    // Если история пуста, склеиваем системный промпт с первым сообщением юзера, чтобы модель железно знала свою роль
    $contents[] = [
        'role' => 'user',
        'parts' => [['text' => "Инструкция для тебя: " . $systemInstruction . "\n\nСообщение клиента: " . $userMessage]]
    ];
} else {
    // Если история уже есть, собираем её по правилам Google
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
}

$payload = [
    'contents' => $contents,
    'generationConfig' => [
        'temperature' => 0.7,
        'maxOutputTokens' => 500
    ]
];

$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $apiKey;

try {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json; charset=utf-8'
        ],
        // Сетевой таран для Render — бьем точно по IP Google в обход упавшего DNS
        CURLOPT_RESOLVE        => ["generativelanguage.googleapis.com:443:142.250.74.42"],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    $data = json_decode((string)$resp, true);
    
    // Если Google вернул конкретный текст ошибки (например, неверный API-ключ)
    if (isset($data['error']['message'])) {
        echo json_encode(['ok' => false, 'reply' => 'Google API Error: ' . $data['error']['message']]);
        exit;
    }

    $reply = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

    if ($reply === '') {
        // Если прилетел пустой JSON, выводим сырой ответ для мгновенного понимания проблемы
        $debug = !empty($resp) ? substr((string)$resp, 0, 150) : 'Empty response (err: '.$err.')';
        echo json_encode(['ok' => false, 'reply' => 'Ответ пуст. Дебаг: ' . $debug]);
        exit;
    }

    // Записываем чистые реплики в сессию
    $_SESSION['ai_chat_history'][] = ['role' => 'user', 'text' => $userMessage];
    $_SESSION['ai_chat_history'][] = ['role' => 'assistant', 'text' => $reply];

    echo json_encode(['ok' => true, 'reply' => $reply]);

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'reply' => 'Ошибка бэкенда: ' . $e->getMessage()]);
}