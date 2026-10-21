import os
from fastapi import FastAPI

app = FastAPI()


@app.get("/")
def root():
    return {"db": os.getenv("DATABASE_URL")}
