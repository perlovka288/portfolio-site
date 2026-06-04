-- Add appeals and appeals_messages tables

CREATE TABLE IF NOT EXISTS appeals (
    id SERIAL PRIMARY KEY,
    order_id INT NOT NULL,
    username VARCHAR(255) DEFAULT '',
    telegram VARCHAR(255) DEFAULT '',
    subject VARCHAR(255) DEFAULT '',
    status VARCHAR(40) DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    replied_at TIMESTAMP NULL
);

CREATE TABLE IF NOT EXISTS appeals_messages (
    id SERIAL PRIMARY KEY,
    appeal_id INT NOT NULL REFERENCES appeals(id) ON DELETE CASCADE,
    author VARCHAR(20) NOT NULL, -- 'client' or 'admin'
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- optional index for faster lookups
CREATE INDEX IF NOT EXISTS idx_appeals_order ON appeals(order_id);
CREATE INDEX IF NOT EXISTS idx_messages_appeal ON appeals_messages(appeal_id);
