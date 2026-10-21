# Go toolchain for dev tasks (make test-daemon / gen-protocol / dev-daemon), so no
# Go install is needed on the host. Thin layer over the official image that adds
# a user matching the host UID/GID: generated files written into daemon/ and
# protocol/ stay owned by the developer, and the pre-created cache directories
# give the named cache volumes the right ownership on first mount.
ARG GO_IMAGE=golang:1.27
FROM ${GO_IMAGE}

ARG UID=1000
ARG GID=1000

RUN set -eux; \
    if ! getent group "${GID}" >/dev/null; then groupadd --gid "${GID}" launchroom; fi; \
    if getent passwd "${UID}" >/dev/null; then userdel "$(getent passwd "${UID}" | cut -d: -f1)"; fi; \
    useradd --uid "${UID}" --gid "${GID}" --create-home --home-dir /home/launchroom --shell /bin/bash launchroom; \
    mkdir -p /go/pkg/mod /home/launchroom/.cache/go-build /out /itest; \
    chown -R "${UID}:${GID}" /go/pkg/mod /home/launchroom /out /itest

ENV GOMODCACHE=/go/pkg/mod \
    GOCACHE=/home/launchroom/.cache/go-build \
    GOTOOLCHAIN=local

USER launchroom
WORKDIR /src/daemon
