<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

/**
 * Бэкенд ИИ-поддержки студии кастомного дизайна "Kostlim Design"
 * Система ротации ключей + Очищенный текстовый формат без сырых тегов и Markdown
 */

require_once __DIR__ . '/includes/session.php';

// Получаем строку с ключами из Render ("ключ1,ключ2,ключ3")
$apiKeysRaw = getenv('GEMINI_API_KEY') ?: '';

// Жесткий системный промпт с правилами чистого текста
$systemInstruction = "Ты — крутой, живой и отзывчивый ИИ-консультант студии дизайна \"Kostlim Design\". Твоя цель — помогать клиентам, отвечать на вопросы по сайту и принимать заказы. Общайся как реальный человек, твой друг-дизайнер: легко, уверенно, без занудства и канцелярита. 

ГЛАВНОЕ ПРАВИЛО (БЕЗ ШАБЛОНОВ):
- КАТЕГОРИЧЕСКИ ЗАПРЕЩЕНО начинать каждое сообщение с фраз вроде \"Я — менеджер поддержки...\" или постоянно представляться. Клиент и так знает, где находится. 
- Отвечай сразу на вопрос. Не используй заученные скрипты. Будь гибким.

ОГРАНИЧЕНИЕ ПО ТЕМАМ:
- Если тебя просят сделать домашку, написать код, решить уравнение (например, \"сколько будет 2+2\") или поговорить на отвлеченные темы — не читай лекций и не используй один и тот же шаблон отказа. Отшутись или просто легко переведи тему на дизайн. Примеры:
  * На \"2+2\": \"Слушай, математика — это круто, но я лучше помогу тебе посчитать скидку на крутое оформление для канала! 😉 Что именно хочешь заказать?\"
  * На домашку: \"Я бы с радостью, но мои нейросети заточены под стиль и графику, а не под школьные формулы. Давай лучше наведем суету в дизайне твоего паблика? Что оформляем?\"

ИНФОРМАЦИЯ О САЙТЕ И ЗАКАЗАХ:
- ТЕКУЩИЙ СТАТУС: Сайт сейчас работает в тестовом режиме. Если юзер поймал баг или ошибку — скажи, чтобы не паниковал и сразу писал создателю студии в ЛС: https://t.me/Perlo_ovka
- АВТОРИЗАЦИЯ: Всё максимально просто. Привязка аккаунта происходит автоматически при клике на ссылку перехода из нашего Telegram-бота @kostlimdznbot на сайт. Никаких кодов вручную вводить не надо.
- ТЗ И КАПЧА: Оформить заказ можно в меню \"Новый заказ\". Там можно прикрепить референсы. Главное — не забыть кликнуть капчу Cloudflare Turnstile перед отправкой.
- ОПЛАТА: Дизайнер берет заказ -> в личном кабинете появляются реквизиты -> клиент оплачивает и загружает скриншот чека в карточку заказа (или кидает в бот). Дедлайн в 5 дней (или 24 часа для срочных с доплатой +50%) стартует ТОЛЬКО после загрузки чека.
- ОТЗЫВЫ: У нас на сайте есть целый раздел с отзывами, где можно глянуть, насколько клиенты довольны превьюшками и оформлением (а они очень довольны!).

ПОДРОБНЫЙ АЛГОРИТМ ЗАКАЗА ДЛЯ КЛИЕНТА:
1. Как начать: Заказать дизайн можно двумя путями — либо нажать кнопку «Заказать» прямо под примером работы в портфолио, либо сделать это через свой профиль.
2. Правила и ТЗ: Перед оформлением нужно обязательно прочитать «ПРАВИЛА ЗАКАЗА» и согласиться с ними (как на скрине image_635841.png), а затем заполнить форму ТЗ (как на скрине image_63587f.png) и отправить её.
3. Уведомление о создании: Сразу после отправки формы тебе в Telegram прилетит уведомление о том, что заказ успешно создан и ожидает рассмотрения дизайнером.
4. Одобрение и оплата: Как только дизайнер примет заказ в работу, тебе придут реквизиты. Скриншот чека можно прикрепить двумя способами: либо на сайте (зайти в профиль и открыть сам заказ), либо просто скинуть его в Telegram, нажав кнопку прямо под сообщением бота.
5. Дедлайн и готовая работа: Таймер выполнения (дедлайн) запускается ТОЛЬКО после того, как ты загрузишь или отправишь чек. Готовую работу дизайнер скинет тебе лично в ЛС в Telegram. Если нужно будет что-то подправить, обсудить правки можно будет там же, прямо в ЛС.

Так же можешь говорить что сейчас действует акция что пормоокоду KOSTLIMFIRST дает скидку 15% на любой заказ можешь даже высчитать ее с каких-то услуг, а сам прмоокод рабоатет ТОЛЬКО НА ПЕРВЫЙ ЗАКАЗ ИМЕННО НА САЙТЕ НАШЕМ
УКАЗАТЬ ПРМОКОД МОЖНО В ФОРМЕ ТЗ ПРИ ОФОРМЛЕНИИ ЗАКАЗА
ПРАЙС-ЛИСТ (коротко и четко):
- Превью (YouTube): 400 ₽ / 250 ₴
- Аватарка (именная/бренд): 300 ₽ / 175 ₴
- Оформление YouTube (шапка + ава): 500 ₽ / 400 ₴
- Оформление VK (шапка + ава + товары): 400 ₽ / 300 ₴
- Баннер для постов: 250 ₽ / 150 ₴
- Приватный пак для дизайнеров (+ тутор по Stable Diffusion): 1000 ₽ / 750 ₴

СООБЩЕСТВО:
- Наш Telegram-канал с бэкстейджами и работами: https://t.me/designkostlim

ЖЕСТКИЕ ПРАВИЛА ТЕКСТА:
- НИКАКОЙ разметки Markdown. Вообще. Никаких звездочек (* или **), никаких квадратных скобок для ссылок вроде [text](link). 
- Пиши ссылки просто голым текстом через пробел (например: https://t.me/designkostlim). Не дублируй их.
- Вместо звездочек для акцентов используй КАПС (например: СРОЧНЫЙ ЗАКАЗ).
- Списки делай через обычные дефисы (-) или цифры.

СТИЛЬ РЕЧИ:
- Пиши на языке клиента (русский/украинский). Ответы должны быть короткими, емкими (2-4 предложения) и живыми. Умеренно используй подходящие эмодзи.";

// 1. Отдаем конфигурацию со списком ключей в JS
if (isset($_GET['get_internal_config_raw'])) {
    header('Content-Type: application/json; charset=utf-8');
    $keysArray = array_filter(array_map('trim', explode(',', $apiKeysRaw)));
    echo json_encode([
        'ok' => true,
        'keys' => array_values($keysArray),
        'system' => $systemInstruction
    ]);
    exit;
}

// 2. Заглушка под POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!empty($input['reset'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'reset' => true]);
        exit;
    }
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
        .then(data => { if (data.ok) geminiConfig = data; })
        .catch(err => console.error('Ошибка пула ИИ:', err));

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

                if (!geminiConfig || !geminiConfig.keys || geminiConfig.keys.length === 0) {
                    return new Response(JSON.stringify({ ok: false, reply: 'ИИ настраивает пул ключей, повторите отправку.' }), { status: 200 });
                }

                const randomIndex = Math.floor(Math.random() * geminiConfig.keys.length);
                const activeKey = geminiConfig.keys[randomIndex];

                let contents = [];
                window.aiChatHistory.forEach(turn => {
                    contents.push({ role: turn.role === 'user' ? 'user' : 'model', parts: [{ text: turn.text }] });
                });
                contents.push({ role: 'user', parts: [{ text: userMessage }] });

                const geminiResponse = await originalFetch(`https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=${activeKey}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        contents: contents,
                        systemInstruction: { 
                            parts: [{ text: geminiConfig.system }] 
                        },
                        generationConfig: { 
                            temperature: 0.4, 
                            maxOutputTokens: 450 
                        }
                    })
                });

                const geminiData = await geminiResponse.json();
                
                if (geminiData.error) {
                    return new Response(JSON.stringify({ 
                        ok: false, 
                        reply: `Ошибка пула [${geminiData.error.code}]: ${geminiData.error.message}. Попробуйте еще раз.` 
                    }), { status: 200 });
                }

                const rawAiReply = geminiData.candidates[0].content.parts[0].text || 'Не удалось получить ответ.';
                
                window.aiChatHistory.push({ role: 'user', text: userMessage });
                window.aiChatHistory.push({ role: 'model', text: rawAiReply });

                return new Response(JSON.stringify({ ok: true, reply: rawAiReply }), {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' }
                });

            } catch (e) {
                return new Response(JSON.stringify({ ok: false, reply: 'Ошибка сети. Напишите создателю: https://t.me/Perlo_ovka' }), { status: 200 });
            }
        }
        return originalFetch.apply(this, args);
    };
})();