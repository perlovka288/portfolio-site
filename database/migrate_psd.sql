-- ============================================================
-- Миграция: таблица portfolio_psd (до 3 PSD к каждой работе)
-- ============================================================

CREATE TABLE IF NOT EXISTS portfolio_psd (
    id SERIAL PRIMARY KEY,
    portfolio_id INT NOT NULL REFERENCES portfolio(id) ON DELETE CASCADE,
    psd_file TEXT NOT NULL,
    original_name TEXT NOT NULL DEFAULT '',
    file_size BIGINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_portfolio_psd_portfolio ON portfolio_psd(portfolio_id);

-- ============================================================
-- Миграция: таблица для админ-команд бота
-- ============================================================

CREATE TABLE IF NOT EXISTS bot_commands (
    id SERIAL PRIMARY KEY,
    command VARCHAR(100) NOT NULL UNIQUE,
    description TEXT NOT NULL DEFAULT '',
    access_level VARCHAR(20) NOT NULL DEFAULT 'admin',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Стандартные админ-команды
INSERT INTO bot_commands (command, description, access_level) VALUES
    ('mute', '⛔ /mute @username [часы] — заблокировать пользователю отправку сообщений', 'admin'),
    ('warn', '⚠️ /warn @username [причина] — выдать предупреждение пользователю', 'admin'),
    ('ban', '🚫 /ban @username [причина] — заблокировать пользователя', 'admin'),
    ('unban', '✅ /unban @username — разблокировать пользователя', 'admin'),
    ('kick', '👢 /kick @username — исключить пользователя из группы', 'admin'),
    ('admin', '⚙️ /admin — открыть админ-панель', 'admin'),
    ('stats', '📊 /stats — статистика по заказам', 'admin'),
    ('help', '📖 /help — список доступных команд', 'admin')
ON CONFLICT (command) DO NOTHING;

-- ============================================================
-- Миграция: таблица для мутов/варнов/банов (moderation)
-- ============================================================

CREATE TABLE IF NOT EXISTS moderation (
    id SERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL,
    username VARCHAR(255) DEFAULT '',
    type VARCHAR(50) NOT NULL, -- 'mute', 'warn', 'ban'
    reason TEXT DEFAULT '',
    duration_minutes INT DEFAULT 0, -- 0 = навсегда
    issued_by BIGINT NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP DEFAULT NULL
);

CREATE INDEX IF NOT EXISTS idx_moderation_user ON moderation(user_id);
CREATE INDEX IF NOT EXISTS idx_moderation_active ON moderation(user_id, is_active);

-- ============================================================
-- Обновление существующей таблицы portfolio
-- ============================================================

ALTER TABLE portfolio ADD COLUMN IF NOT EXISTS psd_dir TEXT DEFAULT NULL;