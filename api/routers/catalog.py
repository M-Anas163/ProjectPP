from fastapi import APIRouter, Request, status

from schemas import ProductCreate
from services.capacity_service import capacity_limited
from services.performance_service import performance_logged
from services.product_service import create_product, get_product, list_products
from services.rate_limit_service import rate_limited


router = APIRouter(tags=["catalog"])


@router.post("/products", status_code=status.HTTP_201_CREATED)
@performance_logged
@rate_limited(max_requests=30, window_seconds=60)
@capacity_limited(name="catalog-writes", max_concurrent=15)
def add_product(request: Request, payload: ProductCreate):
    return create_product(
        name=payload.name,
        description=payload.description,
        price=payload.price,
        stock_quantity=payload.stock_quantity,
    )


@router.get("/products")
@performance_logged
@rate_limited(max_requests=120, window_seconds=60)
@capacity_limited(name="catalog-reads", max_concurrent=50)
def read_products(request: Request, limit: int = 50, offset: int = 0):
    return {"items": list_products(limit=limit, offset=offset)}


@router.get("/products/{product_id}")
@performance_logged
@rate_limited(max_requests=120, window_seconds=60)
@capacity_limited(name="catalog-reads", max_concurrent=50)
def read_product(request: Request, product_id: int):
    return get_product(product_id=product_id)
