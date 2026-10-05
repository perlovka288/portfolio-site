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
