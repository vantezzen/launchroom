# UX round: project canvas, service panel and service settings (CNV)

Area: canvas (nodes, groups, toolbar, minimap), service panel sheet, service settings sections, variables, domains, scaling, schedule, staged changes ("Review & apply"), unsaved-changes bar.

Screens looked at: `ux-round/before/{canvas,canvas-alt,capabilities}-light.png` (fresh) and `site-retake/raw/{service-panel,service-settings,settings-search,variables,domains,domains-edges,scaling,cron,config-managed,staged-changes,staged-changes-blocked}-light.png` (Oct 28). Code read: `features/service-panel/service-staged-bar.tsx`, `features/staged-changes/{staged-changes-sheet,effect-badge}.tsx`, `components/patterns/unsaved-bar.tsx`, `features/canvas/{service-canvas,service-node,canvas-toolbar}.tsx`, `features/service-settings/{variables-section,sections}.tsx`.

---

## 0. What Laravel Cloud does here (primary reference)

Laravel Cloud's environment page is the closest thing on Mobbin to our canvas + panel. Things it does that matter for us:

1. **One save decision, not two steps.** Editing a resource opens a side sheet over the canvas. The unsaved bar inside the sheet reads "Unsaved changes | Reset | Save ▾". The Save split button opens two choices: **"Save and deploy"** ("Your environment's latest commit will be deployed so your changes take effect immediately", with a warning row "Deploying will restart the environment") and **"Save only"** ("Changes will take effect on your next deployment").
   [sheet with bar](https://mobbin.com/screens/fa5663eb-84be-4db9-a9b3-bd97b720847a?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Save menu open](https://mobbin.com/screens/fc517244-3085-47f8-adce-0f9568025568?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [same bar on the Settings page](https://mobbin.com/screens/bce31f98-3283-4c47-971c-8a0dda21ca4b?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
2. **Nodes show facts as icon + key + value rows.** "Compute: Flex 1 vCPU", "Hibernate after: 5m", "Scheduler: ● Enabled", "Background processes: 1", "Custom domains: ○ Not connected". Each node is a small spec sheet you can read without opening it, and status values carry a dot plus a word. Nodes sit in labelled frames ("Network", "US East (Ohio)").
   [canvas with worker + database](https://mobbin.com/screens/742e6e1b-5d3f-4ef5-a8c9-9c4d13fcbc2f?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [pending states right after creation](https://mobbin.com/screens/7aea6b8a-66fc-4df5-a3be-6cf51275148b?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
3. **A small canvas toolbar where every Add entry has a description.** Bottom-centre toolbar with three dropdown buttons. Each menu item has a one-line description ("Database: Create or connect a database", "Worker cluster: Add an extra worker cluster to handle background processes").
   [add-resource menu](https://mobbin.com/screens/f54892bf-16e5-4a69-98e6-4f50ae970d1b?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [tooltip](https://mobbin.com/screens/35a70fa0-9f2c-4966-ae60-19ae7d2e5324?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
4. **Presets before custom sizes.** "New database cluster" offers Dev / Prod / Scale radio cards, each with "Flex (1 vCPU · 512 MiB RAM) · 5 GB storage · 1 days backups". "Custom configuration ›" is the way out.
   [presets](https://mobbin.com/screens/0e0c2cde-be0f-4e0e-9308-b50978f67c44?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
5. **Structured builders with a live preview of the raw result.** "New background process" is a form (connection, --queue, --sleep…) with a "Preview" box showing the exact `php artisan queue:work database --sleep=5`. There is no raw-string-first input.
   [background process builder](https://mobbin.com/screens/e6f074ba-b680-4f20-885a-dd415771661d?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
6. **Settings are rows inside grouped cards** with a header band ("General settings / General settings for this environment. Learn more"). Custom env vars are a code editor blurred behind a "Reveal secrets" button. We already follow the rows-in-cards pattern closely.
   [settings](https://mobbin.com/screens/6d043bb0-c5b7-4663-a684-1d57df497d90?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
7. **No minimap.** The canvas is a bounded box on the environment page with "Latest deployments" under it.

Railway, also indexed (unlike what the brief expected), is the closest match to our model of staged changes on a canvas plus a panel. It corroborates and extends points 1–3 (see §2).

---

## 1. Current state

- **Works:** the panel-over-canvas layout (`service-panel`), the settings sidebar with search and `/` hint (`service-settings`, `settings-search`), the visual scaling with server pictures and fit status (`scaling`), the domain DNS → HTTPS → Traffic pipeline (`domains`), the cron presets with a human summary and next run (`cron`), the config-managed banner (`config-managed`) and the broken-reference blocker (`staged-changes-blocked`). Each is on par with or ahead of the references.
- **Staged changes show up in three places at once with different verbs.** In `capabilities` the panel header has a "Review & apply" button, the panel floats a bar "1 change | web starts when applied, 2 more elsewhere | Discard | Apply all", and the canvas toolbar has "4 changes Review & apply" (`canvas-alt`). The panel bar's Discard drops only this service's changes, while Apply applies the whole environment (`service-staged-bar.tsx`). Nothing on the bar says so.
- **Saving takes two steps and two bars.** An edit shows "Unsaved changes | Reset | Save for next deploy" (`scaling`). After saving, a second bar replaces it: "N changes … Discard | Apply". Laravel Cloud does both in one bar with one choice.
- **The review sheet dumps raw JSON.** `staged-changes` shows `healthcheck` as two full JSON blobs with strike-through. The subtitle is a terse "api: restart, cache: new, web: restart". The cause is `displayValue()` in `effect-badge.tsx`, which only flattens strings and string arrays.
- **Floating bars cover content.** The bar sits over the `NEXT_PUBLIC_STRIPE_KEY` row (`variables`) and over the "Switch to another group" toggle (`capabilities`), with no scroll padding under it.
- **Canvas nodes say little and spend a line on a digest.** The node subtitle is `acme/shop-web@4b5e57f6eb2f`. Changed nodes only get a dashed "Changed" chip (`canvas-alt`), with no count and no hint of what changed. The minimap is always on at `lg` (`service-canvas.tsx` renders `<MiniMap>` whenever `nodes.length > 0`). With 4 services it takes a corner and adds nothing.

---

## 2. Patterns observed

### P1. One save control that offers "apply now" or "later"
- **Laravel Cloud**: Save ▾ → "Save and deploy" / "Save only" ([fc517244](https://mobbin.com/screens/fc517244-3085-47f8-adce-0f9568025568?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)).
- **Etsy**: "Discard changes | Preview | Save draft | **Publish with changes**" in one footer ([e5476550](https://mobbin.com/screens/e5476550-1c37-4475-8b07-b5d3e9c1e070?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)).
- **PlanetScale**: "Deploy changes ▾" split button with an "Auto-apply changes" checkbox ([flow](https://mobbin.com/flows/f7c7d4bb-61fb-4515-b00d-4ef6afe880d2?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)).
- **Why it works:** "save" and "make it live" are a single decision. Asking twice, in two bars that look the same, makes people wonder whether the first click did anything.

### P2. A single staged-changes bar for the environment, plus per-node change counts
- **Railway**: one bar at the top of the canvas, "Apply 2 changes | Details | Deploy ⇧↵ | ⋮". It stays put when the service panel opens. The node replaces its status line with "2 Changes" or "13 Variables and 9 Settings" and shows an "Edited"/"New" tag. A service being removed is dimmed red: "Service will be deleted". Changed variable rows and changed fields are tinted inside the panel. See [714a17b6](https://mobbin.com/screens/714a17b6-23d6-4ffe-b122-67e7da8c7d5e?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [a6c26d30](https://mobbin.com/screens/a6c26d30-857c-47e7-9707-f8632f32a43d?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [b1429e5b](https://mobbin.com/screens/b1429e5b-b8d7-4dab-b394-89c70c2b41fc?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [ec45bbfc](https://mobbin.com/screens/ec45bbfc-92e2-47c2-a500-1467ca63d86e?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp).
- **Retool**: "Undeployed changes" text right next to Deploy, in one place ([859dd7fd](https://mobbin.com/screens/859dd7fd-7f41-41e3-927f-9732f45b0e4a?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)).
- **Why it works:** the pending state has one home and one verb. The node tells you how much changed before you open anything.

### P3. Diffs list only the changed fields, with old and new values
- **Railway** "2 changes applied": per service "todo-api was updated · 2 Settings", then a Change | Old Value | New Value table ([131e5390](https://mobbin.com/screens/131e5390-18b7-4eb6-9715-bb5d9497c7fb?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)).
- **Vapi** "Review Your Changes": only the changed keys are highlighted, with a "Show Unchanged Values" checkbox ([5f8324b9](https://mobbin.com/screens/5f8324b9-1e1f-4118-b0fb-f575d9b70848?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)).
- **Vercel** firewall "Review Change": a sentence per change ("You enabled Bot Protection in log mode", "Just now"), plus Discard All Changes / Publish ([56371ade](https://mobbin.com/screens/56371ade-be8a-45d7-89a2-b1a3a7ca7db0?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)).
- **Why it works:** nobody reviews two 200-character JSON blobs. Field-level rows make the review step worth having.

### P4. Unsaved bars name what changed and never cover the form
- **Etsy**: "There is 1 unsaved change. You changed: Shop section." in a footer band that reserves its own space ([e5476550](https://mobbin.com/screens/e5476550-1c37-4475-8b07-b5d3e9c1e070?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)).
- **Klaviyo, Clerk, Base44, Laravel Cloud** use the same compact dark pill as ours ([Klaviyo](https://mobbin.com/screens/e06a4b15-a937-4f72-8096-9d4261b31972?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Clerk](https://mobbin.com/screens/5fcadf3b-ace8-4161-a761-bff11b623aa1?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)). The pill itself is settled; what to improve is the content and the padding under it.

### P5. Canvas nodes as small spec sheets; canvas chrome kept minimal
- **Laravel Cloud**: key/value rows per node ([742e6e1b](https://mobbin.com/screens/742e6e1b-5d3f-4ef5-a8c9-9c4d13fcbc2f?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)).
- **Railway**: name, domain and status line, with the attached volume as a footer row of the database card ([flow](https://mobbin.com/flows/d7a8d721-94f8-4abb-928c-db740f7b823d?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)). Neither has a minimap. Railway tucks "Hide connections / Auto layout / Reset canvas" into one overflow button.
- **Add menus with descriptions**: Laravel Cloud ([f54892bf](https://mobbin.com/screens/f54892bf-16e5-4a69-98e6-4f50ae970d1b?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)), OpenAI Agent Builder's grouped node palette ([ff4a8f45](https://mobbin.com/screens/ff4a8f45-754c-466b-aa21-dc765ac4b8dc?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)).

### P6. DNS records as a Type | Name | Value table with copy in each cell and a status per record
- [Vercel claim domain](https://mobbin.com/screens/50f72208-2525-4367-9875-bd455dcc71be?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Klaviyo](https://mobbin.com/screens/f6dcd542-ad3a-4101-abff-404742f1d19b?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [AutoSend](https://mobbin.com/screens/2c9c4317-dfc7-4957-a9ca-79169ad9bc97?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp) (adds a "● PENDING" status column), [Mintlify](https://mobbin.com/screens/09a05f71-07a3-4879-9df9-87a3f54d5c67?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Resend](https://mobbin.com/screens/ee71c976-8c06-4610-b736-afbb27829f87?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp).
- **Why it works:** it matches the columns of every registrar's DNS form, so people copy across cell by cell.

### P7. Size presets first, custom as the way out
- Laravel Cloud Dev/Prod/Scale ([0e0c2cde](https://mobbin.com/screens/0e0c2cde-be0f-4e0e-9308-b50978f67c44?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)), Supabase NANO…XL compute cards with memory and CPU ([fa90b107](https://mobbin.com/screens/fa90b107-be68-41a2-bd91-04816a512cfd?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)), Render instance types plus "custom instance type" ([2867dc00](https://mobbin.com/screens/2867dc00-2e65-4b22-96d5-f896a7a62afd?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)), Vercel build machines with "4 vCPUs / 8 GB Memory" chips ([29643b78](https://mobbin.com/screens/29643b78-8d6d-4fa6-ad27-dfa76f689733?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)).

### P8. Schedules built from fields first, with cron as a mode
- Retool "Interval | Cron" segmented control with "Run every [day] at [12:00 AM]" and the summary "At 12:00 AM, every day" ([859dd7fd](https://mobbin.com/screens/859dd7fd-7f41-41e3-927f-9732f45b0e4a?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)), AirOps weekday chips M T W Th F S Su ([7383e7de](https://mobbin.com/screens/7383e7de-aecc-46ff-bbe3-13f43640ee69?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)), Hex frequency + time + day ([b8b16b80](https://mobbin.com/screens/b8b16b80-37f2-4d6a-bb4a-cffec25a0f29?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)), and Laravel Cloud's form-plus-preview (P0.5).

### P9. Variables: a count, search and in-place add
- Railway: "3 Service Variables 🔍 | Shared Variable | Raw Editor | + New Variable". Adding is an inline key/value row at the top of the list with Add / Cancel ([d0dcb777 flow](https://mobbin.com/flows/37d10479-72cf-41d2-9cd1-88d43777e366?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [ec45bbfc](https://mobbin.com/screens/ec45bbfc-92e2-47c2-a500-1467ca63d86e?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)). The raw editor has ENV / JSON tabs and "Copy ENV" ([670d5a5b](https://mobbin.com/screens/670d5a5b-63dd-46d2-b75f-4aacb2a7f540?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)).
- Vercel: search, environment filter and sort above the list. The add sheet has "Add Another" and "Import / paste .env in Key input" ([eb532559](https://mobbin.com/screens/eb532559-1139-4a01-8cd9-a90912ed5563?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [fce0f81a](https://mobbin.com/screens/fce0f81a-790d-4757-95f2-4a6ae033bcde?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)).
- Supabase: "Add another" rows plus a search field, with default secrets listed separately ([8d3f5333](https://mobbin.com/screens/8d3f5333-785a-4908-87cd-d3c20fdcdd39?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)). We already have the "System variables" collapsible.

---

## 3. Recommendations (ranked by impact ÷ effort)

### CNV-1: Make the unsaved bar's Save a split button: "Save and apply" / "Save for later"  (M)
- **Problem:** editing a setting means "Save for next deploy" in one bar, then "Apply" in a second bar that replaces it. It feels like two saves, and the first label suggests nothing happens until a code push.
- **Change:** in `UnsavedBar` (`components/patterns/unsaved-bar.tsx`) the primary action becomes `Save ▾`. The default click is **Save and apply** (stage, then call `deployStaged`). The menu offers:
  - "Save and apply": "web restarts now" (taken from the `EFFECTS` consequence). Add "and 2 other staged changes apply too" when the environment has other staged items.
  - "Save for later": "Applies with the next deploy or when you apply".

  If the user can't deploy, the default flips to "Save for later" and the apply item is disabled with the existing reason ("Applying changes needs the developer role"). Every section's `label="Save for next deploy"` (`scaling-section.tsx:754`, `schedule-section.tsx:437`, `build-section.tsx:682`, `deploy-section.tsx:474`, `advanced-section.tsx:268`, `source-section.tsx:168`, `volumes-section.tsx:417`) becomes a prop on the shared bar instead of a hard-coded label. Broken references disable "Save and apply" with "Fix the broken references first".
- **Evidence:** [Laravel Cloud Save menu](https://mobbin.com/screens/fc517244-3085-47f8-adce-0f9568025568?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Etsy "Publish with changes"](https://mobbin.com/screens/e5476550-1c37-4475-8b07-b5d3e9c1e070?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [PlanetScale Deploy ▾](https://mobbin.com/flows/f7c7d4bb-61fb-4515-b00d-4ef6afe880d2?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
- **Doc update:** `docs/design/flows.md` §8 and §11 (save → apply), `docs/08-dashboard-ux.md` §16 (settings save bar).

### CNV-2: Show field-level diffs in the review sheet  (S–M)
- **Problem:** the review sheet prints whole JSON objects with strike-through (`staged-changes`, healthcheck). The header summary "api: restart, cache: new, web: restart" reads like a log line.
- **Change:**
  - In `features/staged-changes/effect-badge.tsx`, make `displayValue` handle objects: diff `before` and `after` by dotted key and return only the changed leaves. Render each as its own row in `ChangeRow` (`staged-changes-sheet.tsx` ~l.160): `healthcheck.path` `/` → `/api/health`, and `healthcheck.timeoutSeconds` `3` → `5`, with human labels where the settings index has one ("Health check path").
  - Collapse unchanged keys. Optionally add a "Show full value" disclosure for the raw JSON.
  - Rewrite the sheet subtitle as a sentence built from the groups: "api and web restart, cache is created".
- **Evidence:** [Railway Old/New table](https://mobbin.com/screens/131e5390-18b7-4eb6-9715-bb5d9497c7fb?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Vapi changed keys only](https://mobbin.com/screens/5f8324b9-1e1f-4118-b0fb-f575d9b70848?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Vercel sentence per change](https://mobbin.com/screens/56371ade-be8a-45d7-89a2-b1a3a7ca7db0?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
- **Doc update:** `docs/08-dashboard-ux.md` §8 (review sheet content).

### CNV-3: One staged-changes surface per view, with matching scope on Discard and Apply  (S)
- **Problem:** with the panel open there are up to three entry points: the header "Review & apply" button, the floating panel bar and the canvas-toolbar pill. Two of them use different verbs. The panel bar's Discard drops only this service's items, while Apply applies the whole environment (`service-staged-bar.tsx`), and the bar doesn't say so.
- **Change:**
  - In `service-panel/service-controls.tsx`, hide the header "Review & apply" while `ServiceStagedBar` is visible, so the bar is the single home.
  - In `service-staged-bar.tsx`, label the scopes: "Discard web's changes" and "Apply all 3" (or "Apply" when only this service is staged). Add a "Details" text button that calls `openStagedChanges()`, as Railway does.
  - Keep the canvas-toolbar pill as the only surface when no panel is open (`staged-changes-pill.tsx`).
- **Evidence:** [Railway single bar with Details + Deploy](https://mobbin.com/screens/714a17b6-23d6-4ffe-b122-67e7da8c7d5e?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Retool "Undeployed changes" next to Deploy](https://mobbin.com/screens/859dd7fd-7f41-41e3-927f-9732f45b0e4a?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Vercel Discard All / Publish](https://mobbin.com/screens/56371ade-be8a-45d7-89a2-b1a3a7ca7db0?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
- **Doc update:** `docs/design/flows.md` §8 (bar wording).

### CNV-4: Keep floating bars from covering the last rows  (S)
- **Problem:** the panel's floating bar sits on top of the last visible rows: a variable row in `variables` and a capability toggle in `capabilities`.
- **Change:** when a bar is mounted with `placement="inline"`, give the sheet's scroll container `padding-bottom: calc(bar height + 1.25rem)`. Set a `data-has-floating-bar` attribute on the panel root and add a CSS rule in `service-panel.tsx` / `settings-tab.tsx`. The same applies to page placement in `FloatingBarOutlet` (`unsaved-bar.tsx`).
- **Evidence:** [Etsy footer reserves its band](https://mobbin.com/screens/e5476550-1c37-4475-8b07-b5d3e9c1e070?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Laravel Cloud bar over empty space](https://mobbin.com/screens/fa5663eb-84be-4db9-a9b3-bd97b720847a?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
- **Doc update:** `docs/design-system.md` §6 (floating bar: content must stay reachable).

### CNV-5: Nodes say how much changed, not just "Changed"  (M)
- **Problem:** a staged node gets a dashed outline plus a "Changed" chip (`canvas-alt`), and you can't tell a renamed variable from a new source image without opening it. Removals aren't visible on the canvas.
- **Change:** extend the node's staged data with a count and a summary (server side: the canvas node Data that feeds `data.staged`, from the same grouping as `StagedChanges.groups`). In `service-node.tsx`, replace the status line of a staged node with "2 changes" or "1 variable, 2 settings", keep the chip, and render a node that will be removed dimmed with "Removed when applied" and a strike-through name. Clicking the count opens the review sheet filtered to that service.
- **Evidence:** [Railway "2 Changes" on node](https://mobbin.com/screens/714a17b6-23d6-4ffe-b122-67e7da8c7d5e?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), ["13 Variables and 9 Settings"](https://mobbin.com/screens/a6c26d30-857c-47e7-9707-f8632f32a43d?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), ["Service will be deleted"](https://mobbin.com/screens/b1429e5b-b8d7-4dab-b394-89c70c2b41fc?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
- **Doc update:** `docs/08-dashboard-ux.md` §13 (node anatomy).

### CNV-6: Node fact rows instead of the image digest  (M)
- **Problem:** the second line of every node is `acme/shop-web@4b5e57f6eb2f`, which is long, truncates and says little. Useful facts (replicas, schedule, volume) appear only for some kinds, or not at all.
- **Change:** in `service-node.tsx`:
  - Make the subtitle the source in human form: `acme/shop-web` plus the branch for git, or `nginx:1.27` for images. Drop the full digest; the short sha belongs to the deployment.
  - Under the status, add up to three icon + value rows chosen by kind: the domain (already there), "×3 replicas" when more than 1, "Weekdays at 03:30" for cron, the volume path for databases (already there).

  Status keeps dot + word. No "·" separators: each fact is its own row.
- **Evidence:** [Laravel Cloud node rows](https://mobbin.com/screens/742e6e1b-5d3f-4ef5-a8c9-9c4d13fcbc2f?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Railway node with volume footer](https://mobbin.com/flows/d7a8d721-94f8-4abb-928c-db740f7b823d?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
- **Doc update:** `docs/08-dashboard-ux.md` §13.

### CNV-7: DNS records as a horizontal Type | Name | Value row  (S)
- **Problem:** in `domains`, the A record is a box with "A" on the left and Name and Value stacked vertically, with copy buttons far to the right. It doesn't match the columns people fill in at their registrar.
- **Change:** in `features/domains/dns-check-result.tsx` / `platform-dns-records.tsx` (and the record block in `service-settings/domains-section.tsx`), render records as a table: Type | Name | Value, with a copy icon inside each Name and Value cell. Add a per-record status cell (dot + "Found" / "Not found yet") when the check knows it. Below 640px, collapse each record to a stacked card.
- **Evidence:** [Vercel](https://mobbin.com/screens/50f72208-2525-4367-9875-bd455dcc71be?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [AutoSend with status](https://mobbin.com/screens/2c9c4317-dfc7-4957-a9ca-79169ad9bc97?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Klaviyo](https://mobbin.com/screens/f6dcd542-ad3a-4101-abff-404742f1d19b?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
- **Doc update:** none (layout only). Optionally a note in `docs/design/flows.md` §9.

### CNV-8: Hide the minimap until the canvas overflows  (S)
- **Problem:** the minimap (168×108, bottom right) always renders at `lg`. With the usual 2–6 services it shows the same thing as the canvas and competes with the toolbar.
- **Change:** in `service-canvas.tsx` (~l.987), render `<MiniMap>` only when the node bounds don't fit the viewport at ≥ 60% zoom (`getNodesBounds` vs. the viewport), or when there are more than 8 nodes. Add a "Show map" toggle under the toolbar's overflow for people who want it, stored in `localStorage`.
- **Evidence:** [Laravel Cloud canvas](https://mobbin.com/screens/742e6e1b-5d3f-4ef5-a8c9-9c4d13fcbc2f?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp) and [Railway canvas](https://mobbin.com/flows/d7a8d721-94f8-4abb-928c-db740f7b823d?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), neither of which has a minimap.
- **Doc update:** `docs/08-dashboard-ux.md` §13.

### CNV-9: Unsaved bar names the change  (S–M)
- **Problem:** "Unsaved changes" doesn't say what. With sections that scroll (Scaling, Deploy), the edited field is often off screen.
- **Change:** in `features/service-settings/dirty.ts`, track the labels of dirty fields per section. Then `UnsavedBar` shows "Replicas changed" or "3 settings changed", with a tooltip listing them. Clicking the text scrolls to the first dirty field, reusing the settings-search highlight from `settings-index.ts`.
- **Evidence:** [Etsy "You changed: Shop section"](https://mobbin.com/screens/e5476550-1c37-4475-8b07-b5d3e9c1e070?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Laravel Cloud bar](https://mobbin.com/screens/bce31f98-3283-4c47-971c-8a0dda21ca4b?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
- **Doc update:** `docs/08-dashboard-ux.md` §16.

### CNV-10: Variables: a count, search and no redundant Environments column  (S)
- **Problem:** the Variables table has no search. In an environment's panel, every row repeats a "production" pill in an Environments column (`variables`), which is noise in the common case of one environment.
- **Change:** in `variables-section.tsx`:
  - Put a count in the section header ("12 variables").
  - Show a search input (filters key and value preview) when there are more than 8 rows.
  - Hide the Environments column when every row targets only the current environment. Show the pill only on rows that also target others ("also staging").
- **Evidence:** [Railway count + search](https://mobbin.com/screens/ec45bbfc-92e2-47c2-a500-1467ca63d86e?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Vercel search + environment filter](https://mobbin.com/screens/eb532559-1139-4a01-8cd9-a90912ed5563?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Supabase search](https://mobbin.com/screens/8d3f5333-785a-4908-87cd-d3c20fdcdd39?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
- **Doc update:** `docs/08-dashboard-ux.md` §8 (Variables bullet).

### CNV-11: Size presets above the scaling sliders  (M)
- **Problem:** "Size of each replica" is two raw sliders (0.1–12 cores, 128 MB–8 GB). People pick arbitrary numbers, and the server-fit check only appears after the fact.
- **Change:** in `scaling-section.tsx`, add a row of preset cards above the sliders: Small (0.5 core, 512 MB), Medium (1 core, 1 GB), Large (2 cores, 4 GB), Custom. Each card shows its numbers. A preset disables itself with "Bigger than any server" when it doesn't fit the largest eligible server. Picking Custom reveals the existing sliders. There's no pricing, since we're self-hosted. The cron section's preset chips are the in-house precedent.
- **Evidence:** [Laravel Cloud Dev/Prod/Scale](https://mobbin.com/screens/0e0c2cde-be0f-4e0e-9308-b50978f67c44?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Supabase compute sizes](https://mobbin.com/screens/fa90b107-be68-41a2-bd91-04816a512cfd?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Render instance types](https://mobbin.com/screens/2867dc00-2e65-4b22-96d5-f896a7a62afd?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
- **Doc update:** `docs/08-dashboard-ux.md` §16 (visual scaling), `docs/design/flows.md` §11.

### CNV-12: Schedule builder with cron as a mode  (M)
- **Problem:** `cron` offers good presets, but anything else (say "Tue and Thu at 06:15") means editing `30 3 * * 1-5` directly.
- **Change:** in `schedule-section.tsx`, add a segmented control **Simple | Cron**. Simple is a builder: "Every [day ▾] at [03:30]" plus weekday chips (M T W T F S S) for weekly schedules. It writes the expression through `cron.ts` and shows it read-only as a preview, the same way Laravel Cloud previews the command. Cron mode is today's input with its field legend. Open in Cron mode when the stored expression can't be represented by the builder.
- **Evidence:** [Retool Interval | Cron](https://mobbin.com/screens/859dd7fd-7f41-41e3-927f-9732f45b0e4a?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [AirOps weekday chips](https://mobbin.com/screens/7383e7de-aecc-46ff-bbe3-13f43640ee69?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Laravel Cloud form + preview](https://mobbin.com/screens/e6f074ba-b680-4f20-885a-dd415771661d?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
- **Doc update:** `docs/08-dashboard-ux.md` §16 (schedule page).

### CNV-13: Add menu entries carry a one-line description  (S)
- **Problem:** the toolbar "Add" opens kinds of services. New users choosing between "worker", "cron", "image" and "database" don't know which one fits.
- **Change:** in `canvas-toolbar.tsx` (Add menu) and `empty-canvas.tsx`, give each item a muted second line: "Database: Postgres, MySQL, Redis and more", "Cron job: Runs a command on a schedule", "Worker: A process with no public address". Use the same strings in ⌘K.
- **Evidence:** [Laravel Cloud add menu](https://mobbin.com/screens/f54892bf-16e5-4a69-98e6-4f50ae970d1b?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Laravel Cloud tooltip](https://mobbin.com/screens/35a70fa0-9f2c-4966-ae60-19ae7d2e5324?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [OpenAI Agent Builder palette](https://mobbin.com/screens/ff4a8f45-754c-466b-aa21-dc765ac4b8dc?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
- **Doc update:** none.

### CNV-14: Inline "add variable" row  (M, lower priority)
- **Problem:** "Add variable" opens a dialog for what is usually one key and one value.
- **Change:** in `variables-section.tsx`, "Add variable" inserts an editable key/value row at the top of the table, with Add / Cancel and a "More options" link that opens the existing `VariableDialog` (environments, secret, build-time). Pasting `KEY=value` into the key field splits it, and pasting several lines routes to the existing import preview (`env-paste.ts`).
- **Evidence:** [Railway inline add](https://mobbin.com/flows/37d10479-72cf-41d2-9cd1-88d43777e366?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Vercel "Add Another" + paste .env in Key](https://mobbin.com/screens/fce0f81a-790d-4757-95f2-4a6ae033bcde?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Modal "Add another / Import .env"](https://mobbin.com/screens/d2c11967-b74a-4458-9b6a-c9d26b8bd72c?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
- **Doc update:** `docs/design/flows.md` §8.

---

### Considered and not recommended
- **Copying Railway's top-of-canvas bar position.** Our bottom-centre toolbar pill already carries the count. The fix is consolidation (CNV-3), not moving it.
- **A Laravel Cloud-style bounded canvas on an overview page.** Our full-bleed canvas is the project home by design (docs/08 §13). Their node content (CNV-6) transfers; their page layout doesn't.
- **A separate Settings layout with scrollspy (Railway "Filter Settings" + right-hand index).** Our sidebar pages plus search already work (`settings-search`).
