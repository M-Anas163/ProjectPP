from decimal import Decimal

from fastapi import HTTPException, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from db.models import Order, OrderItem, OrderStatus, Payment, User
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


@transactional
def checkout_order(*, user_id: int, items: list[dict], db: Session) -> dict:
    if not items:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Order must contain at least one item",
        )

    if db.get(User, user_id) is None:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="User not found",
        )

    order = Order(user_id=user_id, status=OrderStatus.pending, total_amount=Decimal("0"))
    db.add(order)
    db.flush()

    total_amount = Decimal("0")
    for item in items:
        product_id = int(item["product_id"])
        quantity = int(item["quantity"])
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

    return {
        "order_id": order.id,
        "payment_id": payment.id,
        "status": order.status.value,
        "total_amount": str(total_amount),
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
