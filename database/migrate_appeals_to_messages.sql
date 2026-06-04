-- Migration: ensure appeals + appeals_messages exist, migrate old columns (message, reply) -> appeals_messages

BEGIN;

-- Ensure appeals table exists (compatible definition)
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

-- Ensure messages table exists
CREATE TABLE IF NOT EXISTS appeals_messages (
    id SERIAL PRIMARY KEY,
    appeal_id INT NOT NULL REFERENCES appeals(id) ON DELETE CASCADE,
    author VARCHAR(20) NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_appeals_order ON appeals(order_id);
CREATE INDEX IF NOT EXISTS idx_messages_appeal ON appeals_messages(appeal_id);

-- Migrate old inline columns if present: message (client) and reply (admin)
DO $$
BEGIN
    -- migrate client "message" column
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'appeals'
          AND column_name = 'message'
    ) THEN
        INSERT INTO appeals_messages (appeal_id, author, message, created_at)
        SELECT id, 'client', message, COALESCE(created_at, NOW())
        FROM appeals
        WHERE COALESCE(message, '') <> '';
    END IF;

    -- migrate admin "reply" column
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'appeals'
          AND column_name = 'reply'
    ) THEN
        INSERT INTO appeals_messages (appeal_id, author, message, created_at)
        SELECT id, 'admin', reply, COALESCE(replied_at, NOW())
        FROM appeals
        WHERE COALESCE(reply, '') <> '';
    END IF;

    -- safe drop of old columns (only if they exist)
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'appeals'
          AND column_name = 'message'
    ) THEN
        ALTER TABLE appeals DROP COLUMN IF EXISTS message;
    END IF;

    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'appeals'
          AND column_name = 'reply'
    ) THEN
        ALTER TABLE appeals DROP COLUMN IF EXISTS reply;
    END IF;
END$$;

COMMIT;

-- End of migration
