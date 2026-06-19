from decimal import Decimal
from typing import Literal

from pydantic import BaseModel, Field


class UserCreate(BaseModel):
    email: str
    password: str = Field(min_length=8)


class ProductCreate(BaseModel):
    name: str
    description: str | None = None
    price: Decimal = Field(gt=0)
    stock_quantity: int = Field(default=0, ge=0)


class InventoryAdjustment(BaseModel):
    product_id: int
    change_amount: int
    reason: str
    order_id: int | None = None


class CheckoutItem(BaseModel):
    product_id: int
    quantity: int = Field(gt=0)


class CheckoutRequest(BaseModel):
    user_id: int
    items: list[CheckoutItem]


class OrderStatusUpdate(BaseModel):
    status: Literal["pending", "paid", "failed", "cancelled"]


class PaymentStatusUpdate(BaseModel):
    status: str


class DailySalesBatchRequest(BaseModel):
    chunk_size: int = Field(default=100, ge=1, le=1000)
