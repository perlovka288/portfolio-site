-- YouTube Parser: таблица каналов
-- Выполнить: psql $DATABASE_URL -f sql/20250615_add_channels_table.sql

CREATE TABLE IF NOT EXISTS channels (
    id SERIAL PRIMARY KEY,
    channel_id VARCHAR(100) UNIQUE NOT NULL,
    channel_name VARCHAR(255) NOT NULL,
    channel_url TEXT NOT NULL,
    video_url TEXT,
    preview_url TEXT,
    contacts_tg TEXT,
    contacts_email TEXT,
    contacts_other TEXT,
    subscriber_count INTEGER,
    status VARCHAR(50) DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_channels_channel_id ON channels(channel_id);
CREATE INDEX IF NOT EXISTS idx_channels_status ON channels(status);
CREATE INDEX IF NOT EXISTS idx_channels_created_at ON channels(created_at);
