# Checkout incident review

## A. Architecture and corrected checkout boundary

Nginx distributes finance traffic across ports 9003 and 9005 with
`least_conn`. Both processes are stateless for checkout correctness. The shared
database is the source of truth.

One checkout transaction now performs these operations in order:

1. Insert-or-lock one `checkout_attempts` row using `Idempotency-Key`.
2. Return the already committed result if this is a replay.
3. Validate the user.
4. Aggregate duplicate product lines and sort by product ID.
5. Create the pending order.
6. Atomically reserve each product with:
   `UPDATE products SET stock_quantity = stock_quantity - :quantity ... WHERE stock_quantity >= :quantity`.
7. Insert order items and inventory movements.
8. Insert exactly one payment.
9. Insert exactly one invoice.
10. Link the idempotency record to the order and commit once.

Database constraints are the final defense:

- unique order idempotency key;
- unique checkout-attempt-to-order link;
- one payment per order;
- one invoice per order;
- nonnegative product stock;
- positive order-item quantity.

All finance replicas execute the same transaction against the same database.
No Python lock, process-local cache, or Nginx routing decision participates in
correctness.

## B. Root causes ranked by probability

### 1. Uninitialized Redis client caused checkout 500 responses

`CacheService.init()` was never called. A successful reservation called
`cache_service.invalidate_prefix()`, which accessed missing `_client` state.
The exception occurred before transaction commit, so SQLAlchemy rolled back and
FastAPI returned 500.

Fix: initialize the client in `CacheService.__init__`, use short socket
timeouts, fail open, and use `SCAN` rather than blocking Redis `KEYS`.

### 2. Invoice enqueue happened after checkout commit

The router called `checkout_order()` and then `issue_invoice()`.
`checkout_order()` committed before queue-log insertion. If the second database
session timed out or failed, the client received 500 although stock and order
changes were already committed. A client retry then created a second order.

Fix: create the invoice inside the checkout transaction. Noncritical batch jobs
may still use the local queue, but checkout success no longer depends on it.

### 3. No idempotency contract

Every retry created a new order. Retries can come from JMeter, clients, operators,
or proxies after an ambiguous timeout.

Fix: require `Idempotency-Key`, persist its request hash, serialize concurrent
retries on `checkout_attempts`, and return the original result. Reusing a key
with a different body returns 409.

### 4. Hot-row optimistic retries were too small

Fifty callers could all read the same product version. Only one update won;
three immediate retries were insufficient, producing
`Concurrent stock update conflict`. This is expected contention expressed as
an application error, not overselling protection at useful throughput.

Fix: use one conditional atomic update. Affected-row count zero means missing
or insufficient stock and returns 404/409. There is no stale read/update window.

### 5. Database pool contention was amplified by observability writes

Each request opened one business session and then synchronously opened another
session in the performance decorator. Checkout capacity was equal to the
pool's total configured connections, while invoice workers and performance
logging used the same pool. Pool checkout can wait long enough for Nginx to
time out and mark a replica failed.

Fix: explicit pool timeout/recycle/LIFO settings, checkout capacity below total
pool capacity, sampled bounded asynchronous performance writes, and no
post-commit invoice session.

Pool budget must be checked globally:

`database connections = processes * (pool_size + max_overflow)`

With five one-worker services and `5 + 5`, the upper bound is 50 connections.
Increase worker count only after checking the database connection limit.

### 6. Nginx passively removed both finance servers

`max_fails=3 fail_timeout=10s` means communication errors or timeouts can mark
each server unavailable. Once both are unavailable, Nginx emits
`no live upstreams` and returns 502. Application HTTP 500 responses alone do
not normally make an upstream unavailable; connection refusal, reset, and
timeout do.

Fix: shared upstream state with `zone`, upstream keepalive, explicit connect and
response timeouts, timing logs, shorter recovery time, and readiness checks in
the service startup script.

### 7. Runtime database and documented database disagreed

The runtime database was MariaDB 10.4 while `.env.example` specified PostgreSQL.
PostgreSQL-only SQL would fail at runtime. The requirements also omitted Redis
and the PostgreSQL driver while cache code imported Redis.

Fix: checkout SQL is portable across MariaDB/MySQL and PostgreSQL. Separate
dialect migrations are selected automatically. Both supported drivers and
Redis are declared.

