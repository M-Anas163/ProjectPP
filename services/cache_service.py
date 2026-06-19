from dataclasses import dataclass
from functools import wraps
from threading import RLock
from time import monotonic
from typing import Any, Callable


@dataclass
class CacheEntry:
    value: Any
    expires_at: float


class CacheService:
    def __init__(self) -> None:
        self._items: dict[str, CacheEntry] = {}
        self._lock = RLock()

    def get(self, key: str) -> Any | None:
        with self._lock:
            entry = self._items.get(key)
            if entry is None:
                return None
            if entry.expires_at < monotonic():
                self._items.pop(key, None)
                return None
            return entry.value

    def set(self, key: str, value: Any, ttl_seconds: int) -> None:
        with self._lock:
            self._items[key] = CacheEntry(value, monotonic() + ttl_seconds)

    def invalidate_prefix(self, prefix: str) -> None:
        with self._lock:
            for key in list(self._items):
                if key.startswith(prefix):
                    self._items.pop(key, None)


cache_service = CacheService()


def _cache_key(prefix: str, args: tuple, kwargs: dict) -> str:
    clean_kwargs = {key: value for key, value in kwargs.items() if key != "db"}
    return f"{prefix}:{args!r}:{sorted(clean_kwargs.items())!r}"


def cached(ttl_seconds: int, key_prefix: str) -> Callable:
    def decorator(func: Callable) -> Callable:
        @wraps(func)
        def wrapper(*args: Any, **kwargs: Any) -> Any:
            key = _cache_key(key_prefix, args, kwargs)
            cached_value = cache_service.get(key)
            if cached_value is not None:
                return cached_value

            value = func(*args, **kwargs)
            cache_service.set(key, value, ttl_seconds)
            return value

        return wrapper

    return decorator
