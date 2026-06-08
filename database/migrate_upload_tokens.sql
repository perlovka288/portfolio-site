-- Одноразовые токены для загрузки больших файлов через браузер (до 300 МБ)

CREATE TABLE IF NOT EXISTS upload_tokens (
    id SERIAL PRIMARY KEY,
    token VARCHAR(64) NOT NULL UNIQUE,
    purpose VARCHAR(30) NOT NULL DEFAULT 'psd_upload',
    portfolio_id INT DEFAULT NULL,
    file_slot INT NOT NULL DEFAULT 0,
    chat_id BIGINT NOT NULL,
    admin_tg_id BIGINT NOT NULL,
    original_name TEXT DEFAULT '',
    stored_file TEXT DEFAULT '',
    file_size BIGINT NOT NULL DEFAULT 0,
    max_size BIGINT NOT NULL DEFAULT 314572800,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_upload_tokens_status ON upload_tokens(status, expires_at);
