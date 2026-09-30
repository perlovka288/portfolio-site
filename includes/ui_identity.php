<?php
/**
 * Заполняет для оболочки KUI переменные $isAdmin / $isPackDesigner / $isLinked / $tgProfile,
 * если страница сама их ещё не посчитала (например, order.php).
 * Ничего не ломает: любая ошибка БД тихо игнорируется — оболочка покажет «Гость».
 * Использование:  include __DIR__ . '/includes/ui_identity.php';  (после подключения БД и сессии)
 */
$isAdmin        = !empty($isAdmin) || (isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true);
$isPackDesigner = !empty($isPackDesigner);
$isLinked       = !empty($isLinked);
$tgProfile      = (isset($tgProfile) && is_array($tgProfile)) ? $tgProfile : [];

if (empty($tgProfile) && isset($pdo) && $pdo instanceof PDO) {
    try {
        $__st = $pdo->prepare("SELECT * FROM tg_links WHERE session_id = ? AND linked = TRUE ORDER BY id DESC LIMIT 1");
        $__st->execute([session_id()]);
        $__row = $__st->fetch(PDO::FETCH_ASSOC);
        if ($__row) {
            $tgProfile = $__row;
            $isLinked  = true;
            $__adm = getenv('ADMIN_ID') ?: getenv('ADMIN_TELEGRAM_ID') ?: '';
            if (!$isAdmin && $__adm !== '' && (string)($__row['tg_id'] ?? '') === (string)$__adm) { $isAdmin = true; }
            if (!$isPackDesigner) {
                try {
                    require_once __DIR__ . '/ppk_access.php';
                    $isPackDesigner = !empty(resolvePpkAccess($pdo)['isPackDesigner']);
                } catch (Throwable $__e) {}
            }
        }
    } catch (Throwable $__e) {}
}
