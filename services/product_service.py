from decimal import Decimal

from fastapi import HTTPException, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from db.models import Product
from services.cache_service import cache_service, cached
from services.transaction_service import transactional, with_session


def _product_to_dict(product: Product) -> dict:
    return {
        "id": product.id,
        "name": product.name,
        "description": product.description,
        "price": str(product.price),
        "stock_quantity": product.stock_quantity,
        "version": product.version,
        "created_at": product.created_at,
        "updated_at": product.updated_at,
    }


@cached(ttl_seconds=30, key_prefix="products")
@with_session
def list_products(*, limit: int = 50, offset: int = 0, db: Session) -> list[dict]:
    products = db.scalars(
        select(Product).order_by(Product.id).offset(offset).limit(limit)
    ).all()
    return [_product_to_dict(product) for product in products]


@with_session
def get_product(*, product_id: int, db: Session) -> dict:
    product = db.get(Product, product_id)
    if product is None:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Product not found",
        )
    return _product_to_dict(product)


@transactional
def create_product(
    *,
    name: str,
    description: str | None,
    price: Decimal,
    stock_quantity: int,
    db: Session,
) -> dict:
    product = Product(
        name=name,
        description=description,
        price=price,
        stock_quantity=stock_quantity,
        version=1,
    )
    db.add(product)
    db.flush()
    db.refresh(product)
    cache_service.invalidate_prefix("products")
    return _product_to_dict(product)
