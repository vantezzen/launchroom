# unplannable-site

A static page in `www/`, meant to be served by busybox httpd on port 8080. There is
no manifest and nothing at the root Railpack recognises, so its plan fails with
`plan_error`. The dev stack uses it for the "Generate a Dockerfile" end to end
(docs/20 §8); the stub model answers with `dev/dashboard/stub-responses/unplannable-site.json` (in the dashboard container: `/opt/launchroom-dev/stub-responses/unplannable-site.json`).
