from collections.abc import Sequence
from contextlib import asynccontextmanager

from fastapi import APIRouter, FastAPI, Request

import db.models
from db.database import Base, engine
from services.async_queue_service import async_queue_service
from services.capacity_service import capacity_limited
from services.performance_service import performance_logged
from services.rate_limit_service import rate_limited


def create_app(
    *,
    title: str,
    description: str,
    routers: Sequence[APIRouter],
    start_background_workers: bool = False,
) -> FastAPI:
    @asynccontextmanager
    async def lifespan(app: FastAPI):
        Base.metadata.create_all(bind=engine)
        if start_background_workers:
            async_queue_service.start_workers()
        yield
        if start_background_workers:
            async_queue_service.stop_workers()

    app = FastAPI(
        title=title,
        description=description,
        version="1.0.0",
        lifespan=lifespan,
    )

    @app.get("/")
    @performance_logged
    @rate_limited(max_requests=120, window_seconds=60)
    @capacity_limited(name="read", max_concurrent=50)
    def read_root(request: Request):
        return {
            "system": title,
            "description": description,
            "routes": [router.prefix or "/" for router in routers],
            "aop": [
                "performance_logging",
                "rate_limiting",
                "capacity_control",
                "transaction_integrity",
                "optimistic_locking",
                "async_queue",
                "caching",
            ],
        }

    @app.get("/health")
    @performance_logged
    @capacity_limited(name="read", max_concurrent=50)
    def health(request: Request):
        return {"status": "ok", "system": title}

    for router in routers:
        app.include_router(router)

    return app
