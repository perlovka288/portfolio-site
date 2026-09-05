-- ============================================================
-- МИГРАЦИЯ: Реферальная программа + напоминалка постоянным клиентам
-- ВАЖНО: это СПРАВОЧНЫЙ файл — сам код (ensureReferralSchema() и
-- ensureOrderFlowSchema() в includes/order_flow.php, вызываются из
-- bot.php на каждый запрос) уже создаёт всё это АВТОМАТИЧЕСКИ.
-- Запускать этот файл руками нужно, только если по какой-то причине
-- хочешь применить миграцию заранее / вручную.
-- ============================================================

SET NAMES utf8mb4;

-- ────────────────────────────────────────────────────────────
-- 1. РЕФЕРАЛЬНАЯ ПРОГРАММА
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS referral_users (
    chat_id VARCHAR(64) PRIMARY KEY,
    ref_code VARCHAR(20) NOT NULL,
    referred_by_chat_id VARCHAR(64) DEFAULT NULL,
    invited_count INT NOT NULL DEFAULT 0,
    bonus_percent INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    CONSTRAINT uniq_referral_ref_code UNIQUE (ref_code)
);

CREATE TABLE IF NOT EXISTS referral_awards (
    id SERIAL PRIMARY KEY,
    referrer_chat_id VARCHAR(64) NOT NULL,
    referred_chat_id VARCHAR(64) NOT NULL,
    order_id INT DEFAULT NULL,
    awarded_at TIMESTAMP NOT NULL DEFAULT NOW(),
    CONSTRAINT uniq_referral_award_friend UNIQUE (referred_chat_id)
);

-- Настройки бонуса (необязательно — если не заполнить, код использует
-- значения по умолчанию: +5% за друга, максимум 30%):
-- INSERT INTO site_settings (setting_key, value) VALUES ('referral_bonus_percent', '5') ON CONFLICT (setting_key) DO UPDATE SET value = EXCLUDED.value;
-- INSERT INTO site_settings (setting_key, value) VALUES ('referral_bonus_cap_percent', '30') ON CONFLICT (setting_key) DO UPDATE SET value = EXCLUDED.value;

-- ────────────────────────────────────────────────────────────
-- 2. НАПОМИНАЛКА ПОСТОЯННЫМ КЛИЕНТАМ (cron_client_followup.php)
-- ────────────────────────────────────────────────────────────
ALTER TABLE orders ADD COLUMN IF NOT EXISTS followup_sent_at TIMESTAMP DEFAULT NULL;

-- ============================================================
-- ГОТОВО.
--
-- Не забудь добавить в crontab (или в веб-cron-сервис) вызов раз в сутки:
--   php /path/to/cron_client_followup.php
-- или
--   https://твой-сайт/cron_client_followup.php?secret=ТУТ_СЕКРЕТ
--
-- Через сколько дней после сдачи работы слать напоминание — переменная
-- окружения FOLLOWUP_DAYS (по умолчанию 45).
-- ============================================================
