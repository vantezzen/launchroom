# Django the way production templates (cookiecutter-django style) set it up:
# startproject, then settings read from the environment with django-environ
# (DJANGO_SECRET_KEY, DJANGO_DEBUG, DJANGO_ALLOWED_HOSTS,
# DJANGO_CSRF_TRUSTED_ORIGINS, DATABASE_URL), the proxy's X-Forwarded-Proto
# trusted, gunicorn, Postgres via psycopg. The settings block is written here
# (hand-authored, no generator makes exactly this).
# stage: LR_FIXTURE_PYTHON_IMAGE py_stage
# shellcheck shell=sh disable=SC2164,SC2103,SC1091 # stages run under set -e (_lib.sh)

py_stage() {
	python -m venv /tmp/venv
	. /tmp/venv/bin/activate
	pip install -q --disable-pip-version-check "Django==$LR_FIXTURE_DJANGO" django-environ \
		"gunicorn==$LR_FIXTURE_GUNICORN" "psycopg[binary]"
	cd /work/app
	django-admin startproject config .
	cat >>config/settings.py <<'PY'

# Production settings from the environment (django-environ).
import environ  # noqa: E402

env = environ.Env()

SECRET_KEY = env("DJANGO_SECRET_KEY")
DEBUG = env.bool("DJANGO_DEBUG", default=False)
ALLOWED_HOSTS = env.list("DJANGO_ALLOWED_HOSTS", default=["localhost"])
CSRF_TRUSTED_ORIGINS = env.list("DJANGO_CSRF_TRUSTED_ORIGINS", default=[])
DATABASES = {"default": env.db("DATABASE_URL")}
SECURE_PROXY_SSL_HEADER = ("HTTP_X_FORWARDED_PROTO", "https")
STATIC_ROOT = BASE_DIR / "staticfiles"
PY
	pip freeze >requirements.txt
}
