# launchroom task runner. `make` / `make help` lists the targets.
#
# Everything runs in Docker: the dev stack (dev/compose.yml, project
# `launchroom-dev`), dashboard tooling (composer, artisan, npm, tests) inside the
# dev dashboard image, Go via the dev `go` image. Nothing is installed on the
# host, and nothing outside the launchroom-dev project/label is touched.

# ---- Settings -----------------------------------------------------------------
GO_IMAGE       ?= golang:1.27
GOLANGCI_IMAGE ?= golangci/golangci-lint:v2.15.0
# Node for packages/* (npm packages such as the Next.js adapter).
NODE_IMAGE     ?= node:24
# Release version: the root VERSION file is the single source of truth
# (dashboard image, launchroomd ldflags, release tag v<VERSION>).
LR_VERSION := $(shell cat VERSION 2>/dev/null || echo 0.0.0)
# The dev dashboard reports $(LR_VERSION)-dev like the dev daemons (dev/compose.yml).
export LR_VERSION
# Main package of launchroomd, relative to daemon/ (for `make dev-daemon`).
DAEMON_MAIN    ?= ./cmd/launchroomd

SVC  ?= dashboard
ARGS ?=

# dev/.env (ports, optional UID/GID override) is plain KEY=VALUE.
-include dev/.env
LR_DEV_UID ?= $(shell id -u)
LR_DEV_GID ?= $(shell id -g)
export LR_DEV_UID LR_DEV_GID
export LR_GO_IMAGE := $(GO_IMAGE)
export LR_GOLANGCI_IMAGE := $(GOLANGCI_IMAGE)

COMPOSE       := docker compose --project-directory dev --env-file dev/.env -f dev/compose.yml
# Full suites and integration runs share one machine-wide lock (dev/bin/heavy-lock.sh),
# so parallel workstreams queue instead of saturating every core. Targeted runs
# (check-dashboard, test-daemon PKG=..., -test.run) skip it.
HEAVY         = sh dev/bin/heavy-lock.sh
# Files changed under dashboard/ since the last staged checkpoint (paths relative to dashboard/).
CHANGED_DASHBOARD = $(shell git diff --name-only --relative=dashboard -- dashboard 2>/dev/null; git ls-files --others --exclude-standard -- dashboard 2>/dev/null | sed 's|^dashboard/||')
COMPOSE_MULTI := $(COMPOSE) -f dev/compose.multi.yml
# Runs a command in the dashboard container: `exec` when it is up, else a one-off `run`.
IN_DASHBOARD = if [ -n "$$($(COMPOSE) ps -q --status running dashboard 2>/dev/null)" ]; \
	then $(COMPOSE) exec dashboard $(1); else $(COMPOSE) run --rm dashboard $(1); fi

.DEFAULT_GOAL := help
.PHONY: go-image test-db test-dashboard-pest check-dashboard lint-templates help up up-multi down nuke logs ps shell artisan composer npm migrate seed restart-workers \
	up-backups test test-dashboard lint lint-dashboard test-daemon test-daemon-integration test-daemon-integration-rootless go lint-daemon lint-deploy test-server-sh test-install-sh lint-workflows test-install test-update test-move dev-daemon \
	dev-enroll-2 dev-enroll-3 test-failover gen gen-protocol gen-types gen-openapi design-tools-image device-art world-map fixtures test-next-adapter next-adapter caddy-image caddy-image-load \
	site-install site-dev site-build site-preview site-format lint-site test-cli lint-cli cli test-cli-e2e up-forgejo test-git-e2e

help: ## Show this help
	@awk 'BEGIN {FS = ":.*## "; printf "Usage: make <target> [SVC=...] [ARGS=...]\n\n"} \
		/^[a-zA-Z0-9_-]+:.*## / {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2} \
		/^## / {printf "\n%s\n", substr($$0, 4)}' $(MAKEFILE_LIST)

dev/.env:
	cp dev/.env.example dev/.env
	@echo "Created dev/.env from dev/.env.example (edit it to change ports)."

## Dev stack

up: dev/.env ## Start the dev stack (builds images, installs deps on first run, migrates)
	$(COMPOSE) build
	@# First run on a fresh clone: vendor/ and dashboard/.env are created inside the container.
	@test -d dashboard/vendor || $(COMPOSE) run --rm --no-deps dashboard composer install --no-interaction
	@if [ ! -f dashboard/.env ]; then \
		cp dashboard/.env.example dashboard/.env && \
		$(COMPOSE) run --rm --no-deps dashboard php artisan key:generate --no-interaction; fi
	@# Key files (KEK, CP signing key, registry token key) before Zot starts: Zot
	@# verifies registry tokens with the public key from the keys volume. Never overwrites.
	@$(COMPOSE) run --rm --no-deps dashboard php artisan launchroom:keys:generate --no-interaction >/dev/null
	$(COMPOSE) up -d --wait --wait-timeout 600
	-$(COMPOSE) exec dashboard php artisan migrate --no-interaction
	@if [ -f daemon/go.mod ]; then $(MAKE) --no-print-directory dev-daemon || \
		echo "warning: daemon build failed; server-1 runs dockerd without launchroomd until \`make dev-daemon\` succeeds"; fi
	@echo ""
	@echo "dashboard  http://localhost:$${LR_DEV_DASHBOARD_PORT:-8000}"
	@echo "mailpit    http://localhost:$${LR_DEV_MAILPIT_PORT:-8025}"
	@echo "apps       http://<name>.localhost:$${LR_DEV_HTTP_PORT:-8080}  https://<name>.localhost:$${LR_DEV_HTTPS_PORT:-8443}"

