# "Fake server" for local development: a privileged docker:dind container with
# its own Docker daemon and image store, running launchroomd against it.
#
# launchroomd binary is NOT baked in. `make dev-daemon` builds it (in the dev `go`
# container) into the `launchroom-dev_daemon-bin` volume, which is mounted here
# read-only at /opt/launchroom/bin. entrypoint.sh restarts launchroomd whenever
# that binary changes, so the edit loop is: change Go code → `make dev-daemon`.
ARG DIND_IMAGE=docker:29-dind
FROM ${DIND_IMAGE}

# Host-matching UID/GID of the dev `go` container: pre-creating the mount point
# with that owner makes the daemon-bin volume writable for `make dev-daemon` even
# when this container creates the volume first.
ARG UID=1000
ARG GID=1000
RUN mkdir -p /opt/launchroom/bin && chown "${UID}:${GID}" /opt/launchroom/bin

# git: launchroomd integration tests serve fixture repositories with
# `git http-backend` (daemon/test/gitserve). launchroomd itself clones with go-git.
RUN apk add --no-cache git git-daemon
# procps: dockerd runs the host's `ps -ef` for container top. busybox's ps
# prints no parent PIDs; most real servers (Debian, Ubuntu) have procps, so
# container inspections in dev see process parents like there (docs/22 §7.2).
RUN apk add --no-cache procps

COPY --chmod=0755 entrypoint.sh /usr/local/bin/launchroom-dev-server

ENTRYPOINT ["launchroom-dev-server"]
