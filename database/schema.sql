-- PostgreSQL schema для portfolio-site

CREATE TABLE IF NOT EXISTS orders (
    id SERIAL PRIMARY KEY,
    username VARCHAR(255) NOT NULL,
    telegram VARCHAR(255) NOT NULL,
    service_key VARCHAR(100) NOT NULL,
    details TEXT NOT NULL,
    screenshot VARCHAR(255) DEFAULT '',
    example_photo VARCHAR(255) DEFAULT '',
    status VARCHAR(50) DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    client_chat_id VARCHAR(100) DEFAULT ''
);

CREATE TABLE IF NOT EXISTS portfolio (
    id SERIAL PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    category VARCHAR(100) NOT NULL DEFAULT '',
    image_path VARCHAR(255) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    price INT DEFAULT 0,
    category_key VARCHAR(50) DEFAULT 'preview',
    image VARCHAR(255) DEFAULT '',
    price_rub INT DEFAULT 0,
    price_uan INT DEFAULT 0,
    avatar_image VARCHAR(255) DEFAULT ''
);

CREATE TABLE IF NOT EXISTS portfolio_categories (
    id SERIAL PRIMARY KEY,
    category_key VARCHAR(50) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    width_px INT NOT NULL DEFAULT 0,
    height_px INT NOT NULL DEFAULT 0,
    is_design SMALLINT NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 100,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS prices (
    id SERIAL PRIMARY KEY,
    category_key VARCHAR(50) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    price_rub INT NOT NULL DEFAULT 0,
    price_uan INT NOT NULL DEFAULT 0,
    features TEXT DEFAULT NULL,
    image VARCHAR(255) DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS site_settings (
    setting_key VARCHAR(80) PRIMARY KEY,
    setting_value TEXT DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    avatar VARCHAR(255) DEFAULT 'default_avatar.png'
);

-- Seed data: portfolio_categories
INSERT INTO portfolio_categories (category_key, title, width_px, height_px, is_design, sort_order) VALUES
('preview', 'Превью', 1920, 1080, 0, 10),
('youtube_design', 'Оформление для YouTube', 1920, 768, 1, 20),
('vk_design', 'Оформление для VK', 1920, 768, 1, 30),
('banner', 'Баннеры', 1000, 1200, 0, 40),
('avatar', 'Аватарки', 1000, 1000, 0, 50)
ON CONFLICT (category_key) DO NOTHING;

-- Seed data: prices
INSERT INTO prices (category_key, title, description, price_rub, price_uan, features) VALUES
('preview', 'Превью для видео и стримов', 'Превью для YouTube, видео и стримов.', 400, 250, 'Подбор композиции|Работа с текстом|Подготовка под 1920x1080'),
('avatar', 'Именная аватарка', 'Аватарка с именем, персонажем или брендингом.', 300, 175, 'Именной текст|Персонаж/арт|Подготовка под соцсети'),
('banner', 'Шапка для YouTube/VK', 'Шапка или баннер для YouTube/VK.', 300, 200, 'Шапка YouTube|Шапка VK|Подгон размеров'),
('design', 'Шапка + аватарка для канала', 'Комплект оформления канала: шапка и аватарка.', 500, 400, 'Шапка канала|Аватарка|Единый стиль'),
('vk_design', 'Оформление VK плейджа', 'Оформление страницы/плейджа VK под твой стиль.', 400, 300, 'Шапка/обложка|Адаптация под VK|Оформление визуала'),
('post_banner', 'Баннер для постов', 'Баннер/креатив для публикаций.', 250, 150, 'Постер для поста|Акцентный текст|Быстрая адаптация'),
('private_pack', 'Мой приватный пак', 'Большой набор материалов и помощи для дизайнера.', 1000, 750, 'Дизайн-материалы|Кисти, стили, градиенты|PSD-файлы работ|Туториал на установку Stable Diffusion|Правки к работам')
ON CONFLICT (category_key) DO NOTHING;

-- Seed data: site_settings
INSERT INTO site_settings (setting_key, setting_value) VALUES
('theme_preset', 'onyx'),
('theme_shape', 'soft'),
('theme_density', 'normal'),
('theme_effects', 'glow')
ON CONFLICT (setting_key) DO NOTHING;

-- Seed data: users (пароль: 60667543)
INSERT INTO users (username, email, password, avatar) VALUES
('Kostlim', 'jeffkostlim@gmail.com', '$2y$10$E9V96p9fEaM3Z49zH2pGye9W0A3Uf3mU3S1iYl5BaeI/a56K1Y6Ue', 'default_avatar.png')
ON CONFLICT (username) DO NOTHING;

-- ── tg_links: привязка сессии сайта к Telegram ──────────────────
CREATE TABLE IF NOT EXISTS tg_links (
    id            SERIAL PRIMARY KEY,
    site_code     VARCHAR(20)  NOT NULL UNIQUE,
    session_id    VARCHAR(128) NOT NULL,
    linked        SMALLINT     NOT NULL DEFAULT 0,
    tg_id         VARCHAR(64)  DEFAULT NULL,
    tg_username   VARCHAR(128) DEFAULT NULL,
    tg_first_name VARCHAR(255) DEFAULT NULL,
    tg_photo_url  TEXT         DEFAULT NULL,
    created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_tg_links_session ON tg_links (session_id);