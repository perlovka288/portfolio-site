-- МИГРАЦИЯ: добавляем флаг сотрудничества для заказов

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS cooperation BOOLEAN NOT NULL DEFAULT FALSE;

-- После применения флага заказы могут отмечаться как сотрудничество и при принятии их стоимость будет учитываться как 0.
