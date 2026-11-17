# Django with django-environ settings (DJANGO_ALLOWED_HOSTS, DJANGO_SECRET_KEY,
# DATABASE_URL), gunicorn, Postgres. The probe branch adds /probe/.
# shellcheck shell=sh disable=SC2034
GAPS="G47 G70"
DATABASE=1
PREVIEW_BRANCH=probe

check_main() {
	expect "G47 G70" "/admin/login/ answers 200 (allowed hosts and secret key from the environment)" code_is 200 "$APP" /admin/login/
}

check_preview() {
	expect G70 "request.is_secure() is true (no shim needed)" json_value_is secure true "$PREVIEW" /probe/
	expect G47 "the preview's host is allowed" json_value_is host "$PREVIEW(:$PORT)?" "$PREVIEW" /probe/
}
