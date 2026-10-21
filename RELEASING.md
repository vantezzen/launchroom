# Releasing launchroom

A release is a pushed `v<version>` tag. GitHub Actions (`.github/workflows/release.yml`) builds, signs and publishes everything. There are no signing keys to manage: signatures are cosign keyless, issued to this workflow on this tag. Installers and launchroomd accept a release only if its signature names exactly:

- **identity:** `https://github.com/vantezzen/launchroom/.github/workflows/release.yml@refs/tags/v<version>`
- **issuer:** `https://token.actions.githubusercontent.com`

Background: [docs/09-security.md](docs/09-security.md) R30–R31 and [docs/11-installation-and-dev.md](docs/11-installation-and-dev.md) §2.

## One-time setup

1. **Public repository.** The installer downloads release assets and images without logging in, so releases of a private repository can't be installed.
2. **Tag ruleset.** Settings → Rules → Rulesets → New tag ruleset:
   - target `refs/tags/v*`
   - restrict creation, update and deletion to the maintainers
   - no bypass for anyone else

   A `v*` tag is what lets the workflow produce a valid signature, so only maintainers may create one.
3. **`release` environment.** Settings → Environments → New environment, named exactly `release`:
   - **Deployment branches and tags:** Selected, add the tag rule `v*`.
   - **Required reviewers:** at least one maintainer.

   The signing jobs run in this environment, so nothing is signed or published until a reviewer approves the run.
4. **Public packages (after the first release).** GitHub creates new packages as private. Once the first release has pushed them, make each of these public:
   - `ghcr.io/vantezzen/launchroom-caddy`
   - `ghcr.io/vantezzen/launchroom-dashboard`
   - `ghcr.io/vantezzen/launchroomd`

   For each one: your profile → Packages → the package → Package settings → Change visibility → Public. Until then, installs fail when pulling images.

## Cutting a release

`./release.sh <version>` runs steps 2 and 3 for you. It refuses to run if:
- you're not on `main`;
- the working tree isn't clean;
- the version isn't a semantic version;
- the tag already exists.

```bash
./release.sh 0.1.0-rc.1
```

By hand:

1. Make sure CI is green on `main`.
2. Set the version. The root `VERSION` file is the single source of truth for the dashboard image, the launchroomd build and the tag. It must be a semantic version, and a version with a hyphen (`0.2.0-rc.1`) is published as a pre-release.

   ```bash
   echo "0.1.0" > VERSION
   git commit -am "Release v0.1.0"
   git push origin main
   ```
3. Tag the commit and push the tag:

   ```bash
   git tag v0.1.0
   git push origin v0.1.0
   ```
4. In GitHub → Actions → **release**, approve the run when it waits for the `release` environment.

The workflow refuses to continue if:
- the tag isn't `v` followed by the contents of `VERSION`, or
- the tagged commit isn't on the default branch.

## What a release contains

- **Images:**
  - `launchroom-caddy`, `launchroom-dashboard` and `launchroomd`
  - for `linux/amd64` and `linux/arm64`
  - with SBOM and SLSA provenance attestations and cosign signatures
  - tagged `<version>`, plus `latest` for stable releases

  launchroom-caddy is built first. Its digest is compiled into launchroomd, so each signed launchroomd only runs its own release's edge Caddy ([docs/05](docs/05-networking-ingress.md) §4).
- **GitHub release assets:**
  - `launchroom-deploy.tar.gz`, plus `.sha256`, `.sigstore.json` and provenance
  - `images.env` (the digests of this release)
  - `install.sh`
- **CLI:**
  - `launchroom_<version>_<os>_<arch>` archives for Linux and macOS (amd64 and arm64) and Windows (amd64)
  - signed `SHA256SUMS`
  - `cli.sh`

## Checking a release

Verify the bundle by hand:

```bash
cosign verify-blob --bundle launchroom-deploy.tar.gz.sigstore.json \
  --certificate-identity https://github.com/vantezzen/launchroom/.github/workflows/release.yml@refs/tags/v0.1.0 \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com \
  launchroom-deploy.tar.gz
```

Then install it on a throwaway server:

```bash
curl -fsSL https://github.com/vantezzen/launchroom/releases/download/v0.1.0/install.sh \
  | sh -s -- --version 0.1.0 --domain dash.example.com
```

- **Pre-releases:** `latest` skips them, so always pass `--version` when installing one.
- **Install URL:** once the website is deployed, `https://launchroom.vantezzen.io/install` serves the same `install.sh`.

## Testing self-update

1. On an installed instance, open Settings → Updates and pick a channel:
   - **stable** offers only full releases;
   - **beta** also offers pre-releases.
2. Cut the next release, then click **Update**. The update:
   - re-verifies the signed bundle against the same identity;
   - backs up the control plane;
   - rolls launchroomd out to every server;
   - rolls back on its own if the new version doesn't come up healthy.

## When a release is bad

Tags can't be moved or reused: the ruleset prevents it, and installs pin the tag in the signature identity. Fix it forward instead:

1. Mark the GitHub release as a pre-release, or add a note at the top of its notes, so `latest` no longer points at it.
2. Fix the problem on `main`.
3. Release the next patch version, e.g. `0.1.1`.

Instances that already installed the bad version pick up the fix through Settings → Updates.

## Before the first public release

Neither the release workflow nor CI has run on GitHub yet. Start with a release candidate:

1. Release `v0.1.0-rc.1` and install it on a throwaway server.
2. Release `v0.1.0-rc.2` and update to it from the dashboard on the beta channel.
3. Release `v0.1.0` once both work.
