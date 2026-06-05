-- Добавить столбец deadline для отслеживания дедлайна заказа
ALTER TABLE orders ADD COLUMN IF NOT EXISTS deadline TIMESTAMP;
