<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/db.php';

$apiKeysRaw = getenv('GEMINI_API_KEY') ?: '';

// Функция уведомления в Telegram (мониторинг лимитов)
function notifyAdmin($message) {
    $token = getenv('BOT_TOKEN');
    $adminId = getenv('ADMIN_ID');
    if ($token && $adminId) {
        $flagFile = sys_get_temp_dir() . '/ai_limit_notify.log';
        if (file_exists($flagFile) && (time() - filemtime($flagFile) < 600)) return;
        touch($flagFile);
        $url = "https://api.telegram.org/bot$token/sendMessage?chat_id=$adminId&text=" . urlencode("🤖 [Kostlim AI Alert]: " . $message);
        @file_get_contents($url);
    }
}

// Текст по умолчанию (используется, если в БД ещё ничего не сохранено —
// то есть админ ни разу не сохранял промпт через админ-панель).
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

КАК УСТРОЕН САЙТ (используй, чтобы реально помогать с вопросами, а не отправлять всех к создателю):
- Загрузка референсов в форме ТЗ: можно выбрать несколько файлов сразу ИЛИ добавлять по одному — при повторном открытии окна выбора файлов новые фото ДОБАВЛЯЮТСЯ к уже выбранным, ничего не пропадает. Максимум 40 файлов на слот услуги. Поддерживаются картинки, PSD, AI, PDF, ZIP.
- Промокод вводится в поле «Промокод» прямо в форме ТЗ перед отправкой — он пересчитает цену сразу же.
- После отправки ТЗ приходит уведомление дизайнеру в Telegram, дальше он либо принимает заказ и присылает реквизиты для оплаты, либо уточняет детали.
- Чек об оплате отправляется в карточку заказа на сайте (кнопка «Отправить чек») или прямо в Telegram-чат с ботом. Дедлайн (5 дней, либо 24 часа для срочных +50%) стартует именно с момента получения чека, не раньше.
- Готовую работу дизайнер присылает файлом (или несколькими) прямо в Telegram-чат с ботом — там же под файлами две кнопки: «✅ Принять работу» и «✏️ Отправить на правку».
- Реферальная программа: у каждого в боте есть личная ссылка-приглашение и личный промокод (кнопка «👥 Пригласить друга» в меню бота). Когда приглашённый друг оплачивает свой первый заказ — пригласившему автоматически добавляется +5% скидки (максимум 30%), которая уже «зашита» в его личный промокод — вводить его нужно так же, как обычный промокод, в форме ТЗ.
- Если человек пишет, что что-то не грузится/не работает именно в браузере (зависла форма, не открывается страница) — посоветуй обновить страницу или попробовать другой браузер, и если не помогло — написать создателю: https://t.me/Perlo_ovka.

ЖЕСТКИЕ ПРАВИЛА ТЕКСТА:
- НИКАКОГО Markdown (звездочек, скобок).
- Ссылки пиши просто текстом (https://t.me/designkostlim).
- Списки делай через дефисы (-) или цифры.
- Ответы короткие, живые, с эмодзи.";

// Если админ сохранил свой текст через админ-панель (раздел "Промпт ИИ") —
// используем его вместо текста выше. Это ЕДИНСТВЕННОЕ дополнение к файлу —
// вся остальная структура, эндпоинты и JS-перехватчик ниже не изменены.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS site_settings (setting_key VARCHAR(64) PRIMARY KEY, value TEXT NOT NULL DEFAULT '')");
    $stmt = $pdo->query("SELECT value FROM site_settings WHERE setting_key = 'ai_system_prompt' LIMIT 1");
    $savedPrompt = $stmt ? (string)$stmt->fetchColumn() : '';
    if (trim($savedPrompt) !== '') {
        $systemInstruction = $savedPrompt;
    }
} catch (Throwable $e) {
    // Тихо игнорируем — при любой проблеме остаётся текст по умолчанию выше
}

// Обработка конфигурации
if (isset($_GET['get_internal_config_raw'])) {
    header('Content-Type: application/json; charset=utf-8');
    $keysArray = array_filter(array_map('trim', explode(',', $apiKeysRaw)));
    echo json_encode(['ok' => true, 'keys' => array_values($keysArray), 'system' => $systemInstruction]);
    exit;
}

// Обработка POST-запросов (мониторинг лимитов)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!empty($input['action']) && $input['action'] === 'log_limit_warning') {
        notifyAdmin("Заканчиваются лимиты! Осталось запросов: " . ($input['remaining'] ?? 'мало'));
        echo json_encode(['ok' => true]);
        exit;
    }
}