### 8. Multi-product checkouts could lock products in different orders

Requests containing product A then B and B then A can form a row-lock cycle.

Fix: aggregate duplicate product lines and always reserve ascending product ID.
Residual database deadlock/serialization errors are retried as complete
transactions with bounded backoff.

### 9. `create_all()` was being used as migration management

Multiple replicas ran DDL at startup. `create_all()` cannot add columns or
constraints to existing tables and concurrent startup DDL can block.

Fix: startup schema creation is disabled unless `AUTO_CREATE_SCHEMA=true`.
Run `scripts/migrate_database.py` once before deploying application processes.

### 10. Background jobs are process-local and nondurable

Each Uvicorn process owns a separate in-memory queue. Jobs are lost on process
exit and are not balanced or claimed across replicas.

Fix applied to the incident path: invoice creation is no longer queued.
For production daily jobs, replace the in-memory queue with Celery/RQ plus
Redis/RabbitMQ, or a database outbox worker using `FOR UPDATE SKIP LOCKED`.

### 11. Rate and capacity limits are process-local

Each replica and each worker has its own counters. This does not compromise
stock correctness, but limits are multiplied by process count and are not a
global policy.

Fix: checkout defaults are configurable. Use a Redis-backed limiter if a global
limit is required.

## C. Code changes

Critical files:

- `services/order_service.py`: idempotency, deterministic product ordering,
  one checkout transaction.
- `services/inventory_service.py`: atomic conditional stock updates.
- `services/invoice_service.py`: reusable in-transaction invoice creation.
- `services/transaction_service.py`: one session per transaction and bounded
  deadlock/serialization retry.
- `api/routers/finance.py`: required `Idempotency-Key`, post-commit cache
  invalidation, configurable limits.
- `db/database.py`: bounded pool wait, recycle, pre-ping, LIFO, `READ COMMITTED`,
  and `expire_on_commit=False`.
- `services/performance_service.py`: sampled bounded asynchronous writes.
- `services/cache_service.py`: initialized fail-open Redis client and `SCAN`.
- `api/app_factory.py`: readiness endpoint, instance header, no automatic
  production DDL.
- `scripts/start_systems.ps1`: one process per port, logs, instance IDs, and
  readiness checks.
- `deploy/nginx.conf`: shared least-connection state, keepalive, timeouts, and
  upstream timing logs.

The complete corrected implementations are in those files; no abbreviated code
fragments are required during deployment.

## D. Database deployment

Back up the database, then run:

```powershell
.\.venv\Scripts\python.exe scripts\migrate_database.py
```

The runner selects:

- `db/migrations/*_mysql.sql` for MariaDB/MySQL;
- `db/migrations/*_postgresql.sql` for PostgreSQL.

Preflight checks:

```sql
SELECT order_id, COUNT(*) FROM payments GROUP BY order_id HAVING COUNT(*) > 1;
SELECT order_id, COUNT(*) FROM invoices GROUP BY order_id HAVING COUNT(*) > 1;
SELECT id FROM products WHERE stock_quantity < 0;
SELECT id FROM order_items WHERE quantity <= 0;
```

All four queries must return no rows before uniqueness/check constraints are
added.

## E. Nginx and Uvicorn deployment

Validate Nginx:

```powershell
C:\nginx-1.31.2\nginx.exe -t `
  -p "C:\Users\kutada\Downloads\pp\perp\" `
  -c "deploy\nginx.conf"
