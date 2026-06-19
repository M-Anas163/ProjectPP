from fastapi import APIRouter, Request

from services.benchmark_service import get_benchmark_summary
from services.capacity_service import capacity_limited
from services.performance_service import performance_logged
from services.rate_limit_service import rate_limited


router = APIRouter(tags=["observability"])


@router.get("/ops/benchmarks")
@performance_logged
@rate_limited(max_requests=60, window_seconds=60)
@capacity_limited(name="observability-reads", max_concurrent=50)
def benchmark_summary(request: Request, limit: int = 10):
    return {"slowest_endpoints": get_benchmark_summary(limit=limit)}
