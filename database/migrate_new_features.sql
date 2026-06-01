-- ============================================================
-- МИГРАЦИЯ: Новые фичи Kostlim Design
-- Запусти этот файл ОДИН РАЗ в phpMyAdmin или через mysql CLI
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ────────────────────────────────────────────────────────────
-- 1. СРОЧНЫЕ ЗАКАЗЫ
--    Добавляем столбцы к уже существующей таблице orders
-- ────────────────────────────────────────────────────────────
ALTER TABLE `orders`
    ADD COLUMN IF NOT EXISTS `is_urgent`        TINYINT(1)   NOT NULL DEFAULT 0    COMMENT '1 = срочный заказ (24ч)',
    ADD COLUMN IF NOT EXISTS `urgent_deadline`  DATETIME     NULL DEFAULT NULL     COMMENT 'Дедлайн срочного заказа',
    ADD COLUMN IF NOT EXISTS `last_reminded_at` DATETIME     NULL DEFAULT NULL     COMMENT 'Последнее напоминание о срочном заказе',
    ADD COLUMN IF NOT EXISTS `client_ip`        VARCHAR(45)  NULL DEFAULT NULL     COMMENT 'IP-адрес клиента при отправке заказа';

-- Индекс для быстрой выборки срочных заказов
CREATE INDEX IF NOT EXISTS `idx_orders_urgent`
    ON `orders` (`is_urgent`, `status`);

-- ────────────────────────────────────────────────────────────
-- 2. ЧЁРНЫЙ СПИСОК (защита от спама)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `blacklist` (
    `id`         INT(11)      NOT NULL AUTO_INCREMENT,
    `telegram`   VARCHAR(128) NULL     DEFAULT NULL COMMENT 'Telegram username без @',
    `ip`         VARCHAR(45)  NULL     DEFAULT NULL COMMENT 'IP-адрес',
    `order_id`   INT(11)      NULL     DEFAULT NULL COMMENT 'ID заказа-основания',
    `reason`     VARCHAR(255) NULL     DEFAULT NULL COMMENT 'Причина блокировки',
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_telegram` (`telegram`),
    UNIQUE KEY `uniq_ip`       (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 3. ТАБЛИЦА ПОРТФОЛИО
--    Если у тебя уже есть — пропусти этот блок
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `portfolio` (
    `id`         INT(11)       NOT NULL AUTO_INCREMENT,
    `title`      VARCHAR(255)  NOT NULL,
    `price_rub`  INT(11)       NOT NULL DEFAULT 0,
    `price_uah`  INT(11)       NOT NULL DEFAULT 0,
    `category`   VARCHAR(128)  NULL     DEFAULT NULL,
    `image_url`  VARCHAR(512)  NOT NULL,
    `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- 4. ПРОВЕРЯЕМ client_chat_id В orders (на случай если нет)
-- ────────────────────────────────────────────────────────────
ALTER TABLE `orders`
    ADD COLUMN IF NOT EXISTS `client_chat_id` VARCHAR(64) NULL DEFAULT NULL COMMENT 'Telegram chat_id клиента для уведомлений';

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- ГОТОВО! Теперь в orders есть: is_urgent, urgent_deadline,
-- last_reminded_at, client_ip, client_chat_id
-- Создана таблица blacklist и portfolio
-- ============================================================