up-backups: dev/.env ## Start the dev S3 target for backups (versitygw, http://versitygw:7070)
	$(COMPOSE) --profile backups up -d --wait versitygw

up-forgejo: dev/.env ## Start the dev Forgejo (git providers e2e, http://127.0.0.1:3300; docs/19 §8)
	$(COMPOSE) --profile forgejo up -d --wait forgejo

test-git-e2e: dev/.env ## Git providers e2e against the dev Forgejo: connect, import, push → deploy, PR → preview (make up + dev-daemon)
	sh dev/git-e2e.sh

up-multi: dev/.env ## Start the dev stack plus Headscale, server-2 and server-3 (home build box behind NAT)
	$(COMPOSE_MULTI) up -d --build --wait --wait-timeout 600
	@# The first Headscale API key can only be minted inside its container; it
	@# goes to the dashboard over stdin (never argv/output), which replaces it
	@# with its own encrypted key when the mesh turns on (docs/05 §2).
	@if $(COMPOSE_MULTI) exec -T dashboard php artisan launchroom:mesh:bootstrap-key --check >/dev/null 2>&1; then \
		$(COMPOSE_MULTI) exec -T headscale headscale apikeys create --expiration 87600h 2>/dev/null | tail -n 1 | \
			$(COMPOSE_MULTI) exec -T dashboard php artisan launchroom:mesh:bootstrap-key; fi
	@# Headscale's keys are 0600 root; the dashboard's group may read them so
	@# control-plane backups include the mesh state (docs/14 §9.6).
	@$(COMPOSE_MULTI) run --rm --no-deps --user 0 -v launchroom-dev_headscale:/headscale --entrypoint sh dashboard -c \
		'for f in /headscale/noise_private.key /headscale/derp_server_private.key; do [ ! -f "$$f" ] || { chgrp $(LR_DEV_GID) "$$f" && chmod 0640 "$$f"; }; done'
	@echo "Next: make dev-enroll-2 (enrolls server-2; the mesh turns on when it joins), make dev-enroll-3 (server-3)."

# Enrolls a further fake server like a server added in the UI: a join token
# from the dashboard (launchroom:servers:join-token), the pinned CP key and the
# token into the server's data volume over stdin, a marker that wipes its
# nested Docker state (objects of an earlier enrollment carry the old server
# id and are never adopted), then the server is recreated. server-2's
# enrollment switches the mesh on (docs/05 §2). Needs `make up-multi` and an
# enrolled server-1 (`make dev-enroll`). ROLES overrides the roles.
comma := ,
define DEV_ENROLL
	@out=$$($(COMPOSE_MULTI) exec -T dashboard php artisan launchroom:servers:join-token --roles=$(if $(ROLES),$(ROLES),$(2)) --name=$(1)) || { echo "$$out"; exit 1; }; \
	key=$$(printf '%s' "$$out" | sed -n 's/.*"cpKey":"\([^"]*\)".*/\1/p'); \
	token=$$(printf '%s' "$$out" | sed -n 's/.*"token":"\([^"]*\)".*/\1/p'); \
	[ -n "$$token" ] && [ -n "$$key" ] || { echo "could not read the join token: $$out"; exit 1; }; \
	printf '%s' "$$key" | $(COMPOSE_MULTI) exec -T $(1) sh -c 'cat > /data/cp-key'; \
	printf '%s' "$$token" | $(COMPOSE_MULTI) exec -T $(1) sh -c 'umask 077 && cat > /data/join-token && rm -rf /data/enrollment.json /data/state.json /data/keys && touch /data/reset-docker'; \
	echo "join token written to $(1):/data/join-token"; \
	$(COMPOSE_MULTI) up -d --no-deps --force-recreate $(1)
endef

dev-enroll-2: dev/.env ## Enroll server-2's launchroomd (multi-server dev; after dev-enroll; ROLES=runtime,build)
	$(call DEV_ENROLL,server-2,runtime$(comma)build)

# server-3: the home build box behind NAT (role build). Its label
# location=home is set afterwards (launchroom:servers:update).
dev-enroll-3: dev/.env ## Enroll server-3 (home build box behind NAT, role build; ROLES=build)
	$(call DEV_ENROLL,server-3,build)

down: dev/.env ## Stop the dev stack (keeps volumes)
	$(COMPOSE_MULTI) --profile tools down --remove-orphans

nuke: dev/.env ## Remove ALL launchroom-dev containers, volumes, networks and images (FORCE=1 skips the prompt)
	@if [ "$(FORCE)" != "1" ]; then \
		printf "This deletes every launchroom-dev container, volume (DB, nested Docker state) and image. Continue? [y/N] "; \
		read answer; [ "$$answer" = "y" ] || [ "$$answer" = "Y" ] || { echo "Aborted."; exit 1; }; fi
	$(COMPOSE_MULTI) --profile tools down --volumes --remove-orphans
	@# Sweep anything left over, selected strictly by our label / compose project.
	@ids=$$(docker ps -aq --filter label=launchroom.dev=true); [ -z "$$ids" ] || docker rm -f $$ids
	@ids=$$(docker volume ls -q --filter label=launchroom.dev=true; docker volume ls -q --filter label=com.docker.compose.project=launchroom-dev); \
		[ -z "$$ids" ] || docker volume rm $$(echo "$$ids" | sort -u)
	@ids=$$(docker network ls -q --filter label=launchroom.dev=true); [ -z "$$ids" ] || docker network rm $$ids
	@ids=$$(docker image ls -q --filter label=launchroom.dev=true | sort -u); [ -z "$$ids" ] || docker image rm -f $$ids
	@echo "launchroom-dev removed."

