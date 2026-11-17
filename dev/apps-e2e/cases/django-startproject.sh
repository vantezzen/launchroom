# `django-admin startproject` + requirements.txt. Note: *.localhost passes
# Django's DEBUG allowed hosts, so dev can't see a production ALLOWED_HOSTS
# failure; the probe Host check is covered by deploy green on non-localhost
# domains only.
# shellcheck shell=sh disable=SC2034
GAPS="G45 G70 G62"
PREVIEW_BRANCH=probe

login_post() { # HOST: admin login POST with a valid CSRF token is not a 403
	rm -f "$WORK/jar"
	fetch "$1" /admin/login/ -c "$WORK/jar"
	token="$(sed -n 's/.*name="csrfmiddlewaretoken" value="\([^"]*\)".*/\1/p' "$WORK/body" | head -n 1)"
	fetch "$1" /admin/login/ -b "$WORK/jar" -X POST -H "Origin: https://$1:$PORT" -H "Referer: https://$1:$PORT/admin/login/" \
		--data "csrfmiddlewaretoken=$token&username=nobody&password=wrong&next=/admin/"
	[ "$CODE" != 403 ] && [ "$CODE" -lt 500 ]
}

check_main() {
	expect "G45 G70" "/admin/login/ answers 200" code_is 200 "$APP" /admin/login/
	expect G70 "admin login POST over https passes CSRF (not 403)" login_post "$APP"
	expect G70 "admin CSS is served" code_is 200 "$APP" /static/admin/css/base.css
	todo G62 "SQLite volume offered" "needs the import plan"
}

check_preview() {
	expect G70 "request.is_secure() is true behind the edge" json_value_is secure true "$PREVIEW" /probe/
}
