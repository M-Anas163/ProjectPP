import os
from functools import wraps
from queue import Full, Queue
from random import random
from threading import Thread
from time import perf_counter
from typing import Any, Callable

from fastapi import HTTPException
from starlette.responses import Response

from db.database import SessionLocal
from db.models import PerformanceLog
from services.aop_utils import find_request


_SAMPLE_RATE = float(os.getenv("PERFORMANCE_LOG_SAMPLE_RATE", "0.1"))
_LOG_QUEUE: Queue[tuple[str, str, int, int]] = Queue(
    maxsize=int(os.getenv("PERFORMANCE_LOG_QUEUE_SIZE", "1000"))
)


def _performance_log_worker() -> None:
    while True:
        endpoint, method, duration_ms, status_code = _LOG_QUEUE.get()
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
            _LOG_QUEUE.task_done()


Thread(
    target=_performance_log_worker,
    name="performance-log-worker",
    daemon=True,
).start()


def _enqueue_performance_log(
    endpoint: str,
    method: str,
    duration_ms: int,
    status_code: int,
) -> None:
    if _SAMPLE_RATE <= 0 or random() > _SAMPLE_RATE:
        return
    try:
        _LOG_QUEUE.put_nowait((endpoint, method, duration_ms, status_code))
    except Full:
        # Observability must never block or fail a business request.
        pass


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
            elif request is not None:
                route = request.scope.get("route")
                status_code = getattr(route, "status_code", None) or 200
            return result
        except HTTPException as exc:
            status_code = exc.status_code
            raise
        except Exception:
            status_code = 500
            raise
        finally:
            duration_ms = int((perf_counter() - started) * 1000)
            _enqueue_performance_log(endpoint, method, duration_ms, status_code)

    return wrapper