ps: dev/.env ## Show dev stack containers
	$(COMPOSE_MULTI) ps

logs: dev/.env ## Follow logs (SVC=dashboard; SVC= for all)
	$(COMPOSE_MULTI) logs -f --tail=200 $(SVC)

shell: dev/.env ## Open a shell in a running service (SVC=dashboard)
	$(COMPOSE_MULTI) exec $(SVC) sh -c 'exec $$(command -v bash || command -v sh)'

artisan: dev/.env ## Run artisan in the dashboard container (ARGS="migrate:status")
	@$(call IN_DASHBOARD,php artisan $(ARGS))

composer: dev/.env ## Run composer in the dashboard container (ARGS="require foo/bar")
	@$(call IN_DASHBOARD,composer $(ARGS))

npm: dev/.env ## Run npm against the Linux node_modules volume (ARGS="install foo")
	$(COMPOSE) run --rm --no-deps vite /opt/launchroom-dev/with-node-modules.sh npm $(ARGS)

migrate: dev/.env ## Run dashboard migrations
	@$(call IN_DASHBOARD,php artisan migrate --no-interaction)

seed: dev/.env ## Seed the dev database
	@$(call IN_DASHBOARD,php artisan db:seed --no-interaction)

restart-workers: dev/.env ## Restart horizon, reverb and scheduler (pick up PHP code changes)
	$(COMPOSE) restart horizon reverb scheduler

# The Go tools image bakes in the host UID/GID; rebuilding is a fast cache hit
# but guarantees the image matches the cache volumes' ownership.
go-image: dev/.env
	@$(COMPOSE) build -q go

dev-daemon: dev/.env go-image ## Rebuild launchroomd; the fake servers restart it automatically
	@# restic (pinned, checksum-verified) next to launchroomd binary, before launchroomd restarts.
	$(COMPOSE) run --rm --no-deps -T go sh -s -- /out/restic < dev/go/fetch-restic.sh
	$(COMPOSE) run --rm --no-deps -e CGO_ENABLED=0 go \
		sh -c 'go build -trimpath -ldflags "-X main.version=$(LR_VERSION)-dev" -o /out/.launchroom-ambassador.tmp ./cmd/ambassador && mv /out/.launchroom-ambassador.tmp /out/launchroom-ambassador && go build -trimpath -ldflags "-X main.version=$(LR_VERSION)-dev" -o /out/.launchroomd.tmp $(DAEMON_MAIN) && mv /out/.launchroomd.tmp /out/launchroomd'

# Enrolls server-1's daemon as the control-plane server (docs/03 §3): issues a
# join token (idempotent; nothing happens while it is enrolled), pins the CP
# signing key in dev/.env (compose passes it as LR_CP_KEY) and in
# server-1:/data/cp-key (LR_CP_KEY_FILE), writes the token to
# server-1's data volume and recreates server-1 so launchroomd picks both up.
# The token travels via stdin, never argv. Needs `make up` and a finished /setup.
dev-enroll: dev/.env ## Enroll server-1's launchroomd with the local dashboard (after /setup)
	@$(COMPOSE) exec -T dashboard php artisan launchroom:keys:generate --no-interaction >/dev/null
	@out=$$($(COMPOSE) exec -T dashboard php artisan launchroom:servers:bootstrap-local) || { echo "$$out"; exit 1; }; \
	key=$$(printf '%s' "$$out" | sed -n 's/.*"cpKey":"\([^"]*\)".*/\1/p'); \
	token=$$(printf '%s' "$$out" | sed -n 's/.*"token":"\([^"]*\)".*/\1/p'); \
	server=$$(printf '%s' "$$out" | sed -n 's/.*"serverId":"\([^"]*\)".*/\1/p'); \
	[ -n "$$key" ] || { echo "could not read the control-plane key: $$out"; exit 1; }; \
	grep -v '^LR_DEV_CP_KEY=' dev/.env > dev/.env.tmp && echo "LR_DEV_CP_KEY=$$key" >> dev/.env.tmp && mv dev/.env.tmp dev/.env; \
	printf '%s' "$$key" | $(COMPOSE) exec -T server-1 sh -c 'cat > /data/cp-key'; \
	if [ -n "$$token" ]; then \
		printf '%s' "$$token" | $(COMPOSE) exec -T server-1 sh -c 'umask 077 && cat > /data/join-token && rm -f /data/enrollment.json /data/state.json'; \
		echo "join token written to server-1:/data/join-token"; \
	else \
		echo "server-1 is already enrolled as $$server (to re-enroll, run the artisan command launchroom:servers:bootstrap-local --force, then make dev-enroll again)"; \
	fi; \
	echo "LR_DEV_CP_KEY=$$key"; \
	$(COMPOSE) up -d --no-deps --force-recreate server-1

