#!/bin/sh
# NAT router between server-3's internal "home" network and the dev network.
# Env: LR_HOME_SUBNET (e.g. 10.213.77.0/24).
set -eu

subnet="${LR_HOME_SUBNET:?LR_HOME_SUBNET is required}"
prefix="${subnet%.*}."

home_if=""
up_if=""
for dev in $(ip -o -4 addr show | awk '$2 != "lo" {print $2}'); do
	addr="$(ip -o -4 addr show dev "$dev" | awk '{print $4}' | cut -d/ -f1)"
	case "$addr" in
	"$prefix"*) home_if="$dev"; home_ip="$addr" ;;
	*) up_if="$dev" ;;
	esac
done
[ -n "$home_if" ] && [ -n "$up_if" ] || { echo "nat-router: need one home and one uplink interface" >&2; exit 1; }

# Outbound only (CGNAT): masquerade home → uplink; from the uplink only replies
# to connections the home side opened get through.
iptables -t nat -A POSTROUTING -s "$subnet" -o "$up_if" -j MASQUERADE
iptables -A FORWARD -i "$home_if" -o "$up_if" -j ACCEPT
iptables -A FORWARD -i "$up_if" -o "$home_if" -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT
iptables -A FORWARD -i "$up_if" -o "$home_if" -j DROP
echo "nat-router: $home_if ($home_ip, $subnet) → $up_if, outbound only"

exec dnsmasq --keep-in-foreground --log-facility=- --no-resolv --no-hosts \
	--server=127.0.0.11 --listen-address="$home_ip" --bind-interfaces --cache-size=0
