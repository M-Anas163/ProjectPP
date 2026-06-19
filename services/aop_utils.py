from fastapi import Request


def find_request(args: tuple, kwargs: dict) -> Request | None:
    request = kwargs.get("request")
    if isinstance(request, Request):
        return request

    for value in args:
        if isinstance(value, Request):
            return value

    return None