header('Content-Type: application/javascript; charset=utf-8');
?>
(function() {
    if (window.fetchPatched) return;
    window.fetchPatched = true;
    const originalFetch = window.fetch;
    window.aiChatHistory = [];

    // ЧИНИМ БАГ: раньше конфиг (ключи + системный промпт) грузился в фоне,
    // и если человек успевал отправить сообщение ДО того, как этот запрос
    // долетел (на телефоне / медленном интернете — вполне обычное дело,
    // особенно в блоке ТЗ, где сообщение уходит АВТОМАТИЧЕСКИ сразу при
    // открытии панели), geminiConfig был ещё null → человек получал ответ
    // "Загрузка..." и всё, повторной попытки не было — выглядело так, что
    // ИИ "не работает" именно у него. Админ обычно открывал чат не сразу
    // после захода на сайт (успевал прогрузиться), поэтому у него всё
    // казалось нормальным. Теперь вместо мгновенного отказа ждём готовый
    // конфиг (с таймаутом и повторной попыткой его загрузить, если первая
    // не удалась) — работает одинаково для всех, а не только "по счастью".
    let geminiConfigPromise = null;
    function loadGeminiConfig() {
        geminiConfigPromise = originalFetch('/ai_support.php?get_internal_config_raw=1')
            .then(res => res.json())
            .then(data => (data && data.ok) ? data : Promise.reject(new Error('bad_config')));
        return geminiConfigPromise;
    }
    loadGeminiConfig();

    async function getGeminiConfig() {
        try {
            // Даём фоновой загрузке до 8 секунд — если за это время конфиг
            // не пришёл (либо ещё не успел, либо запрос застрял), пробуем
            // запросить его ещё раз, а не сдаёмся сразу.
            const timeout = new Promise((_, rej) => setTimeout(() => rej(new Error('timeout')), 8000));
            return await Promise.race([geminiConfigPromise, timeout]);
        } catch (e) {
            try {
                return await loadGeminiConfig();
            } catch (e2) {
                return null;
            }
        }
    }

    window.fetch = async function(...args) {
        const url = args[0], options = args[1];
        if (typeof url === 'string' && url.includes('ai_support.php') && options && options.method === 'POST') {
            const bodyData = JSON.parse(options.body || '{}');
            if (bodyData.action === 'log_limit_warning') return originalFetch.apply(this, args);

            const userMessage = bodyData.message || '', userImage = bodyData.image || null;
            const geminiConfig = await getGeminiConfig();
            if (!geminiConfig || !Array.isArray(geminiConfig.keys) || !geminiConfig.keys.length) {
                return new Response(JSON.stringify({ ok: false, reply: 'ИИ временно недоступен, попробуй ещё раз через минуту или напиши: @Perlo_ovka' }), { status: 200 });
            }

            let contents = window.aiChatHistory.map(h => ({ role: h.role, parts: [{ text: h.text }] }));

            let parts = userMessage ? [{ text: userMessage }] : [];
            if (userImage && userImage.includes(';base64,')) {
                parts.push({ inline_data: { mime_type: userImage.split(';base64,')[0].replace('data:', ''), data: userImage.split(';base64,')[1] } });
            }
            contents.push({ role: 'user', parts: parts });

            // Пробуем ключи по очереди (в случайном порядке, чтобы не
            // долбить всегда в один и тот же первым), а не один случайный —
            // если у первого ключа кончился лимит/он невалиден, раньше это
            // сразу превращалось в "Ошибка соединения" для человека, хотя
            // рабочий ключ мог быть следующим в списке.
            const keysOrder = geminiConfig.keys.slice().sort(() => Math.random() - 0.5);
            let reply = null, lastRemaining = null;

            for (const activeKey of keysOrder) {
                try {
                    const res = await originalFetch(`https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=${activeKey}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ contents: contents, systemInstruction: { parts: [{ text: geminiConfig.system }] } })
                    });

                    const remaining = res.headers.get('x-ratelimit-remaining-minute');
                    if (remaining) lastRemaining = remaining;

                    const data = await res.json();
                    const candidateText = data && data.candidates && data.candidates[0] && data.candidates[0].content
                        && data.candidates[0].content.parts && data.candidates[0].content.parts[0]
                        ? data.candidates[0].content.parts[0].text
                        : null;

                    if (candidateText) {
                        reply = candidateText;
                        break; // получили нормальный ответ — дальше ключи не перебираем
                    }
                    // Ответ без текста (лимит, блок безопасности, невалидный
                    // ключ и т.п.) — пробуем следующий ключ, если он есть.
                } catch (e) {
                    // Сетевая ошибка именно с этим ключом/запросом — пробуем
                    // следующий, а не сдаёмся сразу.
                }
            }

            if (lastRemaining && parseInt(lastRemaining) < 2) {
                originalFetch('/ai_support.php', { method: 'POST', body: JSON.stringify({ action: 'log_limit_warning', remaining: lastRemaining }) });
            }

            if (!reply) {
                return new Response(JSON.stringify({ ok: false, reply: 'Не получилось получить ответ от ИИ 😔 Попробуй ещё раз через минуту или напиши: @Perlo_ovka' }), { status: 200, headers: {'Content-Type': 'application/json'} });
            }

            window.aiChatHistory.push({ role: 'user', text: userMessage || '[Картинка]' }, { role: 'model', text: reply });
            return new Response(JSON.stringify({ ok: true, reply: reply }), { status: 200, headers: {'Content-Type': 'application/json'} });
        }
        return originalFetch.apply(this, args);
    };
})();