# Multi-server failover test (dev/failover-test/failover.sh):
# needs `make up-multi`, the three fake servers enrolled (dev-enroll,
# dev-enroll-2, dev-enroll-3) and launchroomd built. Takes ~10 minutes; kills
# and restores server-2, server-3 and the control plane. Evidence in
# dev/failover-test/out/.
test-failover: dev/.env ## Failover test: 3 servers, kills under load, redeploy (needs up-multi + all servers enrolled)
	sh dev/failover-test/failover.sh

dev-setup-token: dev/.env ## Print a first-run setup URL for the local dashboard
	@$(COMPOSE) exec -T dashboard php artisan launchroom:setup-token

## Quality

test: test-dashboard test-daemon ## Run all tests and checks

# TEST_DB lets parallel workstreams use separate databases, e.g.
# `make test-dashboard TEST_DB=launchroom_test_ui`. Must start with launchroom_test.
# Full Pest runs are parallel (LR_TOOL_CPUS workers): Laravel gives every
# worker its own copy, $(TEST_DB)_test_<n>, created on first use.
TEST_DB ?= launchroom_test

test-db: dev/.env
	@case "$(TEST_DB)" in launchroom_test|launchroom_test_[a-z0-9]*) ;; *) echo "TEST_DB must be launchroom_test or launchroom_test_<suffix>"; exit 2;; esac
	@$(COMPOSE) up -d --wait postgres >/dev/null
	@$(COMPOSE) exec -T postgres psql -U launchroom -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname='$(TEST_DB)'" | grep -q 1 \
		|| $(COMPOSE) exec -T postgres createdb -U launchroom -O launchroom "$(TEST_DB)"

test-dashboard: dev/.env test-db ## Dashboard: FULL gate (vp check, tsc, pint, rector, phpstan, all tests). Heavy: run once at integration, not per change
	$(HEAVY) test-dashboard $(COMPOSE) run --rm -e LR_TEST_DATABASE=$(TEST_DB) -e LR_TEST_PROCESSES=$(LR_TOOL_CPUS) test composer ci:check

check-dashboard: dev/.env test-db ## Dashboard: targeted checks on FILES="app/X.php tests/Feature/XTest.php ..." (paths relative to dashboard/; default: everything changed since the last checkpoint). Pint/rector/phpstan on them, tsc, Pest on the test files + ARGS="--filter=..."
	$(COMPOSE) run --rm -e LR_TEST_DATABASE=$(TEST_DB) -e FILES="$(strip $(if $(FILES),$(FILES),$(CHANGED_DASHBOARD)))" -e ARGS="$(ARGS)" test sh /opt/launchroom-dev/check-changed.sh

test-dashboard-pest: dev/.env test-db ## Dashboard: only Pest (ARGS="--filter=Deploy" etc.; TEST_DB as above). Without ARGS: the full suite, in parallel (heavy)
	$(if $(strip $(ARGS)),,$(HEAVY) test-dashboard-pest )$(COMPOSE) run --rm -e LR_TEST_DATABASE=$(TEST_DB) test php vendor/bin/pest $(if $(strip $(ARGS)),$(ARGS),--parallel --processes=$(LR_TOOL_CPUS))

lint: lint-dashboard lint-daemon lint-deploy lint-workflows ## Run all linters

lint-templates: dev/.env test-db ## Templates: schema, digest-pinned images, the Compose allow-list (docs/17 §3.3)
	$(COMPOSE) run --rm -e LR_TEST_DATABASE=$(TEST_DB) test php artisan launchroom:templates:lint

lint-dashboard: dev/.env ## Dashboard: pint --test, phpstan, vp check, tsc
	$(COMPOSE) run --rm --no-deps test sh -c 'composer lint:check && composer types:check && npm run check && npm run types:check'

PKG ?= ./...
test-daemon: dev/.env go-image ## launchroomd: go test -race $(PKG) (PKG=./internal/foo/... for a targeted run; the full run is heavy)
	$(if $(filter ./...,$(PKG)),$(HEAVY) test-daemon )$(COMPOSE) run --rm --no-deps go go test -race $(PKG)

# launchroom CLI (cli/, docs/16-api-and-cli.md): its own Go module, built and
# tested in the same Go tools container as launchroomd.
test-cli: dev/.env go-image ## CLI: go test -race ./... (cli/)
	$(COMPOSE) run --rm --no-deps -w /src/cli go go test -race ./...

lint-cli: dev/.env ## CLI: golangci-lint run (cli/)
	$(COMPOSE) run --rm --no-deps -w /src/cli golangci-lint golangci-lint run ./...

# CLI_OS/CLI_ARCH default to this machine (cross-compiled in Linux, CGO off).
CLI_OS   ?= $(shell uname -s | tr '[:upper:]' '[:lower:]')
CLI_ARCH ?= $(shell uname -m | sed -e 's/x86_64/amd64/' -e 's/aarch64/arm64/')

cli: dev/.env go-image ## CLI: build launchroom for this machine into cli/bin/ (CLI_OS/CLI_ARCH override)
	$(COMPOSE) run --rm --no-deps -w /src/cli -e CGO_ENABLED=0 -e GOOS=$(CLI_OS) -e GOARCH=$(CLI_ARCH) go \
		go build -trimpath -ldflags "-s -w -X main.version=$(LR_VERSION)-dev" -o bin/launchroom ./cmd/launchroom
	@echo "cli/bin/launchroom ($(CLI_OS)/$(CLI_ARCH))"

test-cli-e2e: dev/.env go-image ## CLI: end-to-end against the running dev stack (make up; signs in through the device flow)
	sh dev/cli-e2e.sh

