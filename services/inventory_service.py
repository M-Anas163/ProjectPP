from typing import cast

from fastapi import HTTPException, status
from sqlalchemy import func, update
from sqlalchemy.engine import CursorResult
from sqlalchemy.orm import Session

from db.models import InventoryMovement, Product
from services.cache_service import cache_service
from services.transaction_service import transactional


MAX_OPTIMISTIC_RETRIES = 3


def reserve_product_stock(
    db: Session,
    *,
    product_id: int,
    quantity: int,
    order_id: int,
):
    if quantity <= 0:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Quantity must be greater than zero",
        )

    for _ in range(MAX_OPTIMISTIC_RETRIES):
        product = db.get(Product, product_id)
        if product is None:
            raise HTTPException(
                status_code=status.HTTP_404_NOT_FOUND,
                detail=f"Product {product_id} was not found",
            )

        if product.stock_quantity < quantity:
            raise HTTPException(
                status_code=status.HTTP_409_CONFLICT,
                detail=f"Not enough stock for product {product_id}",
            )

        current_version = product.version

        result = cast(
            CursorResult,
            db.execute(
                update(Product)
                .where(Product.id == product_id)
                .where(Product.version == current_version)
                .where(Product.stock_quantity >= quantity)
                .values(
                    stock_quantity=Product.stock_quantity - quantity,
                    version=Product.version + 1,
                    updated_at=func.now(),
                )
            ),
        )
        if result.rowcount == 1:
            db.add(
                InventoryMovement(
                    product_id=product_id,
                    change_amount=-quantity,
                    reason="order_checkout",
                    order_id=order_id,
                )
            )
            cache_service.invalidate_prefix("products")
            return product.price

        db.expire_all()

    raise HTTPException(
        status_code=status.HTTP_409_CONFLICT,
        detail=f"Concurrent stock update conflict for product {product_id}",
    )


@transactional
def adjust_inventory(
    *,
    product_id: int,
    change_amount: int,
    reason: str,
    order_id: int | None = None,
    db: Session,
) -> dict:
    for _ in range(MAX_OPTIMISTIC_RETRIES):
        product = db.get(Product, product_id)
        if product is None:
            raise HTTPException(
                status_code=status.HTTP_404_NOT_FOUND,
                detail=f"Product {product_id} was not found",
            )

        new_quantity = product.stock_quantity + change_amount
        if new_quantity < 0:
            raise HTTPException(
                status_code=status.HTTP_409_CONFLICT,
                detail="Stock quantity cannot become negative",
            )

        result = cast(
            CursorResult,
            db.execute(
                update(Product)
                .where(Product.id == product_id)
                .where(Product.version == product.version)
                .values(
                    stock_quantity=new_quantity,
                    version=Product.version + 1,
                    updated_at=func.now(),
                )
            ),
        )
        if result.rowcount == 1:
            db.add(
                InventoryMovement(
                    product_id=product_id,
                    change_amount=change_amount,
                    reason=reason,
                    order_id=order_id,
                )
            )
            cache_service.invalidate_prefix("products")
            return {
                "product_id": product_id,
                "stock_quantity": new_quantity,
                "version": product.version + 1,
            }

        db.expire_all()

    raise HTTPException(
        status_code=status.HTTP_409_CONFLICT,
        detail=f"Concurrent stock update conflict for product {product_id}",
    )