```

Start services:

```powershell
.\scripts\start_systems.ps1
```

Each configured finance port is one Uvicorn process. Two finance ports already
provide two replicas. Do not multiply Uvicorn workers until the total database
pool budget and global rate-limit semantics have been recalculated.

`least_conn` balances active TCP requests, not completed request totals.
Sequential fast requests may alternate on ties; long concurrent requests should
favor the currently less-busy replica. Confirm distribution using
`X-Service-Instance` and Nginx's `upstream=$upstream_addr` access-log field.

Nginx retries only communication failures covered by `proxy_next_upstream`.
It does not enable `non_idempotent`, so unsafe POST requests are not blindly
replayed after being sent. Checkout clients should retry with the same
`Idempotency-Key`.

## F. JMeter strategy

### Test 1: overselling

1. Create one product with stock 100.
2. Use 500 threads, ramp-up 1 second, loop count 1.
3. Generate a unique `Idempotency-Key` per thread with `${__UUID()}`.
4. Expected result: exactly 100 HTTP 201 and 400 HTTP 409.
5. Assert there are no 500/502 responses.
6. Verify database stock is zero and exactly 100 order items reference the
   product.

### Test 2: duplicate suppression

1. Create one product with stock at least 10.
2. Set one JMeter property containing a UUID and send the same key from 100
   simultaneous threads.
3. Expected result: all successful responses contain the same `order_id`.
4. Verify one stock decrement, one order, one payment, and one invoice.

### Test 3: sustained capacity

1. Use high stock and a unique key per logical checkout.
2. Run steps at 8, 16, 32, 64, and 128 concurrent users for at least five
   minutes each.
3. Treat 201 as success, 409 as an expected business rejection, 429 as rate
   limiting, and 503 as explicit capacity shedding.
4. Fail the test on any 500 or sustained 502.
5. Record p50/p95/p99, throughput, pool wait errors, database lock waits, both
   instance headers, and Nginx upstream response time.

### Test 4: replica failure

1. Run sustained checkout traffic with unique keys.
2. Stop port 9003.
3. Client retries ambiguous failures with the same key.
4. Verify port 9005 continues processing, no duplicate order is created, and
   Nginx returns to both replicas after 9003 restarts.

Do not use one idempotency key for normal throughput testing; that intentionally
serializes all requests as retries of one logical checkout.

## G. Validation checklist

- Migration table contains both checkout migrations.
- `orders.idempotency_key` is unique and non-null.
- `checkout_attempts` exists.
- Payment and invoice `order_id` values are unique.
- Product stock and order-item quantity checks exist.
- Both `/health/ready` endpoints return 200.
- Responses identify `finance-1` and `finance-2`.
- Redis outage does not fail checkout.
- Same key and same body returns the original order.
- Same key and different body returns 409.
- Stock never becomes negative.
- Expected stock exhaustion returns 409, not 500.
- No `QueuePool limit ... connection timed out` errors occur.
- Nginx logs do not show sustained `no live upstreams`.
- Database deadlock errors, if induced by unrelated operations, are retried and
  do not escape as 500 within the configured retry budget.

## H. Reproduction of the original incident

On the original revision:

1. Start both finance services and Nginx.
2. Send a checkout that has available stock.
3. Stock reservation reaches `cache_service.invalidate_prefix()`.
4. `_client` does not exist because `CacheService.init()` was never invoked.
5. SQLAlchemy rolls back and FastAPI returns 500.
6. Under continued load, synchronous performance writes, queue-log writes, and
   checkout sessions contend for the small pool.
7. Requests exceed proxy timing or a finance process fails startup due to a
   missing dependency.
8. Nginx records communication failures for both servers, temporarily marks
   both unavailable, logs `no live upstreams`, and returns 502.

To reproduce stock contention independently on the old algorithm, run many
unique checkout requests against one high-stock product. More callers than the
three immediate optimistic retries produce `Concurrent stock update conflict`.

## I. Verification procedure

```powershell
.\.venv\Scripts\python.exe scripts\migrate_database.py
.\.venv\Scripts\python.exe scripts\prove_inventory_locking.py --attempts 100 --stock 10
.\.venv\Scripts\python.exe scripts\verify_checkout_safety.py
C:\nginx-1.31.2\nginx.exe -t `
  -p "C:\Users\kutada\Downloads\pp\perp\" `
  -c "deploy\nginx.conf"
.\scripts\start_systems.ps1
```

Then send checkout requests with:

```http
POST /orders/checkout
Content-Type: application/json
Idempotency-Key: 95dcad0f-bb91-4aa0-a377-ec91b817a753

{
  "user_id": 1,
  "items": [{"product_id": 1, "quantity": 1}]
}
```

Repeat the exact request and key. The response must contain the same order ID
and `"idempotent_replay": true`. Change the body but retain the key; the response
must be 409.

Validated during this change:

- MariaDB 10.4 migration applied successfully.
- 100 concurrent attempts against stock 10: 10 successes, 90 HTTP 409 outcomes,
  final stock 0.
- 25 concurrent same-key attempts: one order, one payment, one invoice, one
  stock decrement.
- Python compile/import checks passed.
- Nginx configuration test passed.
