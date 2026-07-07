from fastapi import HTTPException, status
from sqlalchemy import func, select, update
from sqlalchemy.orm import Session

from db.models import InventoryMovement, Product
from services.transaction_service import transactional


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

    result = db.execute(
        update(Product)
        .where(Product.id == product_id)
        .where(Product.stock_quantity >= quantity)
        .values(
            stock_quantity=Product.stock_quantity - quantity,
            version=Product.version + 1,
            updated_at=func.now(),
        )
    )

    if result.rowcount != 1:
        product = db.scalar(
            select(Product).where(Product.id == product_id).with_for_update()
        )
        if product is None:
            raise HTTPException(
                status_code=status.HTTP_404_NOT_FOUND,
                detail=f"Product {product_id} was not found",
            )
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail=f"Not enough stock for product {product_id}",
        )

    unit_price = db.scalar(
        select(Product.price).where(Product.id == product_id).with_for_update()
    )
    if unit_price is None:
        raise RuntimeError(f"Reserved product {product_id} disappeared")

    db.add(
        InventoryMovement(
            product_id=product_id,
            change_amount=-quantity,
            reason="order_checkout",
            order_id=order_id,
        )
    )
    return unit_price


@transactional
def adjust_inventory(
    *,
    product_id: int,
    change_amount: int,
    reason: str,
    order_id: int | None = None,
    db: Session,
) -> dict:
    result = db.execute(
        update(Product)
        .where(Product.id == product_id)
        .where(Product.stock_quantity + change_amount >= 0)
        .values(
            stock_quantity=Product.stock_quantity + change_amount,
            version=Product.version + 1,
            updated_at=func.now(),
        )
    )

    if result.rowcount != 1:
        product = db.scalar(
            select(Product).where(Product.id == product_id).with_for_update()
        )
        if product is None:
            raise HTTPException(
                status_code=status.HTTP_404_NOT_FOUND,
                detail=f"Product {product_id} was not found",
            )
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail="Stock quantity cannot become negative",
        )

    row = db.execute(
        select(Product.stock_quantity, Product.version)
        .where(Product.id == product_id)
        .with_for_update()
    ).one()

    db.add(
        InventoryMovement(
            product_id=product_id,
            change_amount=change_amount,
            reason=reason,
            order_id=order_id,
        )
    )
    return {
        "product_id": product_id,
        "stock_quantity": row.stock_quantity,
        "version": row.version,
    }
