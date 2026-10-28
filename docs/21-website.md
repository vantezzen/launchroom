# 21: Public website and user docs

`launchroom.vantezzen.io` is the public face of launchroom: a landing page, feature pages and the **user docs**. It lives in `site/` (D52) and is a **plain Next.js app**: no Dockerfile, no custom server, no special `output`, so it deploys like any Next.js project on any Next.js host, including launchroom itself.

## 1. Two kinds of docs

| | User docs (`site/content/docs`) | Repo docs (`docs/`, this folder) |
|---|---|---|
| Reader | Developers and operators **using** launchroom | Contributors and agents **building** launchroom |
| Covers | Install, servers, projects, builds, databases, backups, observability, teams and security, API/CLI, troubleshooting, and the internals a user benefits from (what launchroomd does, the mesh, the security model) | Decisions, architecture, protocol, data model, conventions, roadmap |
| Never contains | Class/package/table names, decision IDs, phase names, links into `docs/` | Marketing copy |
| URL | `https://launchroom.vantezzen.io/docs/...` | GitHub only |

When a feature changes behaviour users see, update the user docs page in the same change as the repo doc. Facts in user docs must be checked against the code (installer flags, CLI commands, UI labels, defaults).

## 2. Stack

- **Next.js 16** (App Router, TypeScript strict) with **Tailwind 4**; **fumadocs** (`fumadocs-core` 16, `fumadocs-ui` as `@fumadocs/base-ui` 16, `fumadocs-mdx` 15) for the docs; MDX content in `site/content/docs` with `meta.json` files for the sidebar order.
- Standard scripts only: `dev`, `build`, `start`, `lint`, plus `check` (typecheck, ESLint, Prettier). `next.config.mjs` stays minimal: fumadocs' MDX plugin, the cache headers of §3 and `images.qualities` (screenshots use 85). Pages, the sitemap, robots, the OG image and `llms.txt`/per-page Markdown are prerendered at build time; the server renders only what isn't (404s, search, image optimization).
- Search is fumadocs' **server search route**: `/api/search?query=…` answers from an Orama index the server builds in memory; fumadocs-ui's default dialog calls it (no multi-megabyte index download in the browser).
- Look and feel: the dashboard's design tokens (`dashboard/resources/css/app.css`, [design-system](design-system.md)) are **copied** into `site/app/global.css` and fumadocs' `--color-fd-*` variables are mapped onto them. The site never imports from `dashboard/`. Geist via the `geist` package (self-hosted). Light and dark follow the system with a manual switch.
- Marketing pages (`site/app/(home)`): landing page and `/features/{deployments,servers,databases,analytics,security}` (`/features/observability`, the old "Logs and metrics" page, redirects permanently to `/features/analytics` in `next.config.mjs`). Visuals are real screenshots (`<Screenshot>`, optionally cut to a `region` in CSS pixels of the captured page) or static recreations of dashboard components (`components/marketing/*`: mini canvas, deployment steps, server cards with the device art, promote diff, architecture diagram, what the edge keeps of a request, the "Action needed" rules). No live demo instance.
- The demo video slot (`components/marketing/demo-video.tsx`) renders nothing until `demoVideo` in `site/lib/shared.ts` or `NEXT_PUBLIC_LR_DEMO_VIDEO_URL` at build time names a video.
- SEO: per-page metadata and canonical URLs, a static Open Graph image (`app/og.png`, set in `app/layout.tsx`), `sitemap.xml`, `robots.txt`, favicons (`app/icon.svg`, `app/favicon.ico`, `app/apple-icon.png`), `llms.txt` / per-page Markdown from fumadocs.

## 3. Files the site serves from the repo

The site serves these repo files verbatim. Copies are **committed** in `site/public`, so a host that only has the `site/` folder (launchroom with root directory `site/` builds that folder alone) still serves them. `site/scripts/sync-public.mjs` runs as `predev`/`prebuild` and refreshes them from the repo files, which stay the source of truth; without the repo around it skips with a note. CI fails when the committed copies are stale.

