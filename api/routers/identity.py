from fastapi import APIRouter, Request, status

from schemas import UserCreate
from services.capacity_service import capacity_limited
from services.performance_service import performance_logged
from services.rate_limit_service import rate_limited
from services.user_service import create_user


router = APIRouter(tags=["identity"])


@router.post("/users", status_code=status.HTTP_201_CREATED)
@performance_logged
@rate_limited(max_requests=20, window_seconds=60)
@capacity_limited(name="identity-writes", max_concurrent=15)
def register_user(request: Request, payload: UserCreate):
    return create_user(email=payload.email, password=payload.password)
