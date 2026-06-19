from api.app_factory import create_app
from api.routers.finance import router as finance_router


app = create_app(
    title="Finance System",
    description=(
        "Owns inventory, checkout, payments, invoices, daily sales jobs, "
        "and financial reports."
    ),
    routers=[finance_router],
    start_background_workers=True,
)
