# Contributing to launchroom

Thanks for helping. This file covers how to set up, test and submit a change. The binding rules for code are in [AGENTS.md](AGENTS.md); they apply to people and coding agents alike.

## Before you start

- For anything larger than a small fix, open an issue or a discussion first, so the design can be agreed before you write code.
- Report security issues privately, see [SECURITY.md](SECURITY.md).

## Development setup

Everything runs in Docker. You need Docker and `make`; no PHP, Go or Node on the host.

```sh
make up      # build and start the dev stack, install dependencies, migrate
make help    # list every target
```

The dashboard is then on http://localhost:8000. Deployed test apps run on "fake servers" (Docker-in-Docker containers). Everything the dev stack creates is named `launchroom-dev*` and labelled `launchroom.dev=true`.

## Testing

Run the tests for what you changed, not the full suites:

```sh
# dashboard: Pint, Rector, PHPStan on the listed files, tsc, Pest on the listed tests
make check-dashboard FILES="app/Foo/Bar.php tests/Feature/Foo/BarTest.php"

# launchroomd
make test-daemon PKG=./internal/foo/...
make lint-daemon PKG=./internal/foo/...

# website
make lint-site
make site-build
```

CI runs the full suites on every pull request.

## Documentation

Docs change in the same pull request as the code they describe:

- `docs/` is the design and contributor documentation. Read [docs/README.md](docs/README.md) (decision log) and the doc for the area you touch. If your change contradicts a doc, update the doc.
- `site/content/docs` is the user documentation on the website. Update it when behaviour users see changes.

## Rules for code

The full list is in [AGENTS.md](AGENTS.md) and [docs/09-security.md](docs/09-security.md). The non-negotiable ones:

- No shell strings: argv arrays and API clients only (no `exec`, `shell_exec`, `sh -c`).
- No SSH and no "run a command on the host": launchroomd accepts signed desired state and typed tasks only.
- Authorize every controller action, channel and job through policies and team-scoped bindings.
- Never serialize or log secrets.
- Generate host-side names from IDs; validate every identifier on both the dashboard and launchroomd.
- Fail closed on signatures and HMACs.

UI changes follow [docs/design-system.md](docs/design-system.md).

## Pull requests

- Keep a pull request to one change. Split unrelated work.
- Describe what changed and how you tested it.
- Add or update tests for the behaviour you change.
- Make sure the targeted checks above pass and CI is green.
- Update `docs/` and `site/content/docs` where needed.
- Don't edit generated files by hand (for example `resources/js/types/generated.d.ts`, `protocol/schema/`); run `make gen`.

By contributing, you agree that your contributions are licensed under the project's [LICENSE](LICENSE).

## Releases

Maintainers cut releases by pushing a `v<version>` tag; see [RELEASING.md](RELEASING.md).
