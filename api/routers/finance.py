import os

from fastapi import APIRouter, Header, Request, status

from schemas import (
    CheckoutRequest,
    DailySalesBatchRequest,
    InventoryAdjustment,
    OrderStatusUpdate,
    PaymentStatusUpdate,
)
from services.capacity_service import capacity_limited
from services.cache_service import cache_service
from services.inventory_service import adjust_inventory
from services.invoice_service import issue_invoice
from services.order_service import (
    checkout_order,
    get_order,
    list_recent_orders,
    update_order_status,
)
from services.payment_service import update_payment_status
from services.performance_service import performance_logged
from services.rate_limit_service import rate_limited
from services.report_service import get_summary_report, run_daily_sales_batch


router = APIRouter(tags=["finance"])


@router.post("/inventory/adjust")
@performance_logged
@rate_limited(max_requests=60, window_seconds=60)
@capacity_limited(name="finance-inventory", max_concurrent=10)
def update_inventory(request: Request, payload: InventoryAdjustment):
    result = adjust_inventory(
        product_id=payload.product_id,
        change_amount=payload.change_amount,
        reason=payload.reason,
        order_id=payload.order_id,
    )
    cache_service.invalidate_prefix("products")
    return result


@router.post("/orders/checkout", status_code=status.HTTP_201_CREATED)
@performance_logged
@rate_limited(
    max_requests=int(os.getenv("FINANCE_CHECKOUT_RATE_LIMIT", "6000")),
    window_seconds=60,
)
@capacity_limited(
    name="finance-checkout",
    max_concurrent=int(os.getenv("FINANCE_CHECKOUT_CONCURRENCY", "8")),
)
def checkout(
    request: Request,
    payload: CheckoutRequest,
    idempotency_key: str = Header(
        min_length=8,
        max_length=128,
        alias="Idempotency-Key",
    ),
):
    result = checkout_order(
        user_id=payload.user_id,
        items=[item.model_dump() for item in payload.items],
        idempotency_key=idempotency_key,
    )
    cache_service.invalidate_prefix("products")
    return result


@router.get("/orders")
@performance_logged
@rate_limited(max_requests=120, window_seconds=60)
@capacity_limited(name="finance-reads", max_concurrent=50)
def read_orders(request: Request, limit: int = 20):
    return {"items": list_recent_orders(limit=limit)}


@router.get("/orders/{order_id}")
@performance_logged
@rate_limited(max_requests=120, window_seconds=60)
@capacity_limited(name="finance-reads", max_concurrent=50)
def read_order(request: Request, order_id: int):
    return get_order(order_id=order_id)


@router.patch("/orders/{order_id}/status")
@performance_logged
@rate_limited(max_requests=60, window_seconds=60)
@capacity_limited(name="finance-writes", max_concurrent=15)
def change_order_status(request: Request, order_id: int, payload: OrderStatusUpdate):
    return update_order_status(order_id=order_id, new_status=payload.status)


@router.patch("/payments/{payment_id}/status")
@performance_logged
@rate_limited(max_requests=60, window_seconds=60)
@capacity_limited(name="finance-writes", max_concurrent=15)
def change_payment_status(
    request: Request,
    payment_id: int,
    payload: PaymentStatusUpdate,
):
    return update_payment_status(payment_id=payment_id, new_status=payload.status)


@router.post("/jobs/daily-sales")
@performance_logged
@rate_limited(max_requests=10, window_seconds=60)
@capacity_limited(name="finance-jobs", max_concurrent=5)
def enqueue_daily_sales_job(request: Request, payload: DailySalesBatchRequest):
    return run_daily_sales_batch(chunk_size=payload.chunk_size)


@router.post("/invoices/{order_id}")
@performance_logged
@rate_limited(max_requests=30, window_seconds=60)
@capacity_limited(name="finance-jobs", max_concurrent=5)
def enqueue_invoice_job(request: Request, order_id: int):
    return issue_invoice(order_id=order_id)


@router.get("/reports/summary")
@performance_logged
@rate_limited(max_requests=60, window_seconds=60)
@capacity_limited(name="finance-reads", max_concurrent=50)
def summary_report(request: Request):
    return get_summary_report()
