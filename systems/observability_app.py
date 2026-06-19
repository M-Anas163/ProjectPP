from api.app_factory import create_app
from api.routers.observability import router as observability_router


app = create_app(
    title="Observability System",
    description="Owns performance benchmark and operational reporting endpoints.",
    routers=[observability_router],
)
