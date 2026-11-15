#!/bin/sh
# docker compose for the dev stack (docs/11 §6): project launchroom-dev, the
# image pins (deploy/control-plane/images.env, deploy/pins.env) and dev/.env
# (last, so local settings win) for interpolation. Run from the repo root;
# further -f files and the command follow:
#   sh dev/bin/compose.sh -f dev/compose.multi.yml ps
set -eu
exec docker compose --project-directory dev \
	--env-file deploy/control-plane/images.env --env-file deploy/pins.env --env-file dev/.env \
	-f dev/compose.yml "$@"
