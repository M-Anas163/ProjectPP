import os
from dataclasses import dataclass
from datetime import datetime, timezone
from functools import wraps
from queue import Queue
from threading import Thread
from typing import Any, Callable

from db.database import SessionLocal
from db.models import BackgroundJobLog


@dataclass
class QueuedJob:
    log_id: int
    job_name: str
    func: Callable
    args: tuple
    kwargs: dict


class AsyncQueueService:
    def __init__(self) -> None:
        self._queue: Queue[QueuedJob | None] = Queue()
        self._workers: list[Thread] = []

    def start_workers(self, count: int | None = None) -> None:
        if self._workers:
            return

        worker_count = count or int(os.getenv("ASYNC_WORKERS", "2"))
        for index in range(worker_count):
            worker = Thread(
                target=self._worker_loop,
                name=f"async-worker-{index + 1}",
                daemon=True,
            )
            worker.start()
            self._workers.append(worker)

    def stop_workers(self) -> None:
        for _ in self._workers:
            self._queue.put(None)
        for worker in self._workers:
            worker.join(timeout=2)
        self._workers.clear()

    def enqueue(self, job_name: str, func: Callable, *args: Any, **kwargs: Any) -> dict:
        log_id = self._create_log(job_name)
        self._queue.put(QueuedJob(log_id, job_name, func, args, kwargs))
        return {"job_id": log_id, "status": "queued", "job_name": job_name}

    def _worker_loop(self) -> None:
        while True:
            job = self._queue.get()
            if job is None:
                self._queue.task_done()
                return

            self._run(job)
            self._queue.task_done()

    def _create_log(self, job_name: str) -> int:
        db = SessionLocal()
        try:
            log = BackgroundJobLog(
                job_name=job_name,
                status="queued",
                started_at=datetime.now(timezone.utc),
            )
            db.add(log)
            db.commit()
            db.refresh(log)
            return log.id
        finally:
            db.close()

    def _set_status(
        self,
        log_id: int,
        status: str,
        *,
        error_message: str | None = None,
        finished: bool = False,
    ) -> None:
        db = SessionLocal()
        try:
            log = db.get(BackgroundJobLog, log_id)
            if log is None:
                return
            log.status = status
            if status == "running":
                log.started_at = datetime.now(timezone.utc)
            if finished:
                log.finished_at = datetime.now(timezone.utc)
            log.error_message = error_message
            db.commit()
        finally:
            db.close()

    def _run(self, job: QueuedJob) -> None:
        self._set_status(job.log_id, "running")
        try:
            job.func(*job.args, **job.kwargs)
        except Exception as exc:
            self._set_status(
                job.log_id,
                "failed",
                error_message=str(exc),
                finished=True,
            )
            return

        self._set_status(job.log_id, "succeeded", finished=True)


async_queue_service = AsyncQueueService()


def queued_job(job_name: str) -> Callable:
    def decorator(func: Callable) -> Callable:
        @wraps(func)
        def wrapper(*args: Any, **kwargs: Any) -> dict:
            return async_queue_service.enqueue(job_name, func, *args, **kwargs)

        return wrapper

    return decorator