| URL | Source | Used by |
|---|---|---|
| `/install` | `deploy/install/install.sh` | `curl -fsSL https://launchroom.vantezzen.io/install \| sh`, the recovery kit |
| `/schema/launchroom.v1.json` | `dashboard/resources/schema/launchroom.v1.json` | `$schema` of `launchroom.json` (D45) |
| `/schema/v1/*.json` | `protocol/schema/v1/*.json` | `$id` of the protocol schemas |

Headers (`next.config.mjs`): `/install` is `text/plain; charset=utf-8` with `Cache-Control: public, max-age=0, must-revalidate`, so `curl … | sh` always gets the current script; `/schema/*` is cacheable for an hour and sends `Access-Control-Allow-Origin: *` for browser-based validators.

The installer itself verifies the signed release bundle it downloads, so serving the script from the site adds no trust beyond the HTTPS origin of the site.

## 4. Screenshots

Real dashboard screenshots live in `site/public/screenshots` as **one full-size source per theme** (`<id>-<light|dark>.webp`, lossless WebP from the original PNG capture, about 60–250 KB at 2×) with `site/lib/screenshots.json` (alt text, caption, the capture's `scale`, and per theme the intrinsic size and a 16 px blur placeholder). Captures are taken at **2× device pixels**: a 1440 CSS px viewport stored 2880 px wide, so they stay sharp on retina screens and in close-up cards; a `region` is given in CSS pixels and `<Screenshot>` widens `sizes` by how much a cut-out enlarges the image, so the browser picks a wide enough source. `node site/scripts/import-screenshots.mjs <dir>` imports PNGs plus a `manifest.json` (run inside the site's node container). `dev/screenshots/site-retake/shots.py` retakes them from the dev dashboard; `analytics-*` and `security-*` come from `dev/screenshots/site-retake/features.py` after seeding the dev data with `dev/screenshots/analytics/seed.php` (weeks of traffic, CPU and memory for team 1) and `dev/screenshots/security/seed.php` plus `polish.php` (findings, investigations, firewall events); `security-firewall-settings` comes from `dev/screenshots/security/site.py`. Screenshots never come from a real instance. Everything goes through the built-in `next/image` optimizer, which resizes and re-encodes on request (WebP, default sizes, cached by the server); width and height keep the layout stable and the blur placeholder shows until the image loads. Device renders (`site/public/devices/<render>.webp`) are single 2× files too. Light/dark pairs switch with the theme by CSS only.

## 5. Dashboard links into the docs

Every "Learn more" in the dashboard points at the user docs through **one config value**: `launchroom.docs_url` (`LAUNCHROOM_DOCS_URL`, default `https://launchroom.vantezzen.io/docs`). PHP builds links with `App\Support\DocsUrl::to('servers/high-availability-ingress')`; the frontend gets the base as the `docsUrl` Inertia prop and builds links with `useDocsUrl()` (`features/settings/settings-card.tsx`). Pages are addressed by their docs path, so a renamed page needs a redirect or a dashboard change.

## 6. Development and deployment

- The make targets only wrap the npm scripts in a labelled `node` container (named volumes for `node_modules` and `.next`; CPU capped by `LR_TOOL_CPUS`): `make site-install`, `make site-dev` (`npm run dev` on http://127.0.0.1:`LR_DEV_SITE_PORT`, default 3200), `make site-build` (`npm run build`), `make site-preview` (`npm run build && npm run start` on the same port, in the background as `launchroom-dev-site-preview`), `make lint-site` (`npm run check`).
- Deploy like any Next.js app: root directory `site/`, build `npm run build`, start `npm run start`. On launchroom: add the repository and set the root directory to `site/`; launchroom's Next.js detection does the rest (Railpack plus the Next.js preset and deployment adapter, [04](04-build-and-deploy.md) §3, §12.1). Nothing in `site/` is launchroom-specific.
