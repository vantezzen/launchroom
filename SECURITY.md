# Security policy

## Report a vulnerability

Report security issues privately through [GitHub security advisories](https://github.com/vantezzen/launchroom/security/advisories/new), not in public issues, discussions or pull requests.

Include:

- what you found and its impact
- steps to reproduce it
- the launchroom version you tested (Settings → Updates, or the `VERSION` file)

## Supported versions

Security fixes go into the latest minor release. Update to it before reporting, if you can.

| Version | Supported |
| --- | --- |
| Latest minor release | Yes |
| Older releases | No |

## Scope

In scope is any path from a project member, an app container or a build to:

- root on a host
- another team's data
- another server

Examples: authorization bypasses, secret disclosure (logs, API responses, images), signature or HMAC checks that fail open, ways around launchroomd's local policy floor, injection into commands that launchroomd runs.

Out of scope:

- vulnerabilities in apps you deploy with launchroom
- attacks that need control of the dashboard host or an instance owner account
- missing hardening without a demonstrated impact

The security model is described in [docs/09-security.md](docs/09-security.md).

## What to expect

- An acknowledgement within 7 days.
- An assessment and, for a confirmed issue, a planned fix and release date.
- A published advisory once a fixed release is out, crediting you unless you ask not to be named.

Please give us a reasonable time to release a fix before you disclose the issue publicly.
