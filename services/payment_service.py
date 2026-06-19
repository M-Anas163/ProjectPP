from fastapi import HTTPException, status
from sqlalchemy.orm import Session

from db.models import Order, OrderStatus, Payment
from services.transaction_service import transactional


@transactional
def update_payment_status(*, payment_id: int, new_status: str, db: Session) -> dict:
    payment = db.get(Payment, payment_id)
    if payment is None:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Payment not found",
        )

    payment.status = new_status
    order = db.get(Order, payment.order_id)
    if order is not None and new_status in {"paid", "failed", "cancelled"}:
        order.status = OrderStatus(new_status)

    return {
        "payment_id": payment.id,
        "order_id": payment.order_id,
        "status": payment.status,
    }
