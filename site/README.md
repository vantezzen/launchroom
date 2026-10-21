# launchroom site

The public website and user docs at **https://launchroom.vantezzen.io**: landing page, feature pages and the docs.
A plain Next.js 16 app (App Router) with fumadocs 16 and Tailwind 4: standard `dev`, `build`, `start` and `lint`
scripts, nothing host-specific. Design and decisions: [`docs/21-website.md`](../docs/21-website.md).

## Develop

Everything runs in Docker through `make` (no Node on the host); the targets only wrap the npm scripts:

| Command             | What it does                                                              |
| ------------------- | ------------------------------------------------------------------------- |
| `make site-install` | `npm ci` (named volume `launchroom-dev-site-node-modules`)                |
| `make site-dev`     | `npm run dev` on http://127.0.0.1:3200 (`LR_DEV_SITE_PORT` in `dev/.env`) |
| `make site-build`   | `npm run build`                                                           |
| `make site-preview` | `npm run build && npm run start` on the same port, in the background      |
| `make lint-site`    | `npm run check`: typecheck, ESLint, Prettier check                        |

## Where things are

| Path                                         | Contents                                                                     |
| -------------------------------------------- | ---------------------------------------------------------------------------- |
| `app/(home)`                                 | Landing page and `/features/*`                                               |
| `app/docs`                                   | Docs layout and page renderer (fumadocs)                                     |
| `content/docs`                               | The docs, MDX. Sidebar order in each folder's `meta.json`                    |
| `components/marketing`                       | Header, footer, sections and the static recreations of dashboard components  |
| `components/screenshot.tsx`                  | `<Screenshot id="…" />` for MDX and pages; light/dark switch with the theme  |
| `lib/shared.ts`                              | Site constants: URLs, install command, the demo video slot                   |
| `lib/screenshots.json`, `public/screenshots` | Screenshot manifest and one WebP per screenshot and theme                    |
| `scripts/sync-public.mjs`                    | `prebuild`: refreshes the committed `/install` and `/schema/*` from the repo |

### Writing docs

Pages are MDX with `title` and `description` frontmatter (quote values that contain a colon). Available components
without imports: `Screenshot`, `Callout`, `Cards`/`Card`, `Steps`/`Step`, `Tabs`/`Tab`, `Accordions`/`Accordion`.
Write for people using launchroom; contributor material belongs in `docs/`. Voice: lowercase `launchroom`, no
competitor names, talk about servers (not "agents").

### Screenshots

Capture PNGs (light and dark) named `<id>-light.png` and `<id>-dark.png` into a folder with a `manifest.json`
(`[{ "id": "…", "alt": "…", "caption": "…" }]`), then import them inside the site container:

```sh
docker run --rm --label launchroom.dev=true \
  --mount type=volume,src=launchroom-dev-site-node-modules,dst=/repo/site/node_modules \
  -v "$PWD/site:/repo/site" -v "/path/to/pngs:/raw:ro" -w /repo/site node:24 \
  node scripts/import-screenshots.mjs /raw
```

This stores each image once, full size, as lossless WebP (`public/screenshots/<id>-<theme>.webp`) and records alt
text, caption, size and a blur placeholder in `lib/screenshots.json`. `next/image` resizes on request.

### Demo video

The landing page has a slot for a demo video that renders nothing until a video is set. Either set `demoVideo` in
`lib/shared.ts` or build with `NEXT_PUBLIC_LR_DEMO_VIDEO_URL=https://…/demo.mp4` (or a file under `public/`).

## Deploy

Deploy it like any Next.js app:

- **Root directory** `site/`
- **Build** `npm run build` (its `prebuild` step refreshes `public/install` and `public/schema` when the whole
  repository is checked out, and otherwise keeps the committed copies)
- **Start** `npm run start` (port 3000, or `PORT`)

On launchroom: add the repository and set the root directory to `site/`.

`/install` is the installer for `curl -fsSL https://launchroom.vantezzen.io/install | sh`; when the installer or a
JSON Schema changes in the repo, run `make site-build` and commit the refreshed copies (CI checks this).
