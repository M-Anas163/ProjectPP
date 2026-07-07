CREATE TABLE IF NOT EXISTS checkout_attempts (
    idempotency_key VARCHAR(128) NOT NULL,
    request_hash VARCHAR(64) NOT NULL,
    order_id INTEGER NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (idempotency_key),
    CONSTRAINT uq_checkout_attempts_order_id UNIQUE (order_id),
    CONSTRAINT fk_checkout_attempts_order_id
        FOREIGN KEY (order_id) REFERENCES orders (id)
);
