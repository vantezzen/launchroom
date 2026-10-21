# Tool image for the device art pipeline (docs/design-system.md §7).
# Build: docker build --label launchroom.dev=true -t launchroom-dev-device-art .
FROM python:3.12-slim
LABEL launchroom.dev=true
RUN pip install --no-cache-dir pillow==11.3.0 numpy==2.2.6 opencv-python-headless==4.12.0.88 scipy==1.16.2
WORKDIR /work
