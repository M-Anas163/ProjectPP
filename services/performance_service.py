from functools import wraps
from time import perf_counter
from typing import Any, Callable

from fastapi import HTTPException
from starlette.responses import Response

from db.database import SessionLocal
from db.models import PerformanceLog
from services.aop_utils import find_request


def _write_performance_log(
    endpoint: str,
    method: str,
    duration_ms: int,
    status_code: int,
) -> None:
    db = SessionLocal()
    try:
        db.add(
            PerformanceLog(
                endpoint=endpoint,
                method=method,
                duration_ms=duration_ms,
                status_code=status_code,
            )
        )
        db.commit()
    except Exception:
        db.rollback()
    finally:
        db.close()


def performance_logged(func: Callable) -> Callable:
    @wraps(func)
    def wrapper(*args: Any, **kwargs: Any) -> Any:
        request = find_request(args, kwargs)
        endpoint = request.url.path if request is not None else func.__name__
        method = request.method if request is not None else "FUNCTION"
        status_code = 200
        started = perf_counter()

        try:
            result = func(*args, **kwargs)
            if isinstance(result, Response):
                status_code = result.status_code
            return result
        except HTTPException as exc:
            status_code = exc.status_code
            raise
        except Exception:
            status_code = 500
            raise
        finally:
            duration_ms = int((perf_counter() - started) * 1000)
            _write_performance_log(endpoint, method, duration_ms, status_code)

    return wrapper
