import sys
import threading
from concurrent.futures import ThreadPoolExecutor, as_completed
from decimal import Decimal
from pathlib import Path
from uuid import uuid4

from sqlalchemy import func, select


PROJECT_ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(PROJECT_ROOT))

from db.database import SessionLocal
from db.models import Invoice, Order, OrderItem, Payment, Product
from services.order_service import checkout_order
from services.product_service import create_product
from services.user_service import create_user


def _run_concurrently(callable_, attempts: int) -> list[dict]:
    barrier = threading.Barrier(attempts)

    def invoke() -> dict:
        barrier.wait()
        return callable_()

    with ThreadPoolExecutor(max_workers=attempts) as executor:
        futures = [executor.submit(invoke) for _ in range(attempts)]
        return [future.result() for future in as_completed(futures)]


def main() -> None:
    attempts = 25
    run_id = uuid4().hex
    user = create_user(
        email=f"idempotency-{run_id}@example.com",
        password="password123",
    )
    product = create_product(
        name=f"Idempotency Product {run_id}",
        description="Created by verify_checkout_safety.py",
        price=Decimal("10.00"),
        stock_quantity=attempts + 5,
    )
    user_id = int(user["id"])
    product_id = int(product["id"])
    idempotency_key = f"checkout-safety-{run_id}"

    results = _run_concurrently(
        lambda: checkout_order(
            user_id=user_id,
            items=[{"product_id": product_id, "quantity": 1}],
            idempotency_key=idempotency_key,
        ),
        attempts,
    )

    order_ids = {result["order_id"] for result in results}
    assert len(order_ids) == 1, f"duplicate orders created: {order_ids}"
    order_id = order_ids.pop()

    db = SessionLocal()
    try:
        final_product = db.get(Product, product_id)
        assert final_product is not None
        assert final_product.stock_quantity == attempts + 4
        assert db.scalar(
            select(func.count(Order.id)).where(
                Order.idempotency_key == idempotency_key
            )
        ) == 1
        assert db.scalar(
            select(func.count(OrderItem.id)).where(OrderItem.order_id == order_id)
        ) == 1
        assert db.scalar(
            select(func.count(Payment.id)).where(Payment.order_id == order_id)
        ) == 1
        assert db.scalar(
            select(func.count(Invoice.id)).where(Invoice.order_id == order_id)
        ) == 1
    finally:
        db.close()

    replay_count = sum(result["idempotent_replay"] for result in results)
    assert replay_count == attempts - 1
    print(
        "PASS: concurrent retries created one order, one payment, one invoice, "
        "and one stock decrement."
    )


if __name__ == "__main__":
    main()
