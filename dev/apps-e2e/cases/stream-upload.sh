# Small Node app: server-sent events and a 200 MB streamed upload.
# shellcheck shell=sh disable=SC2034
GAPS="G59"
PREVIEW_BRANCH=""

sse_incremental() { # HOST: with gzip accepted, the first event arrives long before the last
	t="$(curl -sk --compressed -N --max-time 30 --resolve "$1:$PORT:127.0.0.1" -o "$WORK/body" \
		-w '%{time_starttransfer} %{time_total}' "https://$1:$PORT/events?count=5&interval=1000")"
	first="${t% *}" total="${t#* }"
	DETAIL="first byte after ${first}s, done after ${total}s: $(head -c 200 "$WORK/body" | tr '\n' ' ')"
	awk -v f="$first" -v t="$total" 'BEGIN { exit !(f < 2 && t >= 4) }'
}

upload() { # HOST: a 200 MB multipart upload passes the firewall in Block and arrives whole
	[ -f "$WORK/upload.bin" ] || dd if=/dev/urandom of="$WORK/upload.bin" bs=1048576 count=200 2>/dev/null
	FETCH_TIMEOUT=600 fetch "$1" /upload -X POST -F "file=@$WORK/upload.bin"
	bytes="$(sed -n 's/.*"bytes":\([0-9]*\).*/\1/p' "$WORK/body")"
	[ "$CODE" = 200 ] && [ "${bytes:-0}" -gt 209715200 ]
}

check_main() {
	expect G59 "SSE events arrive one by one with Accept-Encoding: gzip" sse_incremental "$APP"
	expect G59 "200 MB multipart upload passes in firewall Block" upload "$APP"
	rm -f "$WORK/upload.bin"
	todo G59 "a 10-minute stream stays open" "long-running; by hand: curl -N .../events?count=600"
}
