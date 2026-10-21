# Releasing @vantezzen/launchroom-next-adapter

launchroom itself does **not** need the npm package: launchroomd embeds a vendored, checksum-pinned build (`daemon/internal/build/nextadapter`). Publishing to npm is for people who use the adapter outside launchroom, or who want to set `adapterPath` themselves.

## Every change to the package

1. Edit `src/` and `test/`, then run `make test-next-adapter` (Docker).
2. If the behaviour changed, bump `version` in `package.json` **and** `src/version.ts` (a unit test keeps them equal).
3. `make next-adapter`. This rebuilds, tests and vendors `dist/*.js` plus a minimal `package.json` into `daemon/internal/build/nextadapter/assets`, and rewrites `SHA256SUMS`.
4. Set `nextadapter.Version` in `daemon/internal/build/nextadapter/nextadapter.go` to the new version. `make test-daemon` checks that the vendored files match `SHA256SUMS`, that the vendored version matches `packages/next-adapter`, and that the summary marker matches.
5. If the manifest summary changed, update `NextBuildInfo` (`daemon/internal/state/build_results.go`), the parser (`daemon/internal/build/next.go`), `make gen-protocol` and `protocol/CHANGELOG.md`.
6. If the preset behaviour changed, bump the `nextjs` preset version (`daemon/internal/build/presets/frameworks.go`).
7. `make test-daemon-integration ARGS="-test.run TestBuildNextAdapterAndRun"` builds the `next-adapter` fixture through Railpack + BuildKit and runs it.

CI (`.github/workflows/next-adapter.yml`) rebuilds the package and fails if the vendored copy differs from the build of `src/`.

## Publishing to npm (maintainer)

Not set up yet: it needs the `@vantezzen` npm scope (org) and a token or trusted publishing.

1. Make sure `main` is green and the version is bumped as above. Tag it `next-adapter-v<version>`.
2. Locally (in Docker, never on the host):
   ```bash
   docker run --rm -it --label launchroom.dev=true -v "$PWD/packages/next-adapter:/w" -w /w node:24 \
     sh -c 'npm ci && npm test && npm pack --dry-run && npm publish --access public --provenance'
   ```
   `--provenance` only works from CI with OIDC. Locally, leave it out or prefer the workflow.
3. Or in CI: enable npm trusted publishing for `@vantezzen/launchroom-next-adapter` with this repository and workflow, then uncomment the `publish` job in `.github/workflows/next-adapter.yml`. It runs on `next-adapter-v*` tags.
4. Check the tarball contents (`npm pack --dry-run`): only `dist/`, `README.md`, `LICENSE` and `package.json`.

Versioning: semver. A change to the manifest or summary schema is a minor bump while we are at 0.x. Keep `schemaVersion: 1` compatible, or bump it together with launchroomd parser.
