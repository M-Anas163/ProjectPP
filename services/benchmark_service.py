from sqlalchemy import desc, func, select
from sqlalchemy.orm import Session

from db.models import PerformanceLog
from services.transaction_service import with_session


@with_session
def get_benchmark_summary(*, db: Session, limit: int = 10) -> list[dict]:
    rows = db.execute(
        select(
            PerformanceLog.endpoint,
            PerformanceLog.method,
            func.count(PerformanceLog.id).label("request_count"),
            func.avg(PerformanceLog.duration_ms).label("avg_duration_ms"),
            func.max(PerformanceLog.duration_ms).label("max_duration_ms"),
        )
        .group_by(PerformanceLog.endpoint, PerformanceLog.method)
        .order_by(desc("avg_duration_ms"))
        .limit(limit)
    ).all()

    return [
        {
            "endpoint": row.endpoint,
            "method": row.method,
            "request_count": row.request_count,
            "avg_duration_ms": round(float(row.avg_duration_ms or 0), 2),
            "max_duration_ms": int(row.max_duration_ms or 0),
        }
        for row in rows
    ]
