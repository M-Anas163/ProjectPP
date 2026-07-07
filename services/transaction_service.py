import os
import time
from functools import wraps
from typing import Any, Callable

from sqlalchemy.exc import OperationalError

from db.database import SessionLocal


MAX_TRANSACTION_RETRIES = int(os.getenv("DB_TRANSACTION_RETRIES", "3"))


def _is_retryable_transaction_error(exc: OperationalError) -> bool:
    original = exc.orig
    error_code = original.args[0] if getattr(original, "args", None) else None
    sqlstate = getattr(original, "sqlstate", None) or getattr(original, "pgcode", None)
    return error_code in {1205, 1213} or sqlstate in {"40001", "40P01"}


def transactional(func: Callable) -> Callable:
    @wraps(func)
    def wrapper(*args: Any, **kwargs: Any) -> Any:
        if kwargs.get("db") is not None:
            return func(*args, **kwargs)

        for attempt in range(MAX_TRANSACTION_RETRIES + 1):
            db = SessionLocal()
            call_kwargs = {**kwargs, "db": db}
            try:
                result = func(*args, **call_kwargs)
                db.commit()
                return result
            except OperationalError as exc:
                db.rollback()
                if (
                    attempt >= MAX_TRANSACTION_RETRIES
                    or not _is_retryable_transaction_error(exc)
                ):
                    raise
                time.sleep(0.01 * (2**attempt))
            except Exception:
                db.rollback()
                raise
            finally:
                db.close()

        raise RuntimeError("Transaction retry loop exited unexpectedly")

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
