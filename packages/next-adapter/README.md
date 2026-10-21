# @vantezzen/launchroom-next-adapter

A [Next.js Deployment Adapter](https://nextjs.org/docs/app/api-reference/config/next-config-js/adapterPath) (Next.js ≥ 16.2) for self-hosting on [launchroom](https://launchroom.vantezzen.io), or anywhere you run `node server.js` in a container.

It:

- forces `output: 'standalone'`, so the image needs the traced server (~40 MB) instead of all of `node_modules` (~340 MB for a starter app);
- sets `deploymentId` (skew protection, consistent asset URLs across replicas);
- pins `outputFileTracingRoot` when the build context root is known (monorepos);
- copies `public/` and `.next/static` next to the standalone `server.js`, which Next leaves out on purpose;
- writes `launchroom-next-manifest.json` (routes, middleware/proxy, image optimization, ISR routes, server entry) for the launchroom dashboard.

Zero runtime dependencies, ESM, MIT.

## On launchroom

Nothing to do. For Next ≥ 16.2 the `nextjs` build preset injects a vendored, checksum-pinned copy of this package through `NEXT_ADAPTER_PATH`. Your repository and `package.json` are never touched, and nothing is downloaded from npm at build time. The decision shows up as the "launchroom deployment adapter" chip in the build plan; remove the chip to build with plain `next start`.

Your own `adapterPath` in `next.config` takes precedence over `NEXT_ADAPTER_PATH`. launchroom then falls back to its older standalone handling and warns (`own-next-adapter`).

## Elsewhere

```bash
npm install --save-dev @vantezzen/launchroom-next-adapter
```

Either point the environment at it at build time (no config change):

```bash
NEXT_ADAPTER_PATH="$(node -p "require.resolve('@vantezzen/launchroom-next-adapter')")" next build
```

or set it in `next.config.mjs`:

```js
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);

export default {
  adapterPath: require.resolve('@vantezzen/launchroom-next-adapter'),
};
```

Then run `node .next/standalone/server.js` with `HOSTNAME=0.0.0.0` (Docker sets `HOSTNAME` to the container id, and `server.js` binds to it) and `PORT`.

If you'd rather run the finalize step yourself (see [How it works](#how-it-works)), set `LAUNCHROOM_NEXT_FINALIZE=manual` and run the CLI after the build:

```bash
LAUNCHROOM_NEXT_FINALIZE=manual next build && npx launchroom-next-finalize
```

`launchroom-next-finalize [projectDir] [--dist-dir <dir>]` is idempotent. It exits with 1 if there is no standalone output, and prints one `launchroom-next-summary {…}` JSON line that launchroomd reads.

## Environment variables

All of them are optional and read at build time.

| Variable | Effect |
|---|---|
| `LAUNCHROOM_DEPLOYMENT_ID` | Sets `deploymentId` (`[A-Za-z0-9_-]{1,64}`). An explicit `deploymentId` in `next.config` or `NEXT_DEPLOYMENT_ID` wins, with a warning. |
| `LAUNCHROOM_REPO_ROOT` | Absolute path of the build context root. Sets `outputFileTracingRoot` and `turbopack.root` (they must match) if the project is inside it. |
| `LAUNCHROOM_NEXT_FINALIZE` | `manual` disables the exit hook (run `launchroom-next-finalize` yourself). |
| `LAUNCHROOM_NEXT_CACHE` | Reserved for a shared cache handler, which is not implemented. Any value other than `local` (the default) makes the adapter warn (`cache-handler-missing`), unless your `next.config` sets `cacheHandler`/`cacheHandlers`, which is kept. |

`next dev` is never modified.

### Config rules

| Setting | Behaviour |
|---|---|
| `output` unset | set to `'standalone'` |
| `output: 'standalone'` | kept |
| `output: 'export'` | kept, with a warning (a static export has no server; launchroom serves it as static files) |
| `deploymentId` set | kept; `LAUNCHROOM_DEPLOYMENT_ID` is ignored with a warning if it differs |
| `cacheHandler` / `cacheHandlers` | kept and reported in the manifest |

## Manifest

`.next/launchroom-next-manifest.json`, copied to `.next/standalone/launchroom-next-manifest.json`:

```jsonc
{
  "schemaVersion": 1,
  "adapter": { "name": "launchroom", "version": "0.1.0" },
  "nextVersion": "16.4.2",
  "buildId": "…",
  "deploymentId": "dpl_…",
  "output": "standalone",
  "standalone": { "appDir": ".next/standalone", "serverEntry": ".next/standalone/server.js" },
  "middleware": { "present": true, "runtime": "nodejs", "matchers": ["/((?!_next/static).*)"] },
  "imageOptimization": { "enabled": true, "loader": "default", "sharp": true, "remotePatterns": 0 },
  "cacheHandler": false,
  "routes": { "appPages": 2, "appRoutes": 1, "pages": 0, "pagesApi": 0, "edge": 0, "staticFiles": 11,
              "truncated": false, "list": [{ "pathname": "/", "kind": "app-page", "runtime": "nodejs" }] },
  "prerenders": { "static": 1, "isr": [{ "pathname": "/isr", "route": "/isr", "revalidate": 60 }] },
  "warnings": []
}
```

Route lists leave out `.rsc` variants and internal pages (`/_not-found`, …) and are capped at 500 entries. `imageOptimization.enabled` comes from the config: on unless `images.unoptimized` is set or a custom loader is used. `sharp` says whether `sharp` resolves from the project.

## Multi-replica checklist

- Set `NEXT_SERVER_ACTIONS_ENCRYPTION_KEY` as a **build** variable; it is embedded in the build output.
- Run every replica from the same image. launchroom builds once and promotes the image, and `deploymentId` stays the same.
- ISR and `revalidateTag` are per replica unless you configure a shared `cacheHandler`/`cacheHandlers` yourself. The adapter ships no cache handler: a Redis one needs a Redis client, which doesn't fit zero runtime dependencies.

## How it works

Verified against the published `next` packages 16.2.0 and 16.4.2. Re-check these points when raising the supported Next version.

### Loading

- `adapterPath` is a stable config key since Next 16.2.0 (it moved from `experimental.adapterPath`; the old key still works with a warning).
- `NEXT_ADAPTER_PATH` is only the default of `adapterPath`, so an `adapterPath` in `next.config` wins over the environment.
- Next loads the adapter with `import(pathToFileURL(require.resolve(adapterPath)))`, resolved from Next's own module. A package name only resolves where Next can see it; an absolute path works anywhere. The module is ESM with a default export (`default` is taken when present).

### Hooks

The adapter implements both hooks of Next's `NextAdapter` interface:

- `modifyConfig(config, { phase, nextVersion, projectDir })` runs after Next applied its defaults and validation, for every command that loads the config, `next dev` included (the adapter returns the config unchanged in `phase-development-server`). The config is complete: `outputFileTracingRoot` and `turbopack.root` are resolved (Next requires them to be equal, so the adapter sets both), and `NEXT_DEPLOYMENT_ID` is already copied into `deploymentId`, which is why it wins over `LAUNCHROOM_DEPLOYMENT_ID`.
- `onBuildComplete({ routing, outputs, projectDir, repoRoot, distDir, config, nextVersion, buildId })` builds the manifest from `outputs` (`pages`, `pagesApi`, `appPages`, `appRoutes`, `prerenders`, `staticFiles`, `middleware`). `appPages` also lists the `.rsc` variant of each page, and `middleware` covers both `middleware.ts` and `proxy.ts`.

### Why the copy runs after `next build`

Next calls `onBuildComplete` **before** it writes `.next/standalone`, and the standalone step deletes that directory first. Anything the hook wrote there would be lost. So:

1. `onBuildComplete` writes the manifest to `<distDir>/launchroom-next-manifest.json`.
2. `finalize()` runs after `next build`: it reads `relativeAppDir` from `<distDir>/required-server-files.json` to find `server.js` inside the traced tree (monorepos), copies `public/` and `<distDir>/static` next to it and places the manifest there. It is synchronous and idempotent.
3. By default `onBuildComplete` registers a `process.once('exit')` hook that runs `finalize()` when `next build` exits with code 0, and sets exit code 1 if it fails. With `LAUNCHROOM_NEXT_FINALIZE=manual` you run `launchroom-next-finalize` instead (launchroom builds do this).

A comment in Next's build code says `output: 'standalone'` might later be disallowed together with an `onBuildComplete` adapter. If that happens, the adapter has to assemble the server from `outputs` (route outputs carry complete traced file lists) or drop `onBuildComplete`.

### Runtime

- The standalone `server.js` uses the config embedded at build time and runs neither the config defaults nor `modifyConfig`. The embedded config still names `adapterPath`, but nothing imports it, so the adapter doesn't need to be in the runtime image.
- With an adapter set, Next writes prerender cache files under route cache keys and the standalone step copies them, so ISR pages work from standalone output.
- Asset URLs carry `?dpl=<deploymentId>`.

### Size

Fixture `dev/fixtures/next-adapter` (Next 16.4.2, arm64):

| | Size |
|---|---|
| `node_modules` after `npm ci` | 338 MB |
| `.next/standalone` (traced server + deps) | 41 MB |
| launchroom image (Railpack) with the adapter | 692 MB (app layer 40.8 MB) |
| launchroom image with `next start` | 1083 MB |

The hooks add milliseconds to `next build`.

## API

```ts
import adapter, { createAdapter, applyLaunchroomConfig, buildManifest, summarize, finalize } from '@vantezzen/launchroom-next-adapter';
```

`createAdapter({ env, log, finalizeOnExit })` returns a fresh adapter (the default export uses `process.env`). The pure helpers are exported for testing and tooling.

## Development

Everything runs in Docker from the repository root:

```bash
make test-next-adapter   # npm ci, tsc, node:test
make next-adapter        # ...and vendor dist/ into launchroomd (daemon/internal/build/nextadapter, SHA256SUMS)
```

See [RELEASING.md](RELEASING.md) for publishing.
