from collections import defaultdict, deque
from functools import wraps
from threading import Lock
from time import monotonic
from typing import Any, Callable

from fastapi import HTTPException, status

from services.aop_utils import find_request


class RateLimitService:
    def __init__(self) -> None:
        self._hits: dict[str, deque[float]] = defaultdict(deque)
        self._lock = Lock()

    def allow(self, key: str, max_requests: int, window_seconds: int) -> bool:
        now = monotonic()
        cutoff = now - window_seconds

        with self._lock:
            hits = self._hits[key]
            while hits and hits[0] < cutoff:
                hits.popleft()

            if len(hits) >= max_requests:
                return False

            hits.append(now)
            return True


rate_limit_service = RateLimitService()


def rate_limited(max_requests: int = 60, window_seconds: int = 60) -> Callable:
    def decorator(func: Callable) -> Callable:
        @wraps(func)
        def wrapper(*args: Any, **kwargs: Any) -> Any:
            request = find_request(args, kwargs)
            if request is None:
                key = func.__name__
            else:
                client = request.client.host if request.client else "unknown"
                key = f"{client}:{request.method}:{request.url.path}"

            if not rate_limit_service.allow(key, max_requests, window_seconds):
                raise HTTPException(
                    status_code=status.HTTP_429_TOO_MANY_REQUESTS,
                    detail="Rate limit exceeded",
                )

            return func(*args, **kwargs)

        return wrapper

    return decorator
