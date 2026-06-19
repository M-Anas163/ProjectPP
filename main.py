from api.app_factory import create_app
from api.routers.catalog import router as catalog_router
from api.routers.finance import router as finance_router
from api.routers.identity import router as identity_router
from api.routers.observability import router as observability_router


app = create_app(
    title="E-Commerce Gateway",
    description="Single-process gateway that exposes all systems for local development.",
    routers=[
        identity_router,
        catalog_router,
        finance_router,
        observability_router,
    ],
    start_background_workers=True,
)
