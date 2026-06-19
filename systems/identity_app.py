from api.app_factory import create_app
from api.routers.identity import router as identity_router


app = create_app(
    title="Identity System",
    description="Owns customer registration and account-facing identity endpoints.",
    routers=[identity_router],
)
