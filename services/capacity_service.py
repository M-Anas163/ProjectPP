import os
from functools import wraps
from threading import BoundedSemaphore, Lock
from typing import Any, Callable

from fastapi import HTTPException, status


DEFAULT_MAX_CONCURRENT_REQUESTS = int(os.getenv("MAX_CONCURRENT_REQUESTS", "20"))


class CapacityService:
    def __init__(self) -> None:
        self._semaphores: dict[str, BoundedSemaphore] = {}
        self._lock = Lock()

    def _get_limiter(self, name: str, max_concurrent: int) -> BoundedSemaphore:
        with self._lock:
            if name not in self._semaphores:
                self._semaphores[name] = BoundedSemaphore(max_concurrent)
            return self._semaphores[name]

    def acquire(self, name: str, max_concurrent: int) -> BoundedSemaphore | None:
        limiter = self._get_limiter(name, max_concurrent)
        if not limiter.acquire(blocking=False):
            return None
        return limiter


capacity_service = CapacityService()


def capacity_limited(
    name: str = "default",
    max_concurrent: int = DEFAULT_MAX_CONCURRENT_REQUESTS,
) -> Callable:
    def decorator(func: Callable) -> Callable:
        @wraps(func)
        def wrapper(*args: Any, **kwargs: Any) -> Any:
            limiter = capacity_service.acquire(name, max_concurrent)
            if limiter is None:
                raise HTTPException(
                    status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
                    detail="Server is busy, retry shortly",
                )

            try:
                return func(*args, **kwargs)
            finally:
                limiter.release()

        return wrapper

    return decorator
