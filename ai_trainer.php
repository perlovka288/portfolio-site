<?php
/**
 * «Тренировка общения с клиентом» — закрытый раздел для PPK/ADMIN.
 * Анкета (имя клиента / сложность / тема) → чат с ИИ-заказчиком →
 * сдача работы → оценка 0–100 → «Поделиться с Kostlim».
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/ppk_access.php';
require_once __DIR__ . '/includes/notifications_lib.php';
require_once __DIR__ . '/includes/notifications_bell.php';

ensureNotificationsSchema($pdo);
$access = resolvePpkAccess($pdo);
$isAdmin = $access['isAdmin'];
$isPackDesigner = $access['isPackDesigner'];

if (!$isPackDesigner) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Доступ закрыт | Kostlim Design</title>
    <link rel="stylesheet" href="style.css">
    </head><body style="display:flex;align-items:center;justify-content:center;min-height:100vh;text-align:center;padding:24px;">
        <div>
            <h1>Доступ закрыт</h1>
            <p>Тренажёр общения с клиентом доступен только участникам приватного пака (PPK).</p>
            <p><a href="privat_pak.php">← В Приват Пак</a></p>
        </div>
    </body></html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#0d0d0d">
    <title>Тренажёр клиентов | Kostlim Design</title>
    <link rel="icon" type="image/png" href="/assets/img/logo-64.png" sizes="16x16">
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
<style>
/* ═════════ Тренажёр в стиле Telegram — только цвета сайта (style.css) ═════════ */
html, body { height: 100%; margin: 0; overflow: hidden; }
body { background: var(--bg); color: var(--text); -webkit-tap-highlight-color: transparent; }
.ic { width: 20px; height: 20px; flex-shrink: 0; display: block; }
[data-ic] { display: inline-flex; align-items: center; justify-content: center; }
button { font-family: inherit; }

.tg-app { text-align: left; position: fixed; inset: 0; display: grid; grid-template-columns: 100%; background: var(--bg); }

/* ── Левая колонка: список чатов ── */
.tg-side { position: relative; display: flex; flex-direction: column; min-height: 0; min-width: 0; background: var(--bg2); border-right: 1px solid var(--border); }
.tg-side-head { display: flex; align-items: center; gap: 10px; padding: calc(10px + env(safe-area-inset-top)) 12px 6px; }
.tg-side-title { flex: 1; font-size: 18px; font-weight: 900; letter-spacing: -.2px; }
.tg-iconbtn { width: 38px; height: 38px; border-radius: 50%; border: 0; background: transparent; color: var(--text2); display: inline-flex; align-items: center; justify-content: center; cursor: pointer; text-decoration: none; transition: background .15s, color .15s; flex-shrink: 0; }
.tg-iconbtn:hover { background: rgba(255,255,255,.07); color: var(--text); }
.tg-search { position: relative; margin: 6px 12px 8px; }
.tg-search [data-ic] { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text2); pointer-events: none; }
.tg-search .ic { width: 17px; height: 17px; }
.tg-search input { width: 100%; box-sizing: border-box; height: 38px; border-radius: 19px; border: 1px solid transparent; background: var(--card3); color: var(--text); padding: 0 14px 0 38px; font: inherit; font-size: 16px; outline: none; transition: border-color .15s, box-shadow .15s; }
.tg-search input::placeholder { color: var(--text2); }
.tg-search input:focus { border-color: var(--border-accent); box-shadow: 0 0 0 3px var(--accent-dim); }
.tg-list { flex: 1; min-height: 0; overflow-y: auto; padding: 2px 6px 96px; -webkit-overflow-scrolling: touch; }

