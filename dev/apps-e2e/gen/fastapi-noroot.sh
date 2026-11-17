# FastAPI's first example without the root route: only GET /items/{item_id}
# (so `/` is FastAPI's 404). Hand-written; requirements frozen by pip.
# stage: LR_FIXTURE_PYTHON_IMAGE py_stage
# shellcheck shell=sh disable=SC2164,SC2103,SC1091 # stages run under set -e (_lib.sh)

py_stage() {
	python -m venv /tmp/venv
	. /tmp/venv/bin/activate
	pip install -q --disable-pip-version-check "fastapi==$LR_FIXTURE_FASTAPI" "uvicorn==$LR_FIXTURE_UVICORN"
	cd /work/app
	cat >main.py <<'PY'
from typing import Union

from fastapi import FastAPI

app = FastAPI()


@app.get("/items/{item_id}")
def read_item(item_id: int, q: Union[str, None] = None):
    return {"item_id": item_id, "q": q}
PY
	pip freeze >requirements.txt
}
