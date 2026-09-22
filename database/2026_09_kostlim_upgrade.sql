-- ============================================================================
-- Kostlim Design — миграция под доработки: бейджи, категории ресурсов,
-- ручная выдача PPK / ключи-активаторы, ИИ-тренажёр клиентов, личный планер.
-- СУБД: PostgreSQL (Neon) — синтаксис под текущий config/db.php проекта.
-- Миграция идемпотентна: можно запускать повторно без ошибок.
-- ============================================================================

-- 1) Категория и порядок сортировки для постов PSD/ресурсов
ALTER TABLE pack_resources ADD COLUMN IF NOT EXISTS category VARCHAR(60) NOT NULL DEFAULT '';

-- 2) Ручная выдача роли PPK (админ-панель, по Telegram ID) — постоянная,
--    не зависит от TTL-проверки участия в группе.
CREATE TABLE IF NOT EXISTS ppk_manual_grants (
    tg_id       VARCHAR(64) PRIMARY KEY,
    granted_by  VARCHAR(64) NOT NULL DEFAULT '',
    note        TEXT NOT NULL DEFAULT '',
    granted_at  TIMESTAMP NOT NULL DEFAULT NOW()
);

-- 3) Одноразовые ключи активации PPK
CREATE TABLE IF NOT EXISTS ppk_activation_keys (
    id            SERIAL PRIMARY KEY,
    code          VARCHAR(64) UNIQUE NOT NULL,
    is_used       BOOLEAN NOT NULL DEFAULT FALSE,
    used_by_tg_id VARCHAR(64) NOT NULL DEFAULT '',
    used_at       TIMESTAMP,
    created_at    TIMESTAMP NOT NULL DEFAULT NOW()
);

-- 4) ИИ-тренажёр клиентов
CREATE TABLE IF NOT EXISTS trainer_sessions (
    id                SERIAL PRIMARY KEY,
    tg_id             VARCHAR(64) NOT NULL,
    designer_name     VARCHAR(150) NOT NULL DEFAULT '',
    client_name       VARCHAR(100) NOT NULL DEFAULT '',
    difficulty        VARCHAR(20)  NOT NULL DEFAULT 'standard', -- easy | standard | hard
    topic             VARCHAR(255) NOT NULL DEFAULT '',
    brief             TEXT NOT NULL DEFAULT '',
    status            VARCHAR(20)  NOT NULL DEFAULT 'active',   -- active | submitted | scored
    score             INT,
    review            TEXT NOT NULL DEFAULT '',
    shared_with_admin BOOLEAN NOT NULL DEFAULT FALSE,
    admin_reaction    VARCHAR(20) NOT NULL DEFAULT '',
    admin_comment     TEXT NOT NULL DEFAULT '',
    created_at        TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at        TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS trainer_messages (
    id             SERIAL PRIMARY KEY,
    session_id     INT NOT NULL REFERENCES trainer_sessions(id) ON DELETE CASCADE,
    role           VARCHAR(10) NOT NULL, -- 'client' (ИИ) | 'designer' (пользователь)
    content        TEXT NOT NULL DEFAULT '',
    attachment_url TEXT NOT NULL DEFAULT '',
    created_at     TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_trainer_messages_session ON trainer_messages(session_id);

-- 5) Личный планер клиентов (для владельцев PPK)
CREATE TABLE IF NOT EXISTS client_planner (
    id           SERIAL PRIMARY KEY,
    owner_tg_id  VARCHAR(64) NOT NULL,
    client_name  VARCHAR(150) NOT NULL DEFAULT '',
    contact      VARCHAR(255) NOT NULL DEFAULT '',
    status       VARCHAR(20) NOT NULL DEFAULT 'in_progress', -- in_progress | revision | paid
    deadline     DATE,
    amount       NUMERIC(10,2) NOT NULL DEFAULT 0,
    notes        TEXT NOT NULL DEFAULT '',
    sort_order   INT NOT NULL DEFAULT 0,
    created_at   TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at   TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_client_planner_owner ON client_planner(owner_tg_id);
