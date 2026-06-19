import argparse
import statistics
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen


def hit(url: str) -> tuple[int, float]:
    started = time.perf_counter()
    request = Request(url, method="GET")
    try:
        with urlopen(request, timeout=10) as response:
            response.read()
            status_code = response.status
    except HTTPError as exc:
        status_code = exc.code
    except URLError:
        status_code = 0

    duration_ms = (time.perf_counter() - started) * 1000
    return status_code, duration_ms


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--url", default="http://127.0.0.1:8000/health")
    parser.add_argument("--users", type=int, default=100)
    args = parser.parse_args()

    started = time.perf_counter()
    results = []
    with ThreadPoolExecutor(max_workers=args.users) as executor:
        futures = [executor.submit(hit, args.url) for _ in range(args.users)]
        for future in as_completed(futures):
            results.append(future.result())

    durations = [duration for _, duration in results]
    status_counts = {}
    for status_code, _ in results:
        status_counts[status_code] = status_counts.get(status_code, 0) + 1

    print(f"requests: {len(results)}")
    print(f"total_time_ms: {round((time.perf_counter() - started) * 1000, 2)}")
    print(f"status_counts: {status_counts}")
    print(f"avg_ms: {round(statistics.mean(durations), 2)}")
    print(f"max_ms: {round(max(durations), 2)}")


if __name__ == "__main__":
    main()