# Integration tests run INSIDE the ephemeral dind-test daemon container (its
# network + PID namespaces, like launchroomd on a real server). The test binary is
# built in the go container into the itest-bin volume, then executed in
# dind-test; dind-test is removed afterwards (its image cache volume stays).
# ITEST=<name> runs the integration suite in its own compose project
# (launchroom-dev-itest-<name>: own dind-test, itest-bin and Go caches), so
# parallel workstreams don't kill each other's runs.
ITEST ?=
ITEST_COMPOSE = $(COMPOSE)$(if $(ITEST), -p launchroom-dev-itest-$(ITEST))

test-daemon-integration: dev/.env go-image ## launchroomd: integration tests against an ephemeral dind Docker daemon (ITEST=<name> isolates parallel runs)
	$(ITEST_COMPOSE) --profile tools up -d --wait --wait-timeout 120 dind-test
	@# launchroom's Caddy build (make caddy-image) for the multi-edge test; skipped when absent.
	@if docker image inspect $(CADDY_DEV_IMAGE) >/dev/null 2>&1; then docker save $(CADDY_DEV_IMAGE) | $(ITEST_COMPOSE) --profile tools exec -T dind-test docker load -q; fi
	$(ITEST_COMPOSE) run --rm --no-deps -e CGO_ENABLED=0 go sh -c 'go test -c -tags integration -o /itest/integration.test ./test/integration && go build -trimpath -o /itest/launchroom-ambassador ./cmd/ambassador && go build -trimpath -o /itest/launchroomd ./cmd/launchroomd && go build -trimpath -o /itest/dockerd-guard ./cmd/dockerd-guard && cp /src/dev/zot/config.json /itest/zot-config.json && rm -rf /itest/deploy && mkdir -p /itest/deploy && cp -r /src/deploy/install /src/deploy/daemon /src/deploy/control-plane /itest/deploy/'
	$(ITEST_COMPOSE) run --rm --no-deps -T go sh -s -- /itest/restic < dev/go/fetch-restic.sh
	@status=0; $(if $(findstring -test.run,$(ARGS)),,$(HEAVY) test-daemon-integration )$(ITEST_COMPOSE) --profile tools exec -T dind-test /opt/launchroom/bin/integration.test -test.v -test.timeout 75m $(ARGS) || status=$$?; \
		$(ITEST_COMPOSE) --profile tools rm --stop --force dind-test >/dev/null 2>&1; \
		$(if $(ITEST),$(ITEST_COMPOSE) --profile tools down -v >/dev/null 2>&1;) exit $$status

# Rootless Docker (D49, docs/11 §3.2): the same integration binary, run as a
# host-network container of a docker:dind-rootless daemon (RootlessKit's
# network namespace, like launchroomd on a rootless server) with
# dockerd-guard in front of the rootless socket and RootlessKit's API for
# the edge ports; only the TestRootless* tests (deploy, routing from outside
# through the exposed ports, measurements). ITEST=<name> isolates it.
ITEST_ROOTLESS_RUN = export DOCKER_HOST=unix:///run/user/1000/docker.sock && mkdir -p /tmp/lr-runner/tmp && cp /opt/launchroom/bin/integration.test /tmp/lr-runner/ && \
	tar -C /tmp/lr-runner -cf - . | docker import - lr-itest-runner:1 >/dev/null && \
	rm -rf /tmp/lr-runner && outer=$$(hostname -i | cut -d" " -f1) && api=$$(ls /tmp/rootlesskit*/api.sock /run/user/1000/dockerd-rootless/api.sock 2>/dev/null | head -n 1) && \
	docker run --rm --network host --pid host --cap-add NET_ADMIN --label launchroom.dev=true \
		-v /run/user/1000/docker.sock:/var/run/docker.sock -v $$api:/run/rootlesskit/api.sock \
		-e LR_ITEST_ROOTLESS=1 -e LR_GUARD_ROOTLESSKIT_API=/run/rootlesskit/api.sock -e LR_ITEST_OUTER_IP=$$outer \
		lr-itest-runner:1 /integration.test -test.v -test.timeout 20m -test.run TestRootless $(ARGS)

test-daemon-integration-rootless: dev/.env go-image ## launchroomd: TestRootless* against an ephemeral docker:dind-rootless daemon (ITEST=<name> isolates parallel runs)
	$(ITEST_COMPOSE) --profile tools up -d --wait --wait-timeout 180 dind-rootless-test
	$(ITEST_COMPOSE) run --rm --no-deps -e CGO_ENABLED=0 go sh -c 'go test -c -tags integration -o /itest/integration.test ./test/integration'
	@status=0; $(ITEST_COMPOSE) --profile tools exec -T dind-rootless-test sh -c '$(ITEST_ROOTLESS_RUN)' || status=$$?; \
		$(ITEST_COMPOSE) --profile tools rm --stop --force dind-rootless-test >/dev/null 2>&1; \
		$(if $(ITEST),$(ITEST_COMPOSE) --profile tools down -v >/dev/null 2>&1;) exit $$status

# launchroom's edge Caddy (deploy/images/caddy): xcaddy build with the Redis
# storage and DNS modules, for this machine's platform, labelled. `make
# caddy-image-load` puts it into the dev fake servers and prints the digest
# to pin (LR_SYSTEM_IMAGE_DIGESTS on the servers, LAUNCHROOM_CADDY_IMAGE on
# the dashboard; docs/11-installation-and-dev.md).
CADDY_DEV_IMAGE ?= launchroom-dev-caddy:dev

