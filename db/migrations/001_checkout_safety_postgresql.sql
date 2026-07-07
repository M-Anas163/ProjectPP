-- PostgreSQL migration.
ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS idempotency_key VARCHAR(128);

-- migrate:split
ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS request_hash VARCHAR(64);

-- migrate:split
UPDATE orders
SET
    idempotency_key = 'legacy-order-' || id::text,
    request_hash = repeat('0', 64)
WHERE idempotency_key IS NULL OR request_hash IS NULL;

-- migrate:split
ALTER TABLE orders
    ALTER COLUMN idempotency_key SET NOT NULL,
    ALTER COLUMN request_hash SET NOT NULL;

-- migrate:split
DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM payments
        GROUP BY order_id
        HAVING count(*) > 1
    ) THEN
        RAISE EXCEPTION 'Cannot add payment uniqueness: duplicate order_id values exist';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM invoices
        GROUP BY order_id
        HAVING count(*) > 1
    ) THEN
        RAISE EXCEPTION 'Cannot add invoice uniqueness: duplicate order_id values exist';
    END IF;
END
$$;

-- migrate:split
CREATE UNIQUE INDEX IF NOT EXISTS uq_orders_idempotency_key
    ON orders (idempotency_key);

-- migrate:split
CREATE UNIQUE INDEX IF NOT EXISTS uq_payments_order_id
    ON payments (order_id);

-- migrate:split
CREATE UNIQUE INDEX IF NOT EXISTS uq_invoices_order_id
    ON invoices (order_id);

-- migrate:split
ALTER TABLE products
    DROP CONSTRAINT IF EXISTS ck_products_stock_nonnegative;
-- migrate:split
ALTER TABLE products
    ADD CONSTRAINT ck_products_stock_nonnegative
    CHECK (stock_quantity >= 0) NOT VALID;
-- migrate:split
ALTER TABLE products
    VALIDATE CONSTRAINT ck_products_stock_nonnegative;

-- migrate:split
ALTER TABLE order_items
    DROP CONSTRAINT IF EXISTS ck_order_items_quantity_positive;
-- migrate:split
ALTER TABLE order_items
    ADD CONSTRAINT ck_order_items_quantity_positive
    CHECK (quantity > 0) NOT VALID;
-- migrate:split
ALTER TABLE order_items
    VALIDATE CONSTRAINT ck_order_items_quantity_positive;
