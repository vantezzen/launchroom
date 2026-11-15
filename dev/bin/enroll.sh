#!/bin/sh
# Enrolls a dev fake server with the local dashboard like a server added in
# the UI (docs/03 §3). Run from the repo root (make dev-enroll, dev-enroll-2,
# dev-enroll-3):
#   sh dev/bin/enroll.sh server-1             the control-plane server: a join
#                                             token from launchroom:servers:bootstrap-local
#                                             (nothing happens while it is
#                                             enrolled), its CP key also pinned
#                                             in dev/.env (LR_DEV_CP_KEY)
#   sh dev/bin/enroll.sh server-2 ROLES NAME  a further server (make up-multi):
#                                             a join token for ROLES, its nested
#                                             Docker state wiped (objects of an
#                                             earlier enrollment carry the old
#                                             server id and are never adopted)
# The command prints {"token","cpKey",…} as one JSON line. The pinned CP key
# and the token go into the server's data volume over stdin (never argv),
# then the server is recreated so launchroomd picks them up.
set -eu

server=$1
if [ "$server" = server-1 ]; then
	compose="sh dev/bin/compose.sh"
	$compose exec -T dashboard php artisan launchroom:keys:generate --no-interaction >/dev/null
	set -- launchroom:servers:bootstrap-local
else
	compose="sh dev/bin/compose.sh -f dev/compose.multi.yml"
	set -- launchroom:servers:join-token "--roles=$2" "--name=${3:-$server}"
fi

out="$($compose exec -T dashboard php artisan "$@")" || { printf '%s\n' "$out"; exit 1; }
field() { printf '%s' "$out" | sed -n "s/.*\"$1\":\"\([^\"]*\)\".*/\1/p"; }
key="$(field cpKey)"
token="$(field token)"
[ -n "$key" ] || { printf 'could not read the control-plane key: %s\n' "$out"; exit 1; }

printf '%s' "$key" | $compose exec -T "$server" sh -c 'cat > /data/cp-key'
if [ "$server" = server-1 ]; then
	grep -v '^LR_DEV_CP_KEY=' dev/.env >dev/.env.tmp && echo "LR_DEV_CP_KEY=$key" >>dev/.env.tmp && mv dev/.env.tmp dev/.env
	if [ -z "$token" ]; then
		echo "server-1 is already enrolled as $(field serverId) (to re-enroll, run the artisan command launchroom:servers:bootstrap-local --force, then make dev-enroll again)"
	else
		printf '%s' "$token" | $compose exec -T "$server" sh -c 'umask 077 && cat > /data/join-token && rm -f /data/enrollment.json /data/state.json'
		echo "join token written to server-1:/data/join-token"
	fi
	echo "LR_DEV_CP_KEY=$key"
else
	[ -n "$token" ] || { printf 'could not read the join token: %s\n' "$out"; exit 1; }
	printf '%s' "$token" | $compose exec -T "$server" sh -c 'umask 077 && cat > /data/join-token && rm -rf /data/enrollment.json /data/state.json /data/keys && touch /data/reset-docker'
	echo "join token written to $server:/data/join-token"
fi
$compose up -d --no-deps --force-recreate "$server"