caddy-image: ## Build launchroom's Caddy image (xcaddy, pinned modules) as launchroom-dev-caddy:dev
	docker buildx build --load --label launchroom.dev=true -t $(CADDY_DEV_IMAGE) -f deploy/images/caddy/Dockerfile deploy/images/caddy

caddy-image-load: dev/.env ## Load launchroom-dev-caddy:dev into the dev fake servers and print its digest
	@for svc in $$($(COMPOSE_MULTI) ps --services --status running | grep '^server-'); do \
		docker save $(CADDY_DEV_IMAGE) | $(COMPOSE_MULTI) exec -T $$svc docker load -q >/dev/null && \
		echo "$$svc: $$($(COMPOSE_MULTI) exec -T $$svc docker image inspect $(CADDY_DEV_IMAGE) --format '{{index .RepoDigests 0}}')"; \
	done

go: dev/.env go-image ## launchroomd: run a go command in the Go tools container (ARGS="get example.com/mod@v1.2.3")
	@# GOPATH in the home dir: the sum database cache ($$GOPATH/pkg/sumdb) must be writable.
	$(COMPOSE) run --rm --no-deps -e GOPATH=/home/launchroom/go go go $(ARGS)

lint-daemon: dev/.env ## launchroomd: golangci-lint run $(PKG) (PKG=./internal/foo/... for a targeted run)
	$(if $(filter ./...,$(PKG)),$(HEAVY) lint-daemon )$(COMPOSE) run --rm --no-deps golangci-lint golangci-lint run $(PKG)

SHELLCHECK_IMAGE ?= koalaman/shellcheck:v0.11.0@sha256:61862eba1fcf09a484ebcc6feea46f1782532571a34ed51fedf90dd25f925a8d

BUSYBOX_IMAGE ?= busybox@sha256:bdf57e528e45e4433820e045b29b4597825a1c9e38353532d90a01445013f82e

lint-deploy: ## Install bundle: shellcheck (POSIX sh) of the install scripts and helper CLI
	docker run --rm --label launchroom.dev=true -v "$(CURDIR)/deploy:/mnt:ro" -w /mnt $(SHELLCHECK_IMAGE) \
		-s sh -x install/install.sh install/server.sh install/cli.sh control-plane/launchroom test/e2e-install.sh test/e2e-update.sh test/e2e-move.sh test/server-sh-test.sh test/install-sh-test.sh release/build-bundle.sh release/check-pinned-images.sh
	docker run --rm --label launchroom.dev=true -v "$(CURDIR)/release.sh:/mnt/release.sh:ro" -w /mnt $(SHELLCHECK_IMAGE) -s sh release.sh
	@# Release Dockerfiles: base images and frontends by digest only (docs/09 R31).
	docker run --rm --label launchroom.dev=true -v "$(CURDIR):/repo:ro" -w /repo $(BUSYBOX_IMAGE) sh deploy/release/check-pinned-images.sh

# server.sh's platform branches (macOS engines, refused setups, Linux layout)
# with stubbed uname/docker in a throwaway busybox container (D53).
test-server-sh: ## Install bundle: unit tests of server.sh's macOS/Linux branches (stubbed docker, busybox)
	docker run --rm --label launchroom.dev=true -v "$(CURDIR)/deploy:/mnt:ro" $(BUSYBOX_IMAGE) sh /mnt/test/server-sh-test.sh

# install.sh's host preparation (Docker and inotify prompts, update channel)
# with stubbed docker/curl/sysctl, a file as terminal and a fake /proc/sys.
test-install-sh: ## Install bundle: unit tests of install.sh's Docker/inotify prompts and update channel (stubs, busybox)
	docker run --rm --label launchroom.dev=true -v "$(CURDIR)/deploy:/mnt:ro" $(BUSYBOX_IMAGE) sh /mnt/test/install-sh-test.sh

ACTIONLINT_IMAGE ?= rhysd/actionlint:1.7.12@sha256:b1934ee5f1c509618f2508e6eb47ee0d3520686341fec936f3b79331f9315667

lint-workflows: ## GitHub Actions workflows: actionlint (with its shellcheck integration)
	docker run --rm --label launchroom.dev=true -v "$(CURDIR):/repo:ro" -w /repo $(ACTIONLINT_IMAGE) -color

# Fresh-install e2e (deploy/test/e2e-install.sh): builds the production
# dashboard, launchroom-caddy and daemon images (launchroomd with the caddy
# digest compiled in, like a release), starts an ephemeral privileged
# docker:dind "VPS" (labelled launchroom.dev=true, no host ports, removed
# afterwards; KEEP=1 keeps it), loads the images into it and runs install.sh +
# uninstall there. Needs internet in the VPS for postgres/valkey/zot pulls.
test-install: lint-deploy test-server-sh test-install-sh ## Install bundle: e2e fresh install + uninstall inside an ephemeral dind VPS
	$(HEAVY) test-install sh deploy/test/e2e-install.sh

test-update: lint-deploy dev/.env ## Control-plane update e2e: install 1.0.0, update to 1.1.0 from the dashboard, roll back a broken 1.2.0 (dind VPS)
	$(HEAVY) test-update sh deploy/test/e2e-update.sh

