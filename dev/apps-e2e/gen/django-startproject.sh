# Django: `django-admin startproject mysite .` and the requirements.txt that
# `pip freeze` writes after `pip install Django`. Nothing else.
# stage: LR_FIXTURE_PYTHON_IMAGE py_stage
# shellcheck shell=sh disable=SC2164,SC2103,SC1091 # stages run under set -e (_lib.sh)

py_stage() {
	python -m venv /tmp/venv
	. /tmp/venv/bin/activate
	pip install -q --disable-pip-version-check "Django==$LR_FIXTURE_DJANGO"
	cd /work/app
	django-admin startproject mysite .
	pip freeze >requirements.txt
}
