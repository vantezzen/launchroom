# Dockerfile with USER 1000 writing to /data, which only a volume provides.
# shellcheck shell=sh disable=SC2034
GAPS="G76"
SKIP="needs a volume at /data; launchroom:dev:deploy-app can't attach one yet (the check: GET /write answers 200)"
