<?php
/**
 * Пункт навигации «ПРИВАТ ПАК (PPK)» + модалка для тех, у кого доступа
 * ещё нет: описание содержимого, кнопка «Приобрести пак» и поле для
 * активации одноразового ключа (если уже купил и получил код в личке).
 *
 * Использование — внутри <header>, рядом с остальными пунктами меню
 * (после include __DIR__ . '/section_tabs.php'):
 *
 *   $ppkHasAccess = $isAdmin || $isPackDesigner;
 *   include __DIR__ . '/includes/ppk_nav_modal.php';
 *
 * Если $ppkHasAccess === true — пункт меню просто ведёт на resources.php.
 * Если false — пункт меню открывает модалку вместо перехода.
 */
$ppkHasAccess = $ppkHasAccess ?? false;
?>
<?php if ($ppkHasAccess): ?>
    <a href="resources.php" class="section-tab <?= ($sectionTabsActive ?? '') === 'ppk' ? 'active' : '' ?>">Приват Пак</a>
<?php else: ?>
    <a href="#" class="section-tab" onclick="document.getElementById('ppkPreviewModal').classList.add('show');return false;">Приват Пак</a>

    <div class="modal-overlay" id="ppkPreviewModal">
        <div class="modal-card">
            <div class="modal-head">
                <h3>🔒 Приватный пак (PPK)</h3>
                <button type="button" class="modal-close" onclick="document.getElementById('ppkPreviewModal').classList.remove('show')">✕</button>
            </div>
            <p style="color:var(--text2);line-height:1.6;">
                Закрытый раздел для владельцев пака: готовые PSD-исходники, шрифты, кисти и стили,
                гайд и видео по установке Stable Diffusion, а также ИИ-тренажёр общения с клиентом
                и личный планер заказов.
            </p>
            <div class="ppk-preview-video">
                <video controls poster="/assets/img/ppk_preview_poster.jpg" style="width:100%;border-radius:12px;">
                    <source src="/assets/img/ppk_preview.mp4" type="video/mp4">
                </video>
            </div>
            <a href="https://t.me/Perlo_ovka" target="_blank" class="save-all-btn" style="display:block;text-align:center;text-decoration:none;margin-top:16px;">🛒 Приобрести пак</a>

            <details style="margin-top:14px;">
                <summary style="cursor:pointer;color:var(--text2);font-size:13px;">Уже купил и есть ключ активации?</summary>
                <div style="display:flex;gap:8px;margin-top:10px;">
                    <input type="text" id="ppkKeyInput" placeholder="PPK-XXXX-XXXX" style="flex:1;background:rgba(0,0,0,.15);border:1px solid var(--border);color:var(--text);padding:9px 11px;border-radius:8px;">
                    <button type="button" class="mini-btn" id="ppkKeyBtn">Активировать</button>
                </div>
                <p id="ppkKeyMsg" style="font-size:12px;margin-top:6px;"></p>
            </details>
        </div>
    </div>
    <script>
    (function(){
        var btn = document.getElementById('ppkKeyBtn');
        if (!btn) return;
        btn.onclick = async function () {
            var input = document.getElementById('ppkKeyInput');
            var msg = document.getElementById('ppkKeyMsg');
            btn.disabled = true;
            const res = await fetch('activate_ppk_key.php', {
                method: 'POST', headers: {'Content-Type':'application/json'},
                body: JSON.stringify({ code: input.value })
            });
            const r = await res.json();
            btn.disabled = false;
            msg.style.color = r.ok ? '#4ade80' : '#ef4444';
            msg.textContent = r.ok ? 'Готово! Обновите страницу — доступ открыт.' : (r.error || 'Ошибка');
            if (r.ok) setTimeout(() => location.reload(), 1200);
        };
    })();
    </script>
<?php endif; ?>
