from api.app_factory import create_app
from api.routers.catalog import router as catalog_router


app = create_app(
    title="Catalog System",
    description="Owns product listing and product management endpoints.",
    routers=[catalog_router],
)