# Move e2e (deploy/test/e2e-move.sh, docs/14 §13): the control plane with its
# apps, data and domain moves from dind VPS A to B (install.sh --move), then a
# regular server C moves to D. Same images and rules as test-install
# (labelled, no host ports, removed afterwards; KEEP=1, SKIP_BUILD=1).
test-move: lint-deploy ## Move e2e: control plane A → B (install.sh --move), then server C → D (dind VPSes)
	$(HEAVY) test-move sh deploy/test/e2e-move.sh

## Packages

# packages/next-adapter (@vantezzen/launchroom-next-adapter) builds and tests
# in a throwaway node container (labelled; npm cache in the named volume
# launchroom-dev-npm-cache). Files it creates are handed back to the host user.
NPM_CACHE_VOLUME := launchroom-dev-npm-cache
NODE_RUN = docker run --rm --label launchroom.dev=true \
	--mount type=volume,src=$(NPM_CACHE_VOLUME),dst=/root/.npm,volume-label=launchroom.dev=true \
	-v "$(CURDIR)/packages/next-adapter:/w" -w /w

test-next-adapter: ## Next.js adapter package: npm ci, tsc build, node:test unit tests (in Docker)
	$(NODE_RUN) $(NODE_IMAGE) sh -c 'npm ci --no-audit --no-fund && npm test; s=$$?; chown -R $(LR_DEV_UID):$(LR_DEV_GID) node_modules dist; exit $$s'

# Vendors the built adapter into launchroomd (daemon/internal/build/nextadapter):
# the Next.js preset writes these files into the Railpack build step, so builds
# never fetch the adapter from npm. SHA256SUMS pins the exact bytes.
next-adapter: test-next-adapter ## Next.js adapter: build, test and vendor dist/ into launchroomd (updates SHA256SUMS)
	$(NODE_RUN) -v "$(CURDIR)/daemon/internal/build/nextadapter:/vendor" $(NODE_IMAGE) sh -c '\
		rm -rf /vendor/assets && mkdir -p /vendor/assets/dist && cp dist/*.js /vendor/assets/dist/ && \
		node -e "const p=require(\"./package.json\");process.stdout.write(JSON.stringify({name:p.name,version:p.version,type:\"module\",main:\"./dist/index.js\",license:p.license},null,2)+\"\\n\")" > /vendor/assets/package.json && \
		cd /vendor/assets && find . -type f | sed "s|^\./||" | LC_ALL=C sort | xargs sha256sum > /vendor/SHA256SUMS && \
		chown -R $(LR_DEV_UID):$(LR_DEV_GID) /vendor/assets /vendor/SHA256SUMS'
	@echo "Vendored $$(sed -n 's/.*\"version\": \"\(.*\)\".*/\1/p' daemon/internal/build/nextadapter/assets/package.json) into daemon/internal/build/nextadapter (bump nextadapter.Version if it changed)."

## Website

# site/ (@vantezzen/launchroom-site): the public website and user docs at
# launchroom.vantezzen.io, a plain Next.js app (fumadocs; docs/21). The targets
# only wrap its npm scripts (dev, build, start, check) in a throwaway node
# container: the repo is mounted read-only (prebuild refreshes the installer
# and JSON Schemas in site/public from it), site/ read-write, node_modules and
# the Next build/cache live in labelled named volumes. Files it creates on the
# host are handed back to the host user. CPU capped like the other tool
# containers (LR_TOOL_CPUS).
LR_TOOL_CPUS   ?= 4
LR_DEV_SITE_PORT ?= 3200
SITE_GENERATED = .source next-env.d.ts public/install public/schema package-lock.json
SITE_RUN = docker run --rm --label launchroom.dev=true --cpus $(LR_TOOL_CPUS) \
	--mount type=volume,src=$(NPM_CACHE_VOLUME),dst=/root/.npm,volume-label=launchroom.dev=true \
	--mount type=volume,src=launchroom-dev-site-node-modules,dst=/repo/site/node_modules,volume-label=launchroom.dev=true \
	--mount type=volume,src=launchroom-dev-site-next,dst=/repo/site/.next,volume-label=launchroom.dev=true \
	-v "$(CURDIR):/repo:ro" -v "$(CURDIR)/site:/repo/site" -w /repo/site -e NEXT_TELEMETRY_DISABLED=1
SITE_CHOWN = s=$$?; for f in $(SITE_GENERATED); do [ ! -e "$$f" ] || chown -R $(LR_DEV_UID):$(LR_DEV_GID) "$$f"; done; exit $$s
SITE_DEPS = if [ -f package-lock.json ]; then npm ci --no-audit --no-fund; else npm install --no-audit --no-fund; fi

site-install: ## Website: install site/ dependencies (npm ci; writes package-lock.json on the first run)
	$(SITE_RUN) --name launchroom-dev-site-install $(NODE_IMAGE) sh -c '$(SITE_DEPS); $(SITE_CHOWN)'

site-dev: ## Website: dev server (npm run dev) on http://127.0.0.1:$(LR_DEV_SITE_PORT) (LR_DEV_SITE_PORT in dev/.env)
	$(SITE_RUN) -it --name launchroom-dev-site -p 127.0.0.1:$(LR_DEV_SITE_PORT):3000 $(NODE_IMAGE) \
		sh -c '[ -x node_modules/.bin/next ] || $(SITE_DEPS); npm run dev -- -H 0.0.0.0 -p 3000; $(SITE_CHOWN)'

