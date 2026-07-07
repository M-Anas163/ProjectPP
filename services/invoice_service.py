from fastapi import HTTPException, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from db.models import Invoice, Order
from services.async_queue_service import queued_job
from services.transaction_service import transactional


def create_invoice(*, order_id: int, db: Session) -> Invoice:
    existing_invoice = db.scalar(select(Invoice).where(Invoice.order_id == order_id))
    if existing_invoice is not None:
        return existing_invoice

    invoice = Invoice(
        order_id=order_id,
        invoice_number=f"INV-{order_id}",
        status="issued",
    )
    db.add(invoice)
    db.flush()
    return invoice


@queued_job("issue_invoice")
@transactional
def issue_invoice(*, order_id: int, db: Session) -> dict:
    order = db.get(Order, order_id)
    if order is None:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Order not found",
        )

    invoice = create_invoice(order_id=order_id, db=db)
    return {"invoice_id": invoice.id, "status": invoice.status}
