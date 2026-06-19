import argparse
import sys
import threading
from concurrent.futures import ThreadPoolExecutor, as_completed
from decimal import Decimal
from pathlib import Path
from uuid import uuid4

from fastapi import HTTPException
from sqlalchemy import func, select

PROJECT_ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(PROJECT_ROOT))

import db.models
from db.database import Base, SessionLocal, engine
from db.models import OrderItem, Product
from services.order_service import checkout_order
from services.product_service import create_product
from services.user_service import create_user


def run_checkout_race(user_id: int, product_id: int, attempts: int) -> list[dict]:
    barrier = threading.Barrier(attempts)

    def attempt_checkout(index: int) -> dict:
        barrier.wait()
        try:
            result = checkout_order(
                user_id=user_id,
                items=[{"product_id": product_id, "quantity": 1}],
            )
            return {"index": index, "ok": True, "result": result}
        except HTTPException as exc:
            return {
                "index": index,
                "ok": False,
                "status_code": exc.status_code,
                "detail": exc.detail,
            }

    with ThreadPoolExecutor(max_workers=attempts) as executor:
        futures = [executor.submit(attempt_checkout, index) for index in range(attempts)]
        return [future.result() for future in as_completed(futures)]


def get_product_state(product_id: int) -> Product:
    db = SessionLocal()
    try:
        product = db.get(Product, product_id)
        if product is None:
            raise RuntimeError(f"Product {product_id} was not found")
        db.expunge(product)
        return product
    finally:
        db.close()


def count_order_items(product_id: int) -> int:
    db = SessionLocal()
    try:
        return db.scalar(
            select(func.count(OrderItem.id)).where(OrderItem.product_id == product_id)
        ) or 0
    finally:
        db.close()


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Prove optimistic locking prevents inventory overselling."
    )
    parser.add_argument("--attempts", type=int, default=50)
    parser.add_argument("--stock", type=int, default=1)
    args = parser.parse_args()

    if args.attempts < 2:
        raise SystemExit("--attempts must be at least 2")
    if args.stock < 1:
        raise SystemExit("--stock must be at least 1")

    Base.metadata.create_all(bind=engine)

    unique_id = uuid4().hex
    user = create_user(
        email=f"race-{unique_id}@example.com",
        password="password123",
    )
    product = create_product(
        name=f"Race Proof Product {unique_id}",
        description="Created by scripts/prove_inventory_locking.py",
        price=Decimal("10.00"),
        stock_quantity=args.stock,
    )

    product_id = int(product["id"])
    initial_version = int(product["version"])

    results = run_checkout_race(
        user_id=int(user["id"]),
        product_id=product_id,
        attempts=args.attempts,
    )

    successes = [result for result in results if result["ok"]]
    failures = [result for result in results if not result["ok"]]
    final_product = get_product_state(product_id)
    order_item_count = count_order_items(product_id)

    print(f"product_id: {product_id}")
    print(f"initial_stock: {args.stock}")
    print(f"attempts: {args.attempts}")
    print(f"successful_checkouts: {len(successes)}")
    print(f"failed_checkouts: {len(failures)}")
    print(f"final_stock: {final_product.stock_quantity}")
    print(f"initial_version: {initial_version}")
    print(f"final_version: {final_product.version}")
    print(f"created_order_items_for_product: {order_item_count}")

    if failures:
        failure_counts: dict[str, int] = {}
        for failure in failures:
            key = f"{failure['status_code']} {failure['detail']}"
            failure_counts[key] = failure_counts.get(key, 0) + 1
        print(f"failure_reasons: {failure_counts}")

    expected_successes = min(args.stock, args.attempts)
    expected_final_stock = args.stock - len(successes)
    expected_final_version = initial_version + len(successes)

    assert len(successes) == expected_successes, (
        f"expected {expected_successes} successful checkouts, "
        f"got {len(successes)}"
    )
    assert final_product.stock_quantity == expected_final_stock, (
        f"expected final stock {expected_final_stock}, "
        f"got {final_product.stock_quantity}"
    )
    assert final_product.stock_quantity >= 0, "stock went negative"
    assert final_product.version == expected_final_version, (
        f"expected version {expected_final_version}, got {final_product.version}"
    )
    assert order_item_count == len(successes), (
        f"expected {len(successes)} order items, got {order_item_count}"
    )

    print("PASS: optimistic locking prevented overselling under concurrent checkout.")


if __name__ == "__main__":
    main()