site-build: ## Website: production build (npm run build) into the launchroom-dev-site-next volume
	$(SITE_RUN) --name launchroom-dev-site-build $(NODE_IMAGE) sh -c '[ -x node_modules/.bin/next ] || $(SITE_DEPS); npm run build; $(SITE_CHOWN)'

site-preview: ## Website: npm run build && npm run start on http://127.0.0.1:$(LR_DEV_SITE_PORT), in the background
	$(SITE_RUN) -d --name launchroom-dev-site-preview -p 127.0.0.1:$(LR_DEV_SITE_PORT):3000 $(NODE_IMAGE) \
		sh -c '[ -x node_modules/.bin/next ] || $(SITE_DEPS); npm run build; s=$$?; for f in $(SITE_GENERATED); do [ ! -e "$$f" ] || chown -R $(LR_DEV_UID):$(LR_DEV_GID) "$$f"; done; [ $$s -eq 0 ] && exec npm run start -- -H 0.0.0.0 -p 3000'
	@echo "site preview  http://127.0.0.1:$(LR_DEV_SITE_PORT)  (builds first; logs: docker logs -f launchroom-dev-site-preview; stop: docker rm -f launchroom-dev-site-preview)"

site-format: ## Website: format site/ with Prettier
	$(SITE_RUN) --name launchroom-dev-site-format $(NODE_IMAGE) sh -c '[ -x node_modules/.bin/next ] || $(SITE_DEPS); npm run format; s=$$?; chown -R $(LR_DEV_UID):$(LR_DEV_GID) app components lib scripts *.json *.mjs *.md 2>/dev/null; exit $$s'

lint-site: ## Website: typecheck, ESLint and Prettier check of site/
	$(SITE_RUN) --name launchroom-dev-site-lint $(NODE_IMAGE) sh -c '[ -x node_modules/.bin/next ] || $(SITE_DEPS); npm run check; $(SITE_CHOWN)'

## Code generation

gen: gen-protocol gen-types gen-openapi ## Run all generators (protocol schemas, TS types, OpenAPI, wayfinder routes)
	@$(call IN_DASHBOARD,php artisan wayfinder:generate --with-form)

gen-protocol: dev/.env go-image ## Generate protocol JSON Schemas + signing/sealing test vectors from Go types
	$(COMPOSE) run --rm --no-deps go sh -c 'go generate ./internal/state && go test ./internal/identity -run TestSigningVectors -update && go test ./internal/sealed -run TestSealingVectors -update'

gen-types: dev/.env ## Generate TypeScript types from PHP Data objects
	@$(call IN_DASHBOARD,php artisan typescript:transform)

gen-openapi: dev/.env ## Regenerate the public API's OpenAPI document (dashboard/resources/api/openapi.json, docs/16)
	@$(call IN_DASHBOARD,php artisan launchroom:api:openapi)

## Design assets

# Tool image for the device art and world map generators (Python + Pillow/numpy/scipy).
DESIGN_TOOLS_IMAGE := launchroom-dev-device-art
DESIGN_TOOLS_RUN = docker run --rm --label launchroom.dev=true --user $(LR_DEV_UID):$(LR_DEV_GID)

design-tools-image:
	@docker build -q --label launchroom.dev=true -t $(DESIGN_TOOLS_IMAGE) dashboard/scripts/device-art >/dev/null

device-art: design-tools-image ## Rebuild the device cut-outs in dashboard/public/images/devices from docs/design/devices (offline)
	@mkdir -p dashboard/public/images/devices
	$(DESIGN_TOOLS_RUN) --network none -v "$(CURDIR)/docs/design/devices:/src:ro" \
		-v "$(CURDIR)/dashboard/public/images/devices:/out" -v "$(CURDIR)/dashboard/resources/js/components/devices:/ts" \
		-v "$(CURDIR)/dashboard/scripts/device-art:/work:ro" \
		$(DESIGN_TOOLS_IMAGE) python /work/process.py

world-map: design-tools-image ## Regenerate the dotted world map (Natural Earth 110 m) for ServerMap (downloads the data)
	$(DESIGN_TOOLS_RUN) -v "$(CURDIR)/dashboard/scripts/world-map:/work:ro" \
		-v "$(CURDIR)/dashboard/resources/js/components/map:/out" \
		$(DESIGN_TOOLS_IMAGE) python /work/build.py

## Fixtures

fixtures: dev/.env ## List fixture apps with their git-fixtures clone URLs and SHAs (rescans dev/fixtures; rebuilds git-fixtures if its code changed)
	@echo "Fixture apps in dev/fixtures/ (see dev/fixtures/README.md):"
	@if [ -n "$$($(COMPOSE) ps -q --status running git-fixtures 2>/dev/null)" ]; then \
		$(COMPOSE) up -d --build --wait git-fixtures >/dev/null 2>&1 || echo "(could not rebuild git-fixtures; listing the running one)"; \
		$(COMPOSE) exec -T git-fixtures wget -qO- http://127.0.0.1:8080/ | tr '{' '\n' | \
			sed -n 's/.*"name":"\([^"]*\)","sha":"\([^"]*\)","cloneUrl":"\([^"]*\)".*/  \1  \3  \2/p'; \
	else \
		ls -1 dev/fixtures | grep -v README.md | sed 's/^/  /'; \
		echo "(start the dev stack to serve them over git: make up)"; \
	fi

