# launchroom dev fixture: a FastAPI app with its own Dockerfile, no EXPOSE and
# an exec-form CMD that listens on 0.0.0.0:8000 (the "rollout hangs" report,
# docs/04 §8.1). Without EXPOSE launchroom can't know the port from the image;
# it reads it from the CMD (`--port 8000`).
FROM python:3.12-slim
WORKDIR /srv
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY app ./app
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000"]
