from functools import wraps
from typing import Any, Callable

from db.database import SessionLocal


def transactional(func: Callable) -> Callable:
    @wraps(func)
    def wrapper(*args: Any, **kwargs: Any) -> Any:
        if kwargs.get("db") is not None:
            return func(*args, **kwargs)

        db = SessionLocal()
        kwargs["db"] = db
        try:
            result = func(*args, **kwargs)
            db.commit()
            return result
        except Exception:
            db.rollback()
            raise
        finally:
            db.close()

    return wrapper


def with_session(func: Callable) -> Callable:
    @wraps(func)
    def wrapper(*args: Any, **kwargs: Any) -> Any:
        if kwargs.get("db") is not None:
            return func(*args, **kwargs)

        db = SessionLocal()
        kwargs["db"] = db
        try:
            return func(*args, **kwargs)
        finally:
            db.close()

    return wrapper
