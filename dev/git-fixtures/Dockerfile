# Dev-only git server for fixture apps (`git-fixtures` service in dev/compose.yml).
# Publishes every directory under /fixtures as a deterministic commit and serves
# it over git smart HTTP (git http-backend). Built from launchroomd module's
# test tooling (daemon/test/gitserve); never shipped.
ARG GO_IMAGE=golang:1.27
FROM ${GO_IMAGE} AS build
WORKDIR /src
COPY daemon/go.mod daemon/go.sum ./
RUN --mount=type=cache,target=/go/pkg/mod go mod download
COPY daemon/ ./
RUN --mount=type=cache,target=/go/pkg/mod --mount=type=cache,target=/root/.cache/go-build \
    CGO_ENABLED=0 go build -trimpath -o /out/git-fixtures ./test/gitserve/cmd/git-fixtures

FROM alpine:3.22
RUN apk add --no-cache git git-daemon
COPY --from=build /out/git-fixtures /usr/local/bin/git-fixtures
USER 65534:65534
ENV HOME=/tmp
EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/git-fixtures", "-fixtures", "/fixtures", "-root", "/tmp/git", "-listen", ":8080", "-base-url", "http://git-fixtures:8080"]
