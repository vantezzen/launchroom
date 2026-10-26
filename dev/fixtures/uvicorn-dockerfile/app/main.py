import os
import time

from fastapi import FastAPI

# FIXTURE_MODE reproduces the rollout failure cases (docs/04 §8.1):
# crash = an exception while importing the app, slow = 90 s before it listens.
MODE = os.environ.get("FIXTURE_MODE", "ok")

if MODE == "crash":
    raise RuntimeError("FIXTURE_MODE=crash: the app fails while importing")

if MODE == "slow":
    print("FIXTURE_MODE=slow: warming up for 90 seconds", flush=True)
    time.sleep(90)

app = FastAPI()


@app.get("/")
def index() -> dict[str, str]:
    return {"hello": "from launchroom", "mode": MODE}