.tg-item { display: flex; align-items: center; gap: 12px; padding: 9px 10px; border-radius: 14px; cursor: pointer; transition: background .15s; user-select: none; }
.tg-item:hover { background: rgba(255,255,255,.045); }
.tg-item.active { background: linear-gradient(135deg, var(--accent2), var(--accent)); box-shadow: 0 8px 22px -10px var(--accent-glow2); }
.tg-ava { width: 50px; height: 50px; border-radius: 50%; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 19px; font-weight: 800; color: #fff; text-transform: uppercase; background: linear-gradient(135deg, var(--ava-a, #fb923c), var(--ava-b, #f97316)); }
.tg-ava--sm { width: 40px; height: 40px; font-size: 16px; }
.tg-item-main { flex: 1; min-width: 0; }
.tg-item-top, .tg-item-bot { display: flex; align-items: center; gap: 8px; min-width: 0; }
.tg-item-name { font-size: 15px; font-weight: 800; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; }
.tg-item-name small { font-size: 13px; font-weight: 500; color: var(--text2); }
.tg-item-time { margin-left: auto; font-size: 11.5px; color: var(--text2); flex-shrink: 0; }
.tg-item-bot { margin-top: 3px; }
.tg-item-preview { flex: 1; min-width: 0; font-size: 13.5px; color: var(--text2); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: flex; align-items: center; gap: 4px; }
.tg-item-preview b { color: var(--accent3); font-weight: 600; flex-shrink: 0; }
.tg-item-preview .ic { width: 14px; height: 14px; }
.tg-item-badges { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
.tg-item-badges .ic { width: 15px; height: 15px; }
.tg-score { min-width: 24px; height: 20px; padding: 0 7px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; font-size: 11.5px; font-weight: 900; background: rgba(255,255,255,.08); color: #fff; }
.tg-score.s-good { background: rgba(34,197,94,.18); color: #22C55E; }
.tg-score.s-mid  { background: rgba(255,197,61,.18); color: #FFC53D; }
.tg-score.s-bad  { background: rgba(239,68,68,.18); color: #EF4444; }
.tg-live { width: 10px; height: 10px; border-radius: 50%; background: #22C55E; box-shadow: 0 0 0 3px rgba(34,197,94,.18); }
.tg-flame { color: var(--accent2); }
.tg-clock { color: #FFC53D; }
.tg-item.active .tg-item-name small, .tg-item.active .tg-item-time, .tg-item.active .tg-item-preview { color: rgba(255,255,255,.82); }
.tg-item.active .tg-item-preview b { color: #fff; }
.tg-item.active .tg-flame, .tg-item.active .tg-clock { color: #fff; }
.tg-item.active .tg-score { background: rgba(255,255,255,.22); color: #fff; }
.tg-list-empty { text-align: center; color: var(--text2); font-size: 13.5px; padding: 48px 24px; line-height: 1.5; }
.tg-list-empty .ic { width: 38px; height: 38px; margin: 0 auto 12px; opacity: .5; }

.tg-fab { position: absolute; right: 16px; bottom: calc(16px + env(safe-area-inset-bottom)); width: 56px; height: 56px; border-radius: 50%; border: 0; cursor: pointer; color: #fff; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, var(--accent2), var(--accent)); box-shadow: var(--shadow-accent); transition: transform .18s; z-index: 3; }
.tg-fab:hover { transform: scale(1.06); }
.tg-fab:active { transform: scale(.94); }
.tg-fab .ic { width: 23px; height: 23px; }

/* ── Правая колонка: переписка ── */
.tg-main { position: absolute; inset: 0; z-index: 5; display: flex; flex-direction: column; min-width: 0; min-height: 0; background: var(--bg); transform: translateX(100%); transition: transform .28s cubic-bezier(.22,1,.36,1); }
.tg-app.in-chat .tg-main { transform: none; }
.tg-empty { flex: 1; display: none; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 24px; gap: 10px; }
.tg-empty-ico { width: 84px; height: 84px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--accent2); background: var(--accent-dim); border: 1px solid var(--border-accent); box-shadow: 0 0 40px var(--accent-glow); margin-bottom: 6px; }
.tg-empty-ico .ic { width: 38px; height: 38px; }
.tg-empty h2 { margin: 0; font-size: 19px; font-weight: 900; }
.tg-empty p { margin: 0; color: var(--text2); font-size: 13.5px; max-width: 320px; line-height: 1.5; }
.tg-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; border: 0; cursor: pointer; border-radius: 12px; padding: 12px 20px; font-size: 13px; font-weight: 800; color: #fff; background: linear-gradient(135deg, var(--accent2), var(--accent)); box-shadow: var(--shadow-accent); transition: transform .15s; margin-top: 8px; }
.tg-btn:hover { transform: translateY(-1px); }
.tg-btn .ic { width: 17px; height: 17px; }
.tg-chat { flex: 1; min-height: 0; display: none; flex-direction: column; }
.tg-main.has-chat .tg-chat { display: flex; }

.tg-chat-head { display: flex; align-items: center; gap: 10px; padding: calc(8px + env(safe-area-inset-top)) 12px 8px 6px; background: var(--bg2); border-bottom: 1px solid var(--border); flex-shrink: 0; }
.tg-chat-who { flex: 1; min-width: 0; line-height: 1.25; }
.tg-chat-who strong { display: block; font-size: 15.5px; font-weight: 800; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tg-status { font-size: 12.5px; color: var(--text2); transition: color .2s; }
.tg-status.online { color: var(--accent3); }
.tg-status.typing { color: var(--accent2); }
.tg-submit { display: inline-flex; align-items: center; gap: 7px; border: 1px solid var(--border-accent); background: var(--accent-dim); color: var(--accent3); border-radius: 18px; padding: 8px 14px; font-size: 12.5px; font-weight: 800; cursor: pointer; flex-shrink: 0; transition: background .15s, color .15s; }
.tg-submit:hover:not(:disabled) { background: var(--accent-glow); color: #fff; }
.tg-submit:disabled { opacity: .6; cursor: default; }
.tg-submit .ic { width: 16px; height: 16px; }
@media (max-width: 420px) { .tg-submit span.tg-lbl { display: none; } .tg-submit { padding: 9px 11px; } }

.tg-pinned { display: flex; align-items: center; gap: 10px; padding: 6px 14px; background: var(--bg2); border-bottom: 1px solid var(--border); flex-shrink: 0; min-width: 0; }
.tg-pinned-bar { width: 3px; align-self: stretch; border-radius: 2px; background: var(--accent); flex-shrink: 0; }
.tg-pinned-txt { flex: 1; min-width: 0; line-height: 1.3; }
.tg-pinned-txt small { display: block; font-size: 11.5px; font-weight: 700; color: var(--accent2); }
.tg-pinned-txt span { display: block; font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.trainer-diff-badge { font-size: 10.5px; font-weight: 900; text-transform: uppercase; padding: 4px 10px; border-radius: 999px; background: var(--accent-dim); color: var(--accent3); border: 1px solid var(--border-accent); flex-shrink: 0; letter-spacing: .3px; }

.trainer-chat-body { flex: 1; min-height: 0; overflow-y: auto; overscroll-behavior: contain; -webkit-overflow-scrolling: touch; scroll-behavior: smooth;
    padding: 10px max(10px, calc((100% - 780px) / 2)) 14px; display: flex; flex-direction: column;
    background-color: var(--bg);
    background-image: radial-gradient(900px 420px at 15% 0%, rgba(249,115,22,.07), transparent 60%), radial-gradient(800px 420px at 100% 100%, rgba(251,146,60,.05), transparent 60%), radial-gradient(rgba(255,255,255,.035) 1px, transparent 1.2px);
    background-size: auto, auto, 24px 24px; }
.tg-day { align-self: center; margin: 12px 0 6px; padding: 3px 12px; border-radius: 999px; background: rgba(255,255,255,.07); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); font-size: 12px; font-weight: 600; color: var(--text2); }
.tg-row { display: flex; margin-top: 8px; animation: tgIn .28s cubic-bezier(.22,1,.36,1) both; }
.tg-row.tg-grouped { margin-top: 2px; }
.tg-row--in { justify-content: flex-start; padding-left: 9px; }
.tg-row--out { justify-content: flex-end; padding-right: 9px; }
@keyframes tgIn { from { opacity: 0; transform: translateY(10px) scale(.97); } to { opacity: 1; transform: none; } }

.tg-bubble { --tail: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 9 12'%3E%3Cpath d='M9 0V12H0Q7 10.5 9 0Z'/%3E%3C/svg%3E");
    position: relative; max-width: min(80%, 540px); padding: 7px 11px 6px; font-size: 14.5px; line-height: 1.4; border-radius: 15px; overflow-wrap: anywhere; word-break: break-word; }
.tg-bubble.tg-in { background: var(--card3); color: var(--text); }
.tg-bubble.tg-out { background: linear-gradient(135deg, var(--accent2), var(--accent)); color: #fff; box-shadow: 0 6px 18px -10px var(--accent-glow2); }
.tg-bubble.tail::after { content: ''; position: absolute; bottom: 0; width: 9px; height: 12px; -webkit-mask: var(--tail) center / 100% 100% no-repeat; mask: var(--tail) center / 100% 100% no-repeat; }
.tg-bubble.tg-in.tail { border-bottom-left-radius: 0; }
.tg-bubble.tg-in.tail::after { left: -8px; background: var(--card3); }
.tg-bubble.tg-out.tail { border-bottom-right-radius: 0; }
.tg-bubble.tg-out.tail::after { right: -8px; background: var(--accent); transform: scaleX(-1); }
.tg-text::after { content: ''; display: table; clear: both; }
.tg-meta { float: right; display: inline-flex; align-items: center; gap: 3px; margin: 7px 0 -3px 12px; font-size: 11px; line-height: 1; opacity: .62; user-select: none; }
.tg-meta .ic { width: 15px; height: 15px; }
.tg-tick.read { color: #fff; opacity: 1; }
.tg-bubble.tg-in .tg-meta { color: var(--text2); opacity: .9; }
.tg-img { display: block; width: 100%; max-width: 280px; border-radius: 10px; margin: 1px 0 5px; }
.tg-bubble.has-img { padding: 4px 4px 6px; }
.tg-bubble.has-img .tg-text { padding: 0 7px; }
.tg-typing { display: inline-flex; gap: 4px; padding: 6px 2px 5px; }
.tg-typing span { width: 7px; height: 7px; border-radius: 50%; background: var(--text2); animation: tgDot .9s infinite ease-in-out; }
.tg-typing span:nth-child(2) { animation-delay: .15s; } .tg-typing span:nth-child(3) { animation-delay: .3s; }
@keyframes tgDot { 0%,60%,100% { opacity: .35; transform: translateY(0); } 30% { opacity: 1; transform: translateY(-4px); } }

/* Системная карточка «Перевод от клиента» */
.tg-pay { align-self: center; width: min(100%, 360px); margin: 10px 0 4px; display: flex; align-items: center; gap: 12px; padding: 11px 14px; border-radius: 16px; background: var(--card2); border: 1px solid var(--border-accent); box-shadow: 0 0 22px var(--accent-glow); animation: tgIn .3s cubic-bezier(.22,1,.36,1) both; }
.tg-pay-ico { width: 38px; height: 38px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: var(--accent-dim); color: var(--accent2); flex-shrink: 0; }
.tg-pay-main { flex: 1; min-width: 0; }
.tg-pay-title { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--text2); }
.tg-pay-amount { font-size: 17px; font-weight: 900; color: var(--accent2); }
.tg-pay-amount span { font-size: 12px; font-weight: 600; color: var(--text2); }
.tg-pay-ok { display: flex; align-items: center; gap: 5px; font-size: 12px; font-weight: 700; color: #22C55E; white-space: nowrap; }
.tg-pay-ok .ic { width: 17px; height: 17px; }

/* ── Нижняя панель ввода ── */
.tg-attach { display: none; align-items: center; gap: 10px; padding: 8px 14px; background: var(--bg2); border-top: 1px solid var(--border); flex-shrink: 0; }
.tg-attach.show { display: flex; animation: tgIn .2s ease both; }
.tg-attach img { width: 40px; height: 40px; border-radius: 8px; object-fit: cover; flex-shrink: 0; }
.tg-attach-txt { flex: 1; min-width: 0; line-height: 1.3; }
.tg-attach-txt small { display: block; font-size: 11.5px; font-weight: 700; color: var(--accent2); }
.tg-attach-txt span { display: block; font-size: 12.5px; color: var(--text2); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tg-foot { display: flex; align-items: center; gap: 8px; padding: 8px 10px calc(8px + env(safe-area-inset-bottom)); background: var(--bg2); border-top: 1px solid var(--border); flex-shrink: 0; }
.tg-input-wrap { flex: 1; min-width: 0; display: flex; align-items: center; background: var(--card3); border: 1px solid var(--border); border-radius: 23px; padding: 0 6px 0 4px; transition: border-color .15s, box-shadow .15s; }
.tg-input-wrap:focus-within { border-color: var(--border-accent); box-shadow: 0 0 0 3px var(--accent-dim); }
.tg-input-wrap input { flex: 1; min-width: 0; height: 44px; border: 0; outline: 0; background: transparent; color: var(--text); font: inherit; font-size: 16px; padding: 0 6px; }
.tg-input-wrap input::placeholder { color: var(--text2); }
.tg-send { width: 46px; height: 46px; border-radius: 50%; border: 0; cursor: pointer; flex-shrink: 0; display: flex; align-items: center; justify-content: center; color: #fff; background: linear-gradient(135deg, var(--accent2), var(--accent)); box-shadow: 0 6px 18px -6px var(--accent-glow2); opacity: .45; transform: scale(.92); transition: opacity .18s, transform .18s; }
.tg-send.ready { opacity: 1; transform: none; }
.tg-send:active { transform: scale(.92); }
.tg-send .ic { width: 21px; height: 21px; margin: 1px 2px 0 0; }

.tg-toast { position: fixed; left: 50%; bottom: calc(86px + env(safe-area-inset-bottom)); transform: translate(-50%, 12px); max-width: min(92vw, 420px); background: var(--card2); border: 1px solid var(--border-accent); color: var(--text); padding: 11px 16px; border-radius: 14px; font-size: 13px; line-height: 1.4; box-shadow: 0 12px 30px rgba(0,0,0,.5); opacity: 0; pointer-events: none; transition: opacity .22s, transform .22s; z-index: 1200; }
.tg-toast.show { opacity: 1; transform: translate(-50%, 0); }

@media (min-width: 860px) {
    .tg-app { grid-template-columns: 380px minmax(0, 1fr); }
    .tg-main { position: relative; inset: auto; transform: none; z-index: auto; }
    .tg-empty { display: flex; }
    .tg-main.has-chat .tg-empty { display: none; }
    .tg-back { display: none !important; }
    .tg-chat-head { padding-left: 14px; }
    .tg-toast { bottom: 96px; }
}

/* ═════════ Модалки (анкета / результат) ═════════ */
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.62); backdrop-filter: blur(4px); display: none; align-items: center; justify-content: center; z-index: 1000; padding: 16px; }
.modal-overlay.show { display: flex; }
.modal-card { background: var(--bg2, #0d0d0d); border: 1px solid var(--border2); border-radius: 20px; padding: 22px; max-width: 420px; width: 100%; max-height: 88vh; overflow-y: auto; animation: tgIn .25s cubic-bezier(.22,1,.36,1) both; }
.modal-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.modal-head h3 { margin: 0; font-size: 17px; font-weight: 900; }
.modal-close { background: none; border: none; color: var(--text2); cursor: pointer; width: 34px; height: 34px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; }
.modal-close:hover { background: rgba(255,255,255,.07); color: var(--text); }
.trainer-setup-card label { display: block; font-size: 12px; color: var(--text2); margin: 14px 0 6px; font-weight: 700; }
.setup-row { display: flex; gap: 8px; }
.trainer-setup-card input[type=text] { flex: 1; width: 100%; box-sizing: border-box; background: var(--card3); border: 1px solid var(--border); color: var(--text); padding: 11px 13px; border-radius: 12px; font: inherit; font-size: 16px; outline: none; }
.trainer-setup-card input[type=text]:focus { border-color: var(--border-accent); }
.trainer-setup-card input#setupTopicCustom { margin-top: 8px; }
.mini-btn { display: inline-flex; align-items: center; gap: 6px; background: var(--card3); border: 1px solid var(--border); color: var(--text); padding: 9px 13px; border-radius: 12px; cursor: pointer; font-size: 12.5px; font-weight: 700; white-space: nowrap; }
.mini-btn:hover { border-color: var(--border-accent); color: var(--accent2); }
.mini-btn .ic { width: 16px; height: 16px; }
.setup-pills { display: flex; gap: 8px; flex-wrap: wrap; }
.setup-pill { background: var(--card3); border: 1px solid var(--border); color: var(--text2); padding: 9px 15px; border-radius: 999px; cursor: pointer; font-size: 12.5px; font-weight: 700; }
.setup-pill.active { background: linear-gradient(135deg, var(--accent2), var(--accent)); color: #fff; border-color: transparent; }
.trainer-start-btn { display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; box-sizing: border-box; margin-top: 18px; background: linear-gradient(135deg, var(--accent2), var(--accent)); color: #fff; border: none; padding: 14px; border-radius: 13px; font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: .8px; cursor: pointer; box-shadow: var(--shadow-accent); }
.trainer-start-btn:disabled { opacity: .65; cursor: default; }
.trainer-start-btn .ic { width: 17px; height: 17px; }

/* Окно результата */
.trainer-score-ring { position: relative; width: 128px; height: 128px; margin: 6px auto 18px; border-radius: 50%; background: conic-gradient(var(--score-color, var(--accent)) calc(var(--pct, 0) * 1%), rgba(255,255,255,.08) 0); transition: background .08s linear; }
.trainer-score-ring::before { content: ''; position: absolute; inset: 9px; border-radius: 50%; background: var(--card, #121212); box-shadow: inset 0 0 0 1px var(--border); }
.trainer-score-ring .tsr-label { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; z-index: 1; }
.trainer-score-ring .tsr-num { font-size: 26px; font-weight: 900; color: #fff; line-height: 1; }
.trainer-score-ring .tsr-max { font-size: 11px; color: var(--text2); margin-top: 2px; }
.trs-section { text-align: left; margin: 0 0 12px; padding: 12px 14px; border-radius: 14px; background: var(--card); border: 1px solid var(--border); }
.trs-section h4 { margin: 0 0 8px; font-size: 13px; font-weight: 800; display: flex; align-items: center; gap: 7px; }
.trs-section h4 .ic { width: 17px; height: 17px; }
.trs-section ul { margin: 0; padding-left: 18px; }
.trs-section li { font-size: 12.5px; line-height: 1.5; color: var(--text2); margin-bottom: 4px; }
.trs-pros { border-color: rgba(34,197,94,.28); } .trs-pros h4 { color: #22C55E; }
.trs-cons { border-color: rgba(249,115,22,.32); } .trs-cons h4 { color: var(--accent); }
.trs-review { display: flex; gap: 12px; align-items: flex-start; text-align: left; padding: 12px 14px; border-radius: 14px; background: var(--card); border: 1px solid var(--border); margin-bottom: 14px; }
.trs-review-label { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--text2); margin-bottom: 4px; }
.trs-review p { margin: 0; font-size: 13px; line-height: 1.5; color: var(--text); }
.trs-smile { flex-shrink: 0; width: 34px; height: 34px; }
.trainer-kostlim-feedback { background: rgba(249,115,22,.08); border: 1px solid rgba(249,115,22,.25); border-radius: 14px; padding: 12px 14px; margin: -4px 0 14px; text-align: left; }
.trainer-kostlim-feedback strong { color: var(--accent2); font-size: 13px; display: inline-flex; align-items: center; gap: 6px; }
.trainer-kostlim-feedback strong .ic { width: 16px; height: 16px; }
.trainer-kostlim-feedback p { margin: 6px 0 0; color: var(--text2); font-size: 13px; line-height: 1.5; }
</style>
</head>
<body>

<div class="tg-app" id="tgApp">

    <!-- ── Список чатов ── -->
    <aside class="tg-side">
        <div class="tg-side-head">
            <a href="privat_pak.php" class="tg-iconbtn" aria-label="Приват Пак" title="Приват Пак"><span data-ic="arrow-left"></span></a>
            <div class="tg-side-title">Тренировка</div>
            <?php renderNotificationBell(); ?>
        </div>
        <div class="tg-search">
            <span data-ic="search"></span>
            <input type="text" id="tgSearch" placeholder="Поиск" autocomplete="off">
        </div>
        <div class="tg-list" id="trainerSessionsList"></div>
        <button type="button" class="tg-fab js-new-order" aria-label="Новый заказ" title="Новый заказ"><span data-ic="square-pen"></span></button>
    </aside>

    <!-- ── Переписка ── -->
    <section class="tg-main" id="tgMain">
        <div class="tg-empty">
            <div class="tg-empty-ico"><span data-ic="gamepad"></span></div>
            <h2>Тренировка общения с клиентом</h2>
            <p>Отыграй заказ от начала до сдачи — ИИ в роли требовательного заказчика.</p>
            <button type="button" class="tg-btn js-new-order"><span data-ic="square-pen"></span>Новый заказ</button>
        </div>

        <div class="tg-chat" id="chatScreen">
            <div class="tg-chat-head">
                <button type="button" class="tg-iconbtn tg-back" onclick="closeChatScreen()" aria-label="Назад"><span data-ic="arrow-left"></span></button>
                <div class="tg-ava tg-ava--sm" id="chatAva">К</div>
                <div class="tg-chat-who">
                    <strong id="chatClientName">Клиент</strong>
                    <span class="tg-status online" id="chatStatus">в сети</span>
                </div>
                <button type="button" class="tg-submit" id="btnSubmitWork" title="Сдать работу"><span data-ic="upload"></span><span class="tg-lbl">Сдать работу</span></button>
            </div>
            <div class="tg-pinned">
                <span class="tg-pinned-bar"></span>
                <div class="tg-pinned-txt"><small>Тема заказа</small><span id="chatTopicLabel"></span></div>
                <span id="chatDifficultyBadge" class="trainer-diff-badge"></span>
            </div>

            <div class="trainer-chat-body" id="chatBody"></div>

            <div class="tg-attach" id="attachChip">
                <img id="attachThumb" alt="">
                <div class="tg-attach-txt"><small>Работа к сдаче</small><span id="attachName"></span></div>
                <button type="button" class="tg-iconbtn" id="btnAttachRemove" aria-label="Убрать файл"><span data-ic="x"></span></button>
            </div>
            <div class="tg-foot">
                <div class="tg-input-wrap">
                    <input type="file" id="submitFileInput" accept="image/*" style="display:none;">
                    <button type="button" class="tg-iconbtn" id="btnAttachSubmit" title="Прикрепить превью работы" aria-label="Прикрепить"><span data-ic="paperclip"></span></button>
                    <input type="text" id="chatInput" placeholder="Написать клиенту…" autocomplete="off" enterkeyhint="send">
                </div>
                <button type="button" class="tg-send" id="btnSendMsg" aria-label="Отправить"><span data-ic="send"></span></button>
            </div>
        </div>
    </section>
</div>

<div class="tg-toast" id="tgToast"></div>

<!-- Модалка анкеты -->
<div class="modal-overlay" id="setupModal">
    <div class="modal-card trainer-setup-card">
        <div class="modal-head">
            <h3>Новый тренировочный заказ</h3>
            <button type="button" class="modal-close" onclick="closeSetupModal()" aria-label="Закрыть"><span data-ic="x"></span></button>
        </div>
        <label>Имя клиента</label>
        <div class="setup-row">
            <input type="text" id="setupClientName" placeholder="Например: Даниил">
            <button type="button" class="mini-btn" id="btnRandomName"><span data-ic="dice"></span>Случайно</button>
        </div>

        <label>Сложность</label>
        <div class="setup-pills" id="setupDifficulty">
            <button type="button" class="setup-pill active" data-val="easy">Легко</button>
            <button type="button" class="setup-pill" data-val="standard">Стандарт</button>
            <button type="button" class="setup-pill" data-val="hard">Сложно</button>
        </div>

        <label>Тема заказа</label>
        <div class="setup-pills" id="setupTopicPills">
            <button type="button" class="setup-pill active" data-val="Превью Standoff 2">Превью Standoff 2</button>
            <button type="button" class="setup-pill" data-val="Шапка Dota 2">Шапка Dota 2</button>
            <button type="button" class="setup-pill" data-val="Аватарка CS2">Аватарка CS2</button>
        </div>
        <input type="text" id="setupTopicCustom" placeholder="Или своя тема заказа...">

        <button type="button" class="trainer-start-btn" id="btnStartSession">Начать заказ<span data-ic="arrow-right"></span></button>
    </div>
</div>

<!-- Экран результата -->
<div class="modal-overlay" id="resultModal">
    <div class="modal-card">
        <div class="modal-head">
            <h3>Результат сдачи</h3>
            <button type="button" class="modal-close" onclick="closeResultModal()" aria-label="Закрыть"><span data-ic="x"></span></button>
        </div>
        <div class="trainer-score-ring" id="resultScoreCircle" style="--pct:0;">
            <div class="tsr-label"><span class="tsr-num" id="resultScoreNum">–</span><span class="tsr-max">из 100</span></div>
        </div>
        <div class="trs-section trs-pros" id="resultProsWrap"><h4><span data-ic="circle-check"></span>Что сделано отлично</h4><ul id="resultPros"></ul></div>
        <div class="trs-section trs-cons" id="resultConsWrap"><h4><span data-ic="alert"></span>За что сняты баллы</h4><ul id="resultCons"></ul></div>
        <div class="trs-review" id="resultReviewBox">
            <span class="trs-smile" id="resultSmile"></span>
            <div><div class="trs-review-label">Отзыв клиента</div><p id="resultReviewText"></p></div>
        </div>
        <div id="resultKostlimFeedback" class="trainer-kostlim-feedback" style="display:none;"></div>
        <button type="button" class="trainer-start-btn" id="btnShareAdmin"><span data-ic="send"></span>Поделиться с Kostlim</button>
    </div>
</div>

<script>
const API = 'ai_trainer_api.php';
let currentSessionId = null;
let pendingSubmitFile = null;
let allSessions = [];

/* ───────── Иконки (минималистичные, stroke) ───────── */
const IC = {
 'arrow-left': '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
 'arrow-right': '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
 'square-pen': '<path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/>',
 'search': '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
 'x': '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
 'paperclip': '<path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/>',
 'send': '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>',
 'check': '<path d="M20 6 9 17l-5-5"/>',
 'check-check': '<path d="M18 6 7 17l-5-5"/><path d="m22 10-7.5 7.5L13 16"/>',
 'clock': '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
 'flame': '<path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/>',
 'upload': '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/>',
 'gamepad': '<path d="M6 11h4"/><path d="M8 9v4"/><path d="M15 12h.01"/><path d="M18 10h.01"/><path d="M17.32 5H6.68a4 4 0 0 0-3.978 3.59c-.006.052-.01.101-.017.152C2.604 9.416 2 14.456 2 16a3 3 0 0 0 3 3c1 0 1.5-.5 2-1l1.414-1.414A2 2 0 0 1 9.828 16h4.344a2 2 0 0 1 1.414.586L17 18c.5.5 1 1 2 1a3 3 0 0 0 3-3c0-1.545-.604-6.584-.685-7.258-.007-.05-.011-.1-.017-.151A4 4 0 0 0 17.32 5z"/>',
 'message': '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
 'wallet': '<path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>',
 'dice': '<rect width="12" height="12" x="2" y="10" rx="2" ry="2"/><path d="m17.92 14 3.5-3.5a2.24 2.24 0 0 0 0-3l-5-4.92a2.24 2.24 0 0 0-3 .02L10 6"/><path d="M6 18h.01"/><path d="M10 14h.01"/><path d="M15 6h.01"/><path d="M18 9h.01"/>',
 'alert': '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
 'circle-check': '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
 'image': '<rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>',
 'thumbs-up': '<path d="M7 10v12"/><path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z"/>',
 'help': '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>'
};
function ic(n, cls) {
    return '<svg class="ic' + (cls ? ' ' + cls : '') + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (IC[n] || '') + '</svg>';
}
document.querySelectorAll('[data-ic]').forEach(el => { el.innerHTML = ic(el.dataset.ic); });
function setBtn(btn, icon, text) { btn.innerHTML = (icon ? ic(icon) : '') + esc(text); }

function esc(s){ const d=document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }
async function api(action, payload = {}, isFormData = false) {
    let opts;
    if (isFormData) {
        payload.append('action', action);
        opts = { method: 'POST', body: payload };
    } else {
        opts = { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({action, ...payload}) };
    }
    const res = await fetch(API, opts);
    return res.json();
}

/* ───────── Время / аватары / тосты ───────── */
const nowTs = () => Math.floor(Date.now() / 1000);
function fmtHM(ts){ return new Date(ts * 1000).toLocaleTimeString('ru-RU', {hour:'2-digit', minute:'2-digit'}); }
function dayKey(ts){ const d = new Date(ts * 1000); return d.getFullYear() + '-' + d.getMonth() + '-' + d.getDate(); }
function startOfDay(d){ return new Date(d.getFullYear(), d.getMonth(), d.getDate()); }
function daysAgo(ts){ return Math.round((startOfDay(new Date()) - startOfDay(new Date(ts * 1000))) / 86400000); }
function dayLabel(ts){
    const n = daysAgo(ts);
    if (n === 0) return 'Сегодня';
    if (n === 1) return 'Вчера';
    return new Date(ts * 1000).toLocaleDateString('ru-RU', {day:'numeric', month:'long'});
}
function listTime(ts){
    if (!ts) return '';
    const n = daysAgo(ts);
    if (n === 0) return fmtHM(ts);
    if (n === 1) return 'вчера';
    if (n < 7) return new Date(ts * 1000).toLocaleDateString('ru-RU', {weekday:'short'});
    return new Date(ts * 1000).toLocaleDateString('ru-RU', {day:'2-digit', month:'2-digit', year:'2-digit'});
}
// Аватарки — тёплые оттенки палитры сайта
const AVA = [['#fb923c','#f97316'],['#fdba74','#fb923c'],['#f97316','#c2410c'],['#fbbf24','#f97316'],['#ea580c','#9a3412'],['#fb923c','#7c2d12']];
function avaStyle(name){ let h = 0; for (const c of String(name || '')) h = (h * 31 + c.charCodeAt(0)) >>> 0; const p = AVA[h % AVA.length]; return '--ava-a:' + p[0] + ';--ava-b:' + p[1]; }
function avaLetter(name){ return esc(String(name || 'К').trim().charAt(0) || 'К'); }
let toastTimer = null;
function toast(msg){
    const t = document.getElementById('tgToast');
    t.textContent = msg; t.classList.add('show');
    clearTimeout(toastTimer); toastTimer = setTimeout(() => t.classList.remove('show'), 3200);
}

/* ───────── Модалки / навигация ───────── */
function openSetupModal(){ document.getElementById('setupModal').classList.add('show'); }
function closeSetupModal(){ document.getElementById('setupModal').classList.remove('show'); }
function closeResultModal(){ document.getElementById('resultModal').classList.remove('show'); }
function closeChatScreen(){
    document.getElementById('tgApp').classList.remove('in-chat');
    loadSessions();
}
document.querySelectorAll('.js-new-order').forEach(b => b.onclick = openSetupModal);

// Блок 4.2 ТЗ: анимированная заливка кольца от 0 до итогового счёта +
// цвет по диапазону оценки (красный/оранжевый/зелёный).
function animateScoreRing(score) {
    const ring = document.getElementById('resultScoreCircle');
    const numEl = document.getElementById('resultScoreNum');
    const color = score >= 80 ? '#22C55E' : score >= 50 ? '#FFC53D' : '#EF4444';
    ring.style.setProperty('--score-color', color);
    ring.style.setProperty('--pct', 0);
    numEl.textContent = '0';
    const duration = 900;
    let start = null;
    function step(ts) {
        if (!start) start = ts;
        const progress = Math.min((ts - start) / duration, 1);
        const val = Math.round(progress * score);
        ring.style.setProperty('--pct', val);
        numEl.textContent = val;
        if (progress < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
}

document.querySelectorAll('#setupDifficulty .setup-pill').forEach(btn => {
    btn.onclick = () => { document.querySelectorAll('#setupDifficulty .setup-pill').forEach(b=>b.classList.remove('active')); btn.classList.add('active'); };
});
document.querySelectorAll('#setupTopicPills .setup-pill').forEach(btn => {
    btn.onclick = () => {
        document.querySelectorAll('#setupTopicPills .setup-pill').forEach(b=>b.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('setupTopicCustom').value = '';
    };
});

const randomNames = ['Даниил','Марк','Артём','Илья','Тимур','Максим','Кирилл','Егор'];
document.getElementById('btnRandomName').onclick = () => {
    document.getElementById('setupClientName').value = randomNames[Math.floor(Math.random()*randomNames.length)];
};

document.getElementById('btnStartSession').onclick = async () => {
    const btn = document.getElementById('btnStartSession');
    const difficulty = document.querySelector('#setupDifficulty .setup-pill.active').dataset.val;
    const topicCustom = document.getElementById('setupTopicCustom').value.trim();
    const topic = topicCustom || document.querySelector('#setupTopicPills .setup-pill.active').dataset.val;
    const clientName = document.getElementById('setupClientName').value.trim() || randomNames[0];

    btn.disabled = true; btn.textContent = 'Клиент печатает...';
    let r;
    try {
        r = await api('start_session', { client_name: clientName, difficulty, topic });
    } catch (e) {
        btn.disabled = false; btn.innerHTML = 'Начать заказ' + ic('arrow-right');
        alert('Ошибка сети, попробуйте ещё раз.');
        return;
    }
    btn.disabled = false; btn.innerHTML = 'Начать заказ' + ic('arrow-right');
    if (!r.ok) { alert(r.error || 'Ошибка'); return; }

    currentSessionId = r.session_id;
    closeSetupModal();
    openChatWithHistory(r);
    loadSessions();
};

/* ───────── Переписка ───────── */
let lastRole = null, lastDay = null, lastBubble = null;

function setChatStatus(typing){
    const el = document.getElementById('chatStatus');
    el.textContent = typing ? 'печатает…' : 'в сети';
    el.className = 'tg-status ' + (typing ? 'typing' : 'online');
}
function scrollDown(smooth){
    const b = document.getElementById('chatBody');
    if (smooth === false) { b.style.scrollBehavior = 'auto'; b.scrollTop = b.scrollHeight; b.style.scrollBehavior = ''; }
    else b.scrollTop = b.scrollHeight;
}

function openChatWithHistory(sessionData) {
    document.getElementById('chatClientName').textContent = sessionData.client_name;
    document.getElementById('chatTopicLabel').textContent = sessionData.topic;
    const ava = document.getElementById('chatAva');
    ava.textContent = String(sessionData.client_name || 'К').trim().charAt(0) || 'К';
    ava.setAttribute('style', avaStyle(sessionData.client_name));
    const diffLabels = {easy:'Легко', standard:'Стандарт', hard:'Сложно'};
    document.getElementById('chatDifficultyBadge').textContent = diffLabels[sessionData.difficulty] || sessionData.difficulty;
    setChatStatus(false);
    resetAttach();

    const body = document.getElementById('chatBody');
    body.innerHTML = '';
    lastRole = null; lastDay = null; lastBubble = null;
    (sessionData.messages || []).forEach(addMessageBubble);
    if (sessionData.paid_amount) addPaymentBubble({ amount: sessionData.paid_amount, type: sessionData.payment_type });

    document.getElementById('tgMain').classList.add('has-chat');
    document.getElementById('tgApp').classList.add('in-chat');
    scrollDown(false);
}

function tickHtml(read){ return '<span class="tg-tick' + (read ? ' read' : '') + '">' + ic(read ? 'check-check' : 'check') + '</span>'; }
function markRead(){
    document.querySelectorAll('#chatBody .tg-tick:not(.read)').forEach(t => { t.classList.add('read'); t.innerHTML = ic('check-check'); });
}

function addMessageBubble(m) {
    const body = document.getElementById('chatBody');
    const dir = m.role === 'client' ? 'in' : 'out';
    const ts = Number(m.ts) || nowTs();

    const dk = dayKey(ts);
    if (dk !== lastDay) {
        const pill = document.createElement('div');
        pill.className = 'tg-day'; pill.textContent = dayLabel(ts);
        body.appendChild(pill);
        lastDay = dk; lastRole = null; lastBubble = null;
    }

    const grouped = lastRole === dir;
    if (grouped && lastBubble) lastBubble.classList.remove('tail');
    if (dir === 'in') markRead();

    const row = document.createElement('div');
    row.className = 'tg-row tg-row--' + dir + (grouped ? ' tg-grouped' : '');

    // страховка: даже если сервер пропустил маркер оплаты — не показываем его сырым
    const shown = (m.content || '').replace(/[`\s]*\[\s*PAYMENT_SUCCESS\b[^\]]*\][`]*/gi, '').trim();
    let html = '';
    if (m.attachment_url) html += '<a href="' + esc(m.attachment_url) + '" target="_blank" rel="noopener"><img src="' + esc(m.attachment_url) + '" class="tg-img" loading="lazy" alt=""></a>';
    html += '<div class="tg-text">' + esc(shown).replace(/\n/g, '<br>') +
            '<span class="tg-meta">' + fmtHM(ts) + (dir === 'out' ? tickHtml(false) : '') + '</span></div>';

    const bubble = document.createElement('div');
    bubble.className = 'tg-bubble tg-' + dir + ' tail' + (m.attachment_url ? ' has-img' : '');
    bubble.innerHTML = html;
    row.appendChild(bubble);
    body.appendChild(row);
    lastRole = dir; lastBubble = bubble;
    scrollDown();
}

// Маркер [PAYMENT_SUCCESS:...] — красивой системной карточкой, а не сырым текстом
// (см. ai_trainer_api.php::extractPaymentMarker).
function addPaymentBubble(payment) {
    const body = document.getElementById('chatBody');
    const div = document.createElement('div');
    div.className = 'tg-pay';
    const amount = Number(payment.amount || 0).toLocaleString('ru-RU');
    div.innerHTML = '<div class="tg-pay-ico">' + ic('wallet') + '</div>' +
        '<div class="tg-pay-main"><div class="tg-pay-title">Перевод от клиента</div>' +
        '<div class="tg-pay-amount">+' + amount + ' ₽ <span>(' + esc(payment.type || 'Предоплата') + ')</span></div></div>' +
        '<div class="tg-pay-ok">' + ic('circle-check') + 'Успешно</div>';
    body.appendChild(div);
    lastRole = null; lastBubble = null;
    scrollDown();
}

function showTypingBubble(){
    const body = document.getElementById('chatBody');
    const grouped = lastRole === 'in';
    if (grouped && lastBubble) lastBubble.classList.remove('tail');
    const row = document.createElement('div');
    row.className = 'tg-row tg-row--in' + (grouped ? ' tg-grouped' : ''); row.id = 'tgTyping';
    row.innerHTML = '<div class="tg-bubble tg-in tail"><div class="tg-typing"><span></span><span></span><span></span></div></div>';
    body.appendChild(row); scrollDown(); setChatStatus(true);
}
function hideTypingBubble(){
    const t = document.getElementById('tgTyping');
    if (t) t.remove();
    if (lastBubble && lastRole === 'in') lastBubble.classList.add('tail');
    setChatStatus(false);
}

// Смайлик отзыва клиента: красный / жёлтый / зелёный — минималистичный SVG.
function sentimentSmile(sentiment) {
    const color = sentiment === 'green' ? '#22C55E' : sentiment === 'yellow' ? '#FFC53D' : '#EF4444';
    const mouth = sentiment === 'green' ? 'M9 21c2 3 10 3 12 0' : sentiment === 'yellow' ? 'M10 22h10' : 'M9 24c2-3 10-3 12 0';
    return `<svg viewBox="0 0 30 30" width="34" height="34" fill="none" stroke="${color}" stroke-width="2" stroke-linecap="round">
        <circle cx="15" cy="15" r="13"/><circle cx="10.5" cy="12" r="1" fill="${color}"/><circle cx="19.5" cy="12" r="1" fill="${color}"/><path d="${mouth}"/></svg>`;
}

// Единая отрисовка окна результата (после сдачи и при повторном открытии).
function showResult(r) {
    animateScoreRing(r.score);
    const fill = (id, wrapId, list) => {
        const ul = document.getElementById(id);
        ul.innerHTML = (list || []).map(t => `<li>${esc(t)}</li>`).join('');
        document.getElementById(wrapId).style.display = (list && list.length) ? 'block' : 'none';
    };
    fill('resultPros', 'resultProsWrap', r.pros);
    fill('resultCons', 'resultConsWrap', r.cons);
    const sentiment = r.sentiment || (r.score >= 80 ? 'green' : r.score >= 50 ? 'yellow' : 'red');
    document.getElementById('resultSmile').innerHTML = sentimentSmile(sentiment);
    document.getElementById('resultReviewText').textContent = r.review || '';
    document.getElementById('resultModal').classList.add('show');
}

async function sendMessage() {
    const input = document.getElementById('chatInput');
    const text = input.value.trim();
    if (!text || !currentSessionId) return;
    addMessageBubble({role:'designer', content:text});
    input.value = ''; updateSendState();
    showTypingBubble();

    let r;
    try {
        r = await api('send_message', { session_id: currentSessionId, content: text });
    } catch (e) {
        r = { ok: false };
    }
    hideTypingBubble();
    if (r.ok) {
        addMessageBubble({role:'client', content: r.reply});
        if (r.payment) addPaymentBubble(r.payment);
    }
    else addMessageBubble({role:'client', content: 'Не удалось получить ответ, попробуй ещё раз.'});
    loadSessions();
}
function updateSendState(){
    document.getElementById('btnSendMsg').classList.toggle('ready', !!document.getElementById('chatInput').value.trim());
}
document.getElementById('btnSendMsg').onclick = sendMessage;
document.getElementById('chatInput').addEventListener('input', updateSendState);
document.getElementById('chatInput').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); sendMessage(); } });

/* ───────── Сдача работы ───────── */
function resetAttach(){
    pendingSubmitFile = null;
    document.getElementById('submitFileInput').value = '';
    document.getElementById('attachChip').classList.remove('show');
}
document.getElementById('btnAttachSubmit').onclick = () => document.getElementById('submitFileInput').click();
document.getElementById('submitFileInput').onchange = (e) => {
    pendingSubmitFile = e.target.files[0] || null;
    if (!pendingSubmitFile) { resetAttach(); return; }
    document.getElementById('attachThumb').src = URL.createObjectURL(pendingSubmitFile);
    document.getElementById('attachName').textContent = pendingSubmitFile.name;
    document.getElementById('attachChip').classList.add('show');
};
document.getElementById('btnAttachRemove').onclick = resetAttach;

document.getElementById('btnSubmitWork').onclick = async () => {
    if (!currentSessionId) return;
    if (!pendingSubmitFile) {
        toast('Прикрепи превью работы скрепкой, затем снова нажми «Сдать работу».');
        document.getElementById('submitFileInput').click();
        return;
    }
    const btn = document.getElementById('btnSubmitWork');
    btn.disabled = true; btn.innerHTML = '<span class="tg-lbl">ИИ оценивает…</span>';

    const fd = new FormData();
    fd.append('session_id', currentSessionId);
    fd.append('file', pendingSubmitFile);
    let r;
    try {
        r = await api('submit_work', fd, true);
    } catch (e) {
        r = { ok: false, error: 'Ошибка сети' };
    }

    btn.disabled = false; btn.innerHTML = ic('upload') + '<span class="tg-lbl">Сдать работу</span>';
    if (!r.ok) { alert(r.error || 'Ошибка оценки'); return; }

    addMessageBubble({role:'designer', content:'Сдал работу на проверку', attachment_url: r.attachment_url});
    const kb = document.getElementById('resultKostlimFeedback'); if (kb) kb.style.display = 'none';
    const sb = document.getElementById('btnShareAdmin'); sb.disabled = false; setBtn(sb, 'send', 'Поделиться с Kostlim');
    showResult(r);
    resetAttach();
    loadSessions();
};

document.getElementById('btnShareAdmin').onclick = async () => {
    const btn = document.getElementById('btnShareAdmin');
    // FIX: после успешной отправки кнопка остаётся заблокированной насовсем
    // (иначе повторные клики слали дубль уведомления админу); разблокируем
    // только если сама отправка не удалась.
    btn.disabled = true; btn.textContent = 'Отправка...';
    let r;
    try { r = await api('share_with_admin', { session_id: currentSessionId }); } catch (e) { r = { ok: false }; }
    if (r.ok) {
        btn.disabled = true;
        setBtn(btn, 'check', 'Отправлено');
    } else {
        btn.disabled = false;
        btn.textContent = 'Ошибка, повторить';
    }
};

/* ───────── Список чатов ───────── */
function reactionIcon(r) {
    return ic(r === 'fire' ? 'flame' : r === 'like' ? 'thumbs-up' : r === 'think' ? 'help' : 'message');
}
function previewHtml(s){
    const clean = String(s.last_message || '').replace(/[`\s]*\[\s*PAYMENT_SUCCESS\b[^\]]*\][`]*/gi, '').replace(/\s+/g, ' ').trim();
    const prefix = s.last_role === 'designer' ? '<b>Вы:</b>' : '';
    if (clean) return prefix + '<span>' + esc(clean) + '</span>';
    if (s.last_attach) return prefix + ic('image') + '<span>Фото</span>';
    if (s.status === 'scored') return '<span>Оценено: ' + esc(s.score) + '/100</span>';
    return '<span>Нет сообщений</span>';
}
function sessionRowHtml(s){
    const sc = s.status === 'scored' ? Number(s.score) : null;
    const cls = sc === null ? '' : (sc >= 80 ? 's-good' : sc >= 50 ? 's-mid' : 's-bad');
    let badges = '';
    if (s.admin_reaction || s.admin_comment) badges += '<span class="tg-flame" title="Отзыв от Kostlim">' + reactionIcon(s.admin_reaction) + '</span>';
    if (sc !== null) badges += '<span class="tg-score ' + cls + '">' + sc + '</span>';
    else if (s.status === 'submitted') badges += '<span class="tg-clock" title="На проверке">' + ic('clock') + '</span>';
    else badges += '<span class="tg-live" title="В процессе"></span>';
    return '<div class="tg-item' + (Number(s.id) === Number(currentSessionId) ? ' active' : '') + '" onclick="resumeSession(' + Number(s.id) + ')">' +
        '<div class="tg-ava" style="' + avaStyle(s.client_name) + '">' + avaLetter(s.client_name) + '</div>' +
        '<div class="tg-item-main">' +
            '<div class="tg-item-top"><div class="tg-item-name">' + esc(s.client_name) + ' <small>· ' + esc(s.topic) + '</small></div><span class="tg-item-time">' + listTime(Number(s.ts)) + '</span></div>' +
            '<div class="tg-item-bot"><div class="tg-item-preview">' + previewHtml(s) + '</div><div class="tg-item-badges">' + badges + '</div></div>' +
        '</div></div>';
}
function renderSessions(){
    const wrap = document.getElementById('trainerSessionsList');
    const q = document.getElementById('tgSearch').value.trim().toLowerCase();
    const list = q ? allSessions.filter(s => (s.client_name + ' ' + s.topic).toLowerCase().includes(q)) : allSessions;
    if (!list.length) {
        wrap.innerHTML = '<div class="tg-list-empty">' + ic('message') + (q ? 'Ничего не найдено' : 'Пока нет тренировок.<br>Нажми на кнопку внизу и начни первый заказ.') + '</div>';
        return;
    }
    wrap.innerHTML = list.map(sessionRowHtml).join('');
}
async function loadSessions() {
    let r;
    try { r = await api('list_sessions'); } catch (e) { return; }
    allSessions = (r && r.ok && r.sessions) ? r.sessions : [];
    renderSessions();
}
document.getElementById('tgSearch').addEventListener('input', renderSessions);

async function resumeSession(id) {
    const r = await api('get_session', { session_id: id });
    if (!r.ok) return;
    currentSessionId = id;
    openChatWithHistory(r);
    renderSessions();
    if (r.status === 'scored') {
        // Блок 4.3 ТЗ: реакция/комментарий Kostlim — в модалке результата с меткой «Отзыв от Kostlim».
        const kostlimBox = document.getElementById('resultKostlimFeedback');
        if (r.admin_reaction || r.admin_comment) {
            kostlimBox.innerHTML = '<strong>' + reactionIcon(r.admin_reaction) + 'Отзыв от Kostlim</strong>' + (r.admin_comment ? '<p>' + esc(r.admin_comment) + '</p>' : '');
            kostlimBox.style.display = 'block';
        } else {
            kostlimBox.style.display = 'none';
        }
        showResult(r);
        // Реоткрытие уже расшаренного результата — сразу «Отправлено», повторно не шлём.
        const shareBtn = document.getElementById('btnShareAdmin');
        if (r.shared_with_admin) { shareBtn.disabled = true; setBtn(shareBtn, 'check', 'Отправлено'); }
        else { shareBtn.disabled = false; setBtn(shareBtn, 'send', 'Поделиться с Kostlim'); }
    }
}

loadSessions();
</script>
</body>
</html>
