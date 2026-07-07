ALTER TABLE orders
    ADD COLUMN idempotency_key VARCHAR(128) NULL,
    ADD COLUMN request_hash VARCHAR(64) NULL;

-- migrate:split
UPDATE orders
SET
    idempotency_key = CONCAT('legacy-order-', id),
    request_hash = REPEAT('0', 64)
WHERE idempotency_key IS NULL OR request_hash IS NULL;

-- migrate:split
ALTER TABLE orders
    MODIFY idempotency_key VARCHAR(128) NOT NULL,
    MODIFY request_hash VARCHAR(64) NOT NULL,
    ADD CONSTRAINT uq_orders_idempotency_key UNIQUE (idempotency_key);

-- migrate:split
ALTER TABLE payments
    ADD CONSTRAINT uq_payments_order_id UNIQUE (order_id);

-- migrate:split
ALTER TABLE invoices
    ADD CONSTRAINT uq_invoices_order_id UNIQUE (order_id);

-- migrate:split
ALTER TABLE products
    ADD CONSTRAINT ck_products_stock_nonnegative
    CHECK (stock_quantity >= 0);

-- migrate:split
ALTER TABLE order_items
    ADD CONSTRAINT ck_order_items_quantity_positive
    CHECK (quantity > 0);
