INSERT INTO portfolio_categories (category_key, title, width_px, height_px, is_design, sort_order) VALUES
('preview', 'Превью', 1920, 1080, 0, 10),
('youtube_design', 'Оформление для YouTube', 1920, 768, 1, 20),
('vk_design', 'Оформление для VK', 1920, 768, 1, 30),
('banner', 'Баннеры', 1000, 1200, 0, 40),
('avatar', 'Аватарки', 1000, 1000, 0, 50)
ON CONFLICT (category_key) DO UPDATE SET
    title = EXCLUDED.title,
    width_px = EXCLUDED.width_px,
    height_px = EXCLUDED.height_px,
    is_design = EXCLUDED.is_design,
    sort_order = EXCLUDED.sort_order;
