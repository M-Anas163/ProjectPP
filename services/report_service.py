from datetime import datetime, time, timezone
from decimal import Decimal

from sqlalchemy import func, select
from sqlalchemy.orm import Session

from db.models import Order, OrderStatus, Product
from services.async_queue_service import queued_job
from services.transaction_service import with_session


@with_session
def get_summary_report(*, db: Session) -> dict:
    paid_sales = db.scalar(
        select(func.coalesce(func.sum(Order.total_amount), 0)).where(
            Order.status == OrderStatus.paid
        )
    )
    orders_count = db.scalar(select(func.count(Order.id)))
    products_count = db.scalar(select(func.count(Product.id)))
    low_stock_count = db.scalar(
        select(func.count(Product.id)).where(Product.stock_quantity <= 5)
    )

    return {
        "total_sales": str(paid_sales or Decimal("0")),
        "orders_count": orders_count or 0,
        "products_count": products_count or 0,
        "low_stock_count": low_stock_count or 0,
    }


@queued_job("daily_sales_batch")
@with_session
def run_daily_sales_batch(*, chunk_size: int = 100, db: Session) -> dict:
    day_start = datetime.combine(
        datetime.now(timezone.utc).date(),
        time.min,
        tzinfo=timezone.utc,
    )
    processed = 0
    total_sales = Decimal("0")
    offset = 0

    while True:
        chunk = db.scalars(
            select(Order)
            .where(Order.status == OrderStatus.paid)
            .where(Order.created_at >= day_start)
            .order_by(Order.id)
            .offset(offset)
            .limit(chunk_size)
        ).all()
        if not chunk:
            break

        processed += len(chunk)
        total_sales += sum((order.total_amount for order in chunk), Decimal("0"))
        offset += chunk_size

    return {"processed_orders": processed, "total_sales": str(total_sales)}
