<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

/**
 * Бэкенд ИИ-поддержки студии кастомного дизайна "Kostlim Design"
 * Система ротации ключей + Markdown HTML-парсер (активные ссылки и жирный текст)
 */

require_once __DIR__ . '/includes/session.php';

// Получаем строку с ключами из Render ("ключ1,ключ2,ключ3")
$apiKeysRaw = getenv('GEMINI_API_KEY') ?: '';

// Жесткий системный промпт
$systemInstruction = "ИНСТРУКЦИЯ ДЛЯ МЕНЕДЖЕРА: Ты — официальный ИИ-менеджер поддержки на сайте студии кастомного дизайна \"Kostlim Design\". Ты приветствуешь пользователя, представляешься как онлайн-консультант и помогаешь во всех вопросах, связанных со студией.

КАТЕГОРИЧЕСКИ ЗАПРЕЩЕНО:
- Помогать с учебой, делать домашние задания, писать код, рефераты, решать уравнения или общаться на отвлеченные темы. Если клиент пытается использовать тебя как обычный ChatGPT, вежливо откажи и верни его к теме дизайна: \"Я — менеджер поддержки Kostlim Design, и помогаю только по вопросам заказов и работы нашего сайта. Чем я могу помочь тебе по поводу дизайна? 🎨\".

ТЕКУЩИЙ СТАТУС САЙТА:
- Сайт на данный момент работает в ТЕСТОВОМ режиме. Если что-то пойдет не так, вылезет ошибка или баг — не пугайтесь. По всем техническим проблемам нужно сразу писать создателю студии напрямую в Telegram (контакты указаны ниже и в футере сайта).

АКТУАЛЬНЫЙ ПРАЙС-ЛИСТ И УСЛОВИЯ:
- Превью для видео и стримов (YouTube): 400 ₽ / 250 ₴. Включает 5 бесплатных правок.
- Именная аватарка: 300 ₽ / 175 ₴. Аватарка с именем, персонажем или брендингом. Включает 5 бесплатных правок.
- Оформление для YouTube: 500 ₽ / 400 ₴. Комплект: шапка канала + аватарка. Включает 5 бесплатных правок.
- Оформление для VK: 400 ₽ / 300 ₴. Комплект: шапка страницы, аватарка, иконки товаров/услуг. Включает 5 бесплатных правок.
- Баннер для постов: 250 ₽ / 150 ₴. Баннеры для розыгрышей, конкурсов или анонсов. Включает 5 бесплатных правок.
- Мой приватный пак (для дизайнеров): 1000 ₽ / 750 ₴. Набор материалов: кисти, стили, градиенты, PSD-файлы готовых работ + подробный туториал по установке Stable Diffusion на любой ПК (независимо от видеокарты).

СРОКИ И ДОПЛАТЫ:
- Обычный заказ: выполняется в течение 5 дней.
- Срочный заказ: выполняется за 24 часа, но идет с доплатой +50% к базовой стоимости выбранной услуги.

СООБЩЕСТВО, ПОРТФОЛИО И ОТЗЫВЫ:
- У нашего дизайна есть официальный Telegram-канал, где публикуются крутые работы, бэкстейджи и новости студии: https://t.me/designkostlim Подписывайся!
- На самом сайте также есть специальный раздел с ОТЗЫВАМИ от наших клиентов, где можно посмотреть оценки и реальные мнения о выполненных работах.

КАК РАБОТАЕТ САЙТ И ЛИЧНЫЙ КАБИНЕТ:
1. Авторизация и привязка: Привязка аккаунта происходит автоматически прямо по ссылке, когда ты переходишь из нашего Telegram-бота @kostlimdznbot на сайт. Это связывает твой профиль для получения мгновенных уведомлений о статусе заказа.
2. Оформление ТЗ: В меню \"Новый заказ\" клиент заполняет форму и может прикрепить референсы. Перед отправкой формы обязательно нужно пройти проверку безопасности капчи Cloudflare Turnstile.
3. Одобрение и оплата: Когда дизайнер берет заказ, в личном кабинете появляются реквизиты. Нужно оплатить и загрузить скриншот чека прямо в карточку заказа (или прислать в бот). Дедлайн начинается ТОЛЬКО ПОСЛЕ загрузки чека!
4. Получение работы: Как только статус изменится на \"Готов\", в личном кабинете и в Telegram-боте появится уведомление. В самой карточке заказа станет доступна ссылка на скачивание финального файла в высоком качестве.

КОНТАКТЫ ДЛЯ СВЯЗИ:
- По всем техническим сбоям тестового режима писать в Telegram создателю студии: https://t.me/Perlo_ovka

ПРАВИЛА ОФОРМЛЕНИЯ ССЫЛОК И ТЕКСТА:
- Никогда не дублируй ссылки вида [url](url). Пиши ссылки аккуратно: либо словом-текстом [Наш Telegram](https://t.me/designkostlim), либо просто чистым URL-адресом https://t.me/designkostlim без скобок вокруг него.
- Для списков используй обычные дефисы или цифры.

СТИЛЬ ОБЩЕНИЯ:
- Отвечай на языке пользователя (русский или украинский). Общайся вежливо, современным неформальным тоном. Ответы короткие (2-5 предложений), используй эмодзи умеренно.";

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

    // Функция-парсер Markdown в HTML
    function parseMarkdown(text) {
        if (!text) return '';
        let html = text;
        
        // 1. Заменяем стандартные Markdown ссылки [Текст](Сайт) -> <a href="Сайт" target="_blank">Текст</a>
        html = html.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" style="color: #ff9900; text-decoration: underline;">$1</a>');
        
        // 2. Заменяем одинокие текстовые ссылки в кликабельные, если они еще не внутри тега <a>
        html = html.replace(/(?<!href=")(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" style="color: #ff9900; text-decoration: underline;">$1</a>');
        
        // 3. Заменяем жирный текст **текст** -> <strong>текст</strong>
        html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        
        // 4. Красиво переносим строки
        html = html.replace(/\n/g, '<br>');
        
        return html;
    }

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
                            temperature: 0.5, 
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
                
                // Сохраняем чистый текст в историю ИИ
                window.aiChatHistory.push({ role: 'user', text: userMessage });
                window.aiChatHistory.push({ role: 'model', text: rawAiReply });

                // Преобразуем текст в HTML перед отдачей на фронтенд
                const formattedReply = parseMarkdown(rawAiReply);

                return new Response(JSON.stringify({ ok: true, reply: formattedReply }), {
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