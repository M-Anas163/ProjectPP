import os
from collections.abc import Sequence
from contextlib import asynccontextmanager

from fastapi import APIRouter, FastAPI, Request
from sqlalchemy import text

import db.models
from db.database import Base, SessionLocal, engine
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
        if os.getenv("AUTO_CREATE_SCHEMA", "false").lower() == "true":
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

    @app.middleware("http")
    async def add_instance_header(request: Request, call_next):
        response = await call_next(request)
        response.headers["X-Service-Instance"] = os.getenv(
            "SERVICE_INSTANCE",
            title,
        )
        return response

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

    @app.get("/health/ready")
    def readiness(request: Request):
        db = SessionLocal()
        try:
            db.execute(text("SELECT 1"))
        finally:
            db.close()
        return {
            "status": "ready",
            "system": title,
            "instance": os.getenv("SERVICE_INSTANCE", title),
        }

    for router in routers:
        app.include_router(router)

    return app
