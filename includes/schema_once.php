<?php
/**
 * «Один раз на контейнер»: защита от повторных CREATE/ALTER TABLE на КАЖДОМ запросе.
 *
 * Раньше каждая страница (главная, профиль, заказ, админка…) при каждой загрузке гоняла десятки
 * ensure*Schema()/CREATE TABLE IF NOT EXISTS к удалённой БД — это 1–3 секунды на открытие любой
 * страницы. Теперь схема проверяется один раз после деплоя (новый контейнер = новый прогон),
 * дальше функции возвращаются мгновенно.
 *
 * Отключить (вернуть старое поведение): переменная окружения KUI_SCHEMA_ALWAYS=1.
 * Сбросить вручную: удалить папку /tmp/kui_schema_* на сервере (или просто передеплоить).
 */
if (!function_exists('kuiSchemaDir')) {
    function kuiSchemaDir(): string
    {
        static $dir = null;
        if ($dir !== null) { return $dir; }
        $ver = (string)(getenv('RENDER_GIT_COMMIT') ?: getenv('SOURCE_VERSION') ?: '');
        $dir = rtrim(sys_get_temp_dir(), '/\\') . '/kui_schema_' . substr(md5(dirname(__DIR__) . $ver), 0, 10);
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        return $dir;
    }
    function kuiSchemaDone(string $key): bool
    {
        if (getenv('KUI_SCHEMA_ALWAYS')) { return false; }
        return is_file(kuiSchemaDir() . '/' . preg_replace('/\W+/', '_', $key));
    }
    function kuiSchemaMark(string $key): void
    {
        @file_put_contents(kuiSchemaDir() . '/' . preg_replace('/\W+/', '_', $key), (string)time());
    }
    /** Выполнить $fn один раз (пока не упадёт с исключением — тогда попробует снова). */
    function kuiSchemaOnce(string $key, callable $fn): void
    {
        if (kuiSchemaDone($key)) { return; }
        $fn();
        kuiSchemaMark($key);
    }
}
