import hashlib
import json
from collections import defaultdict
from decimal import Decimal

from fastapi import HTTPException, status
from sqlalchemy import select
from sqlalchemy.dialects.mysql import insert as mysql_insert
from sqlalchemy.dialects.postgresql import insert as postgresql_insert
from sqlalchemy.orm import Session

from db.models import (
    CheckoutAttempt,
    Invoice,
    Order,
    OrderItem,
    OrderStatus,
    Payment,
    User,
)
from services.invoice_service import create_invoice
from services.inventory_service import reserve_product_stock
from services.transaction_service import transactional, with_session


def _order_to_dict(db: Session, order: Order) -> dict:
    items = db.scalars(
        select(OrderItem).where(OrderItem.order_id == order.id).order_by(OrderItem.id)
    ).all()
    payments = db.scalars(
        select(Payment).where(Payment.order_id == order.id).order_by(Payment.id)
    ).all()
    return {
        "id": order.id,
        "user_id": order.user_id,
        "status": order.status.value if hasattr(order.status, "value") else order.status,
        "total_amount": str(order.total_amount),
        "created_at": order.created_at,
        "items": [
            {
                "id": item.id,
                "product_id": item.product_id,
                "quantity": item.quantity,
                "unit_price": str(item.unit_price),
                "subtotal": str(item.quantity * item.unit_price),
            }
            for item in items
        ],
        "payments": [
            {
                "id": payment.id,
                "status": payment.status,
                "amount": str(payment.amount),
                "created_at": payment.created_at,
            }
            for payment in payments
        ],
    }


def _checkout_request_hash(user_id: int, items: list[tuple[int, int]]) -> str:
    payload = json.dumps(
        {"user_id": user_id, "items": items},
        separators=(",", ":"),
        sort_keys=True,
    )
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()


def _checkout_result(db: Session, order: Order, *, replayed: bool) -> dict:
    payment = db.scalar(select(Payment).where(Payment.order_id == order.id))
    invoice = db.scalar(select(Invoice).where(Invoice.order_id == order.id))
    if payment is None or invoice is None:
        raise RuntimeError(f"Committed checkout {order.id} is incomplete")
    return {
        "order_id": order.id,
        "payment_id": payment.id,
        "invoice_id": invoice.id,
        "status": order.status.value,
        "total_amount": str(order.total_amount),
        "idempotent_replay": replayed,
    }


def _lock_checkout_attempt(
    db: Session,
    *,
    idempotency_key: str,
    request_hash: str,
) -> CheckoutAttempt:
    values = {
        "idempotency_key": idempotency_key,
        "request_hash": request_hash,
    }
    dialect = db.get_bind().dialect.name
    if dialect in {"mysql", "mariadb"}:
        statement = mysql_insert(CheckoutAttempt).values(**values)
        db.execute(
            statement.on_duplicate_key_update(
                idempotency_key=statement.inserted.idempotency_key
            )
        )
    elif dialect == "postgresql":
        statement = postgresql_insert(CheckoutAttempt).values(**values)
        db.execute(
            statement.on_conflict_do_update(
                index_elements=[CheckoutAttempt.idempotency_key],
                set_={"idempotency_key": statement.excluded.idempotency_key},
            )
        )
    else:
        attempt = db.get(CheckoutAttempt, idempotency_key)
        if attempt is None:
            attempt = CheckoutAttempt(**values)
            db.add(attempt)
            db.flush()

    attempt_query = select(CheckoutAttempt).where(
        CheckoutAttempt.idempotency_key == idempotency_key
    )
    if dialect not in {"mysql", "mariadb", "postgresql"}:
        attempt_query = attempt_query.with_for_update()
    attempt = db.scalar(attempt_query)
    if attempt is None:
        raise RuntimeError("Failed to create or lock checkout idempotency record")
    if attempt.request_hash != request_hash:
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail="Idempotency-Key was already used for a different checkout",
        )
    return attempt


@transactional
def checkout_order(
    *,
    user_id: int,
    items: list[dict],
    idempotency_key: str,
    db: Session,
) -> dict:
    if not items:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Order must contain at least one item",
        )

    quantities: dict[int, int] = defaultdict(int)
    for item in items:
        product_id = int(item["product_id"])
        quantity = int(item["quantity"])
        if quantity <= 0:
            raise HTTPException(
                status_code=status.HTTP_400_BAD_REQUEST,
                detail="Quantity must be greater than zero",
            )
        quantities[product_id] += quantity

    normalized_items = sorted(quantities.items())
    request_hash = _checkout_request_hash(user_id, normalized_items)
    checkout_attempt = _lock_checkout_attempt(
        db,
        idempotency_key=idempotency_key,
        request_hash=request_hash,
    )
    if checkout_attempt.order_id is not None:
        existing_order = db.get(Order, checkout_attempt.order_id)
        if existing_order is None:
            raise RuntimeError(
                f"Checkout attempt points to missing order {checkout_attempt.order_id}"
            )
        return _checkout_result(db, existing_order, replayed=True)

    if db.get(User, user_id) is None:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="User not found",
        )

    order = Order(
        idempotency_key=idempotency_key,
        request_hash=request_hash,
        user_id=user_id,
        status=OrderStatus.pending,
        total_amount=Decimal("0"),
    )
    db.add(order)
    db.flush()

    total_amount = Decimal("0")
    for product_id, quantity in normalized_items:
        unit_price = reserve_product_stock(
            db,
            product_id=product_id,
            quantity=quantity,
            order_id=order.id,
        )
        db.add(
            OrderItem(
                order_id=order.id,
                product_id=product_id,
                quantity=quantity,
                unit_price=unit_price,
            )
        )
        total_amount += unit_price * quantity

    order.total_amount = total_amount
    order.status = OrderStatus.paid

    payment = Payment(order_id=order.id, status="paid", amount=total_amount)
    db.add(payment)
    db.flush()

    invoice = create_invoice(order_id=order.id, db=db)
    checkout_attempt.order_id = order.id

    return {
        "order_id": order.id,
        "payment_id": payment.id,
        "invoice_id": invoice.id,
        "status": order.status.value,
        "total_amount": str(total_amount),
        "idempotent_replay": False,
    }


@with_session
def get_order(*, order_id: int, db: Session) -> dict:
    order = db.get(Order, order_id)
    if order is None:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Order not found",
        )
    return _order_to_dict(db, order)


@transactional
def update_order_status(*, order_id: int, new_status: str, db: Session) -> dict:
    order = db.get(Order, order_id)
    if order is None:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Order not found",
        )

    try:
        order.status = OrderStatus(new_status)
    except ValueError as exc:
        allowed = ", ".join(status.value for status in OrderStatus)
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail=f"Invalid order status. Use one of: {allowed}",
        ) from exc

    return {"order_id": order.id, "status": order.status.value}


@with_session
def list_recent_orders(*, limit: int = 20, db: Session) -> list[dict]:
    orders = db.scalars(select(Order).order_by(Order.id.desc()).limit(limit)).all()
    return [_order_to_dict(db, order) for order in orders]
