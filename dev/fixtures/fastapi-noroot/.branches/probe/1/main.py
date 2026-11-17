from typing import Union

from fastapi import FastAPI, Request

app = FastAPI()


@app.get("/items/{item_id}")
def read_item(item_id: int, q: Union[str, None] = None):
    return {"item_id": item_id, "q": q}


@app.get("/probe")
def probe(request: Request):
    # Diagnostic only (probe branch).
    return {"url": str(request.url), "scheme": request.url.scheme, "client": request.client.host if request.client else None}
