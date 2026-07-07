import os
import json
from functools import wraps
from typing import Any, Callable
import redis


class CacheService:
    def __init__(self) -> None:
        redis_host = os.getenv("REDIS_HOST", "127.0.0.1")
        redis_port = int(os.getenv("REDIS_PORT", "6379"))
        redis_db = int(os.getenv("REDIS_DB", "0"))
        redis_password = os.getenv("REDIS_PASSWORD")

        self._client = redis.Redis(
            host=redis_host,
            port=redis_port,
            db=redis_db,
            password=redis_password,
            decode_responses=True,
            socket_connect_timeout=0.2,
            socket_timeout=0.2,
        )

    def get(self, key: str) -> Any | None:
        try:
            data = self._client.get(key)
            if data is None:
                return None
            return json.loads(data)
        except (redis.RedisError, json.JSONDecodeError):
            return None

    def set(self, key: str, value: Any, ttl_seconds: int) -> None:
        try:
            serialized_value = json.dumps(value, default=str)
            self._client.setex(key, ttl_seconds, serialized_value)
        except (redis.RedisError, TypeError, ValueError):
            pass

    def invalidate_prefix(self, prefix: str) -> None:
        try:
            batch: list[str] = []
            for key in self._client.scan_iter(match=f"{prefix}*", count=100):
                batch.append(key)
                if len(batch) == 100:
                    self._client.delete(*batch)
                    batch.clear()
            if batch:
                self._client.delete(*batch)
        except redis.RedisError:
            pass


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

            result = func(*args, **kwargs)
            cache_service.set(key, result, ttl_seconds)
            return result

        return wrapper

    return decorator
