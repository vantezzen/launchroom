# Servers area: UX refinement research (SRV)

Screens reviewed: `ux-round/before/add-server-light.png`, `ux-round/before/add-server-roles-light.png` (fresh); `site-retake/raw/{servers,server-topology,server-detail,server-network,private-network,mtls}-light.png` (Oct 28). Code: `pages/servers/{index,show,create,enrollment,replace,move}.tsx`, `features/servers/*`, `features/moves/*`, `pages/settings/network.tsx`. Doc: `docs/08-dashboard-ux.md` §14, §20; `docs/design/flows.md` §10, §14.

## 0. Laravel Cloud first (primary reference)

Laravel Cloud has no "servers" (it's serverless-ish), but its **environment canvas**, **resource cards**, **create-resource modal**, **credentials block**, **metrics** and **archive confirm** map one-to-one onto our servers area. What it does:

1. **Canvas grouped by place.** The environment page is a dotted canvas where nodes sit inside labelled groups: "Network" (Edge network, Domains) and a region group "US East (Ohio)" holding "App cluster" / "Worker cluster" / "Database". Each node is a small card: icon + name header, then 2–4 key/value rows, each row with an icon, a label and a right-aligned value or a status word with a dot ("DDoS protection · Active", "Custom domains · Not connected", "Compute · Flex 1 vCPU", "Hibernate after · 5m"). A dashed "Add resource" node sits where the next thing would go. [env canvas](https://mobbin.com/screens/63204509-6daa-4fb9-ac50-c4f4bb571442), [canvas after adding a database](https://mobbin.com/flows/5b3d9862-e6ec-4817-aff8-c9f93a4b5916)
2. **Resource card = name + type, then one icon meta row**: pin "US East (Ohio)", chip "1 vCPU · 512 MiB RAM", disk "5 GB storage". No table, no unlabeled numbers. [Databases list](https://mobbin.com/screens/f004f56a-18d9-489d-bce0-af02de6cf05d), [Resources flow](https://mobbin.com/flows/66ca44b3-2a4f-47e8-94d2-fa32b249482b)
3. **Create modal with size as radio cards**: Name, Type, Region (pin icon in select), then "Configuration" radio cards Dev / Prod / Scale, each with one line of real specs ("Flex (1 vCPU · 2 GiB RAM) · 20 GB storage · 7 days backups"), "Custom configuration ›" link, and a consequence footer under the primary button. [Creating a database](https://mobbin.com/flows/5b3d9862-e6ec-4817-aff8-c9f93a4b5916)
4. **Secrets masked by default** with an eye toggle and a copy button in the block's corner; a plain-language amber callout says what the setting means for you ("Public endpoint is disabled, so these credentials will only work for Cloud environments in the same region. Enable public endpoint…"). [Copying credentials](https://mobbin.com/flows/d0fb6efe-b327-4f2c-97cd-f69fc5de1139)
5. **Metrics**: one chart per card, headline value top-right with its aggregation word ("0.63% avg.", "257.5 MiB current"), range pill 24 hours / 7 days / 30 days. [View metrics](https://mobbin.com/flows/2dbd2a60-e676-42ab-a2a8-8eabba8ad43a), [app metrics](https://mobbin.com/screens/b1cd2fc4-7079-4628-a8fd-8afe228908ce)
6. **Status with duration in the header**: a pill next to the breadcrumb, "Hibernating 15h", "Stopped 20m". [Application detail](https://mobbin.com/flows/208f0f71-fb17-49ed-9ee0-bf49640641e2), [Running a command](https://mobbin.com/flows/0a8cfbfc-0a27-432e-a01f-b633487729cc)
7. **Destructive confirm shows impact**: the archive dialog says what happens in bold, lists the affected object and what's attached ("main · 1 environment"), one red consequence line ("Archiving will detach the associated environment."), a cost note, then type-the-name. [Archiving a database](https://mobbin.com/flows/690be9ec-aa46-4d38-8566-ee813ffcd176)

## 1. Current state

- **Works:** device-art server cards that say what's at stake when a server is gone ("No report for 2 days. 6 replicas were placed here." + What to check / Replace server) (`servers`). The add-server stepper with collapsed done steps and the live "Waiting for your server…" device render (`add-server`, `add-server-roles`) already matches Tailscale's add-device page. The mTLS Off/Check/Require cards and readiness rows (`mtls`) are clean settings-as-rows.
- **Raw internals leak into primary UI.** Servers page banner: "The private network has a problem. Headscale list nodes failed: Headscale is not reachable" and lower-case "control plane is serving certificates from a local copy" (`servers`). Server Network tab: "shared certificate storage unreachable: certificate storage 100.64.0.1:6379: dial tcp 100.64.0.1:6379: i/o timeout", "waiting for a mesh key from the dashboard" (`server-network`). Settings → Network repeats both (`private-network`).
- **Docker/daemon words in the Capabilities section**, and the same warning three times: callout "launchroom holds the full Docker socket…", a "Docker" row with the same text, then "Docker access: launchroomd holds the full Docker socket; re-run the installer to add dockerd-guard" (`server-network`). Breaks the "never daemon/Docker words" rule.
- **Numbers without labels.** Card footer "12 cores | 12 GB | 348 GB" (what is 348 GB?); usage tiles mix units ("7 %", "4.0 GB of 12 GB", "51 % of 356 GB") (`servers`, `server-detail`).
- **Data-dump table on the server page.** "What runs here" has a CPU column that is "–" on every row and "512 MB limit" on every row (`server-detail`). The Servers page stacks four overview panels (System load, map + places, How it fits together, At a glance) for 3 servers; map labels overlap (Berlin/Helsinki) and topology nodes truncate to "serv…" (`servers`, `server-topology`).
- **Install command shows the join token and control-plane key in clear** in a large black block; the Ubuntu/Docker/Other Linux/macOS switcher only changes the hint line, the command stays identical (`add-server`, `features/servers/add-server.tsx` `InstallCommand`).

## 2. Patterns observed

**P1. Infrastructure drawn as groups of small key/value cards, grouped by place.** Laravel Cloud groups nodes by "Network" and region ([canvas](https://mobbin.com/screens/63204509-6daa-4fb9-ac50-c4f4bb571442)); Supabase's project home puts one node card on a dotted canvas with region flag and "CPU 2% · Disk 13% · RAM 56%" inline ([Supabase project](https://mobbin.com/flows/51295d05-6586-4508-83ee-9feee90fdf11)); PlanetScale's dashboard draws VTGates → Primary → Replicas, each node with region and "CPU 2% Mem 16%" ([PlanetScale cluster](https://mobbin.com/flows/4090b939-6d92-45dd-9af3-0eb0e5104fe5)). Why it works: the picture carries the numbers, so no separate "At a glance" panel is needed, and names never truncate because nodes are wider and grouped.

**P2. Spec line = icon + value + unit word, plus location.** Laravel Cloud ([databases](https://mobbin.com/screens/f004f56a-18d9-489d-bce0-af02de6cf05d)), Supabase compute size cards "2 GB memory · 2-core ARM CPU" ([Supabase compute](https://mobbin.com/screens/fa90b107-be68-41a2-bd91-04816a512cfd)), PlanetScale size chips "1/8 vCPU · 1 GB memory · 10 GB storage" ([cluster size](https://mobbin.com/flows/8d72a84d-5026-4d09-befc-4287dec369da)).

**P3. Install-and-wait on one screen, secret masked, status inline.** Tailscale: OS tiles left, "Waiting for your first device" panel right ([Tailscale add first device](https://mobbin.com/screens/6cf86c73-db70-47eb-b101-b628b11943fc)). Cloudflare Tunnel: OS + architecture buttons, a security notice, the command with the token masked as `eyJhIjoi••••••dy` and a copy button, and a "Connection Status: Waiting for your Tunnel to connect…" section right below ([Creating a tunnel](https://mobbin.com/flows/6975b64d-4b04-4522-86ac-7977e0fc14eb)). Laravel Cloud masks credentials with eye + copy ([credentials](https://mobbin.com/flows/d0fb6efe-b327-4f2c-97cd-f69fc5de1139)). Why: a screenshot or screen share of the page doesn't leak a working token, and copy still gets the real thing.

**P4. Success that proves it works.** Tailscale's onboarding ends on "Done! Your devices can now connect from anywhere." with a mini diagram of the two devices and their addresses, a ping command and "Success, it works!" ([Tailscale onboarding](https://mobbin.com/flows/8599ed56-357b-4026-bf3a-983a9d1070c4)). Neon's create page shows a globe with the chosen region and what you get ([Neon create](https://mobbin.com/screens/810a6887-11ae-4ee9-9d6c-b88569bb6b0f)).

**P5. Plain status words, details behind a click.** Laravel Cloud rows "Active / Enabled / Not connected"; Cloudflare tunnel list "Inactive" ([tunnels](https://mobbin.com/screens/edb50e14-306e-4009-8974-b7542faf4acb)); Tailscale machines "Connected" / last-seen time and addresses in a copy popover ([Tailscale machines](https://mobbin.com/screens/df8e39bc-0025-4b45-916b-a620ca633c91), [address popover](https://mobbin.com/screens/8e77de97-1ccf-432c-a2f7-55e4e7dbf376)). Railway private networking shows the internal hostname with a copy and "IPv4 & IPv6" tag ([Railway service settings](https://mobbin.com/flows/a543148f-5133-49b7-98d0-14c574424b10)). None print a stack trace inline.

**P6. Metrics: headline with aggregation word, one chart per card.** Laravel Cloud ("0.63% avg.", "257.5 MiB current"); Render puts an event timeline next to the charts ("Database unavailable / available") ([Render metrics](https://mobbin.com/screens/eaa7bbd9-5605-4cbe-b6a6-59697d900c6b)); Neon's monitoring cards ([Neon monitoring](https://mobbin.com/screens/c5ee5c89-e7ad-43fc-828a-46315320e455)).

**P7. Destructive actions show what's attached.** Laravel Cloud archive (affected list + red consequence + typed name) ([archive](https://mobbin.com/flows/690be9ec-aa46-4d38-8566-ee813ffcd176)); Railway "Delete Service" sentence names exactly what goes ([Railway settings](https://mobbin.com/flows/a543148f-5133-49b7-98d0-14c574424b10)); Neon "Delete project" names the project in bold ([Neon settings](https://mobbin.com/flows/4f3a0f01-3fb5-4fee-a879-e3052e36b814)).

**P8. Moving data drawn as source → target.** PlanetScale's running workflow: source card with per-table "Initial rows copied", an arrow "Replicating data to target", target card, a "Serving primary and replica traffic" tag on whichever side serves, "Replication lag: 0s", then "Verify data" before switching ([PlanetScale workflow](https://mobbin.com/screens/883adb7a-91a6-4ef8-9ed3-7fb234acbf20)). Neon import uses numbered collapsible steps with "Running checks…" ([Neon import](https://mobbin.com/screens/a6cb4dd8-fad2-4128-aba2-c502157b88dc)).

**P9. Device grid with real product images** (our identity already): Apple "Your Devices" ([Apple](https://mobbin.com/screens/88bebc9b-ff6b-4994-bfd2-4327d50450fc)). Confirms our cards; nothing to copy beyond keeping the image area calm and equal-height.

## 3. Recommendations (ranked by impact / effort)

### SRV-1 Turn raw errors into product sentences, keep the raw text behind "Details" (S–M)
- **Problem:** "Headscale list nodes failed: Headscale is not reachable", "dial tcp 100.64.0.1:6379: i/o timeout", "waiting for a mesh key from the dashboard" appear as primary copy (`servers`, `server-network`, `private-network`).
- **Change:** One mapping from known error kinds to a sentence + consequence + action, like Laravel Cloud's "Public endpoint is disabled, so these credentials will only work…":
  - mesh coordinator down → "Servers can't find each other right now. Apps keep running; new servers can't join until this is fixed." Action: "Open network".
  - shared certificate storage unreachable → "{server} is using its own copy of your certificates. Sites stay up; new certificates wait until it can reach {control plane} again."
  - waiting for mesh key → status word "Joining" with "Gets its network key with the next report."
  Raw error goes into a collapsed "Details" disclosure (mono, copyable) for admins. Capitalise server names in sentences consistently (the banner starts with lower-case "control plane").
- **Files:** `pages/servers/index.tsx` (attention list, `mesh.error` / `degradedStorage` items), `features/servers/mesh-status.tsx`, `features/servers/server-connection.tsx` (public-traffic note under the facts row), `pages/settings/network.tsx` (error row, Public traffic rows). Backend: give `MeshOverviewData`/edge data an error *kind* enum next to the message (`app/Services/Mesh/Exceptions/HeadscaleUnavailable.php` produces the string today) so the UI maps kinds, not strings.
- **Evidence:** [Laravel Cloud credentials callout](https://mobbin.com/flows/d0fb6efe-b327-4f2c-97cd-f69fc5de1139), [Laravel Cloud canvas status words](https://mobbin.com/screens/63204509-6daa-4fb9-ac50-c4f4bb571442), [Cloudflare tunnels](https://mobbin.com/screens/edb50e14-306e-4009-8974-b7542faf4acb)
- **Doc:** `docs/08-dashboard-ux.md` §14 (add "errors are shown as kind → sentence; raw text under Details"); `docs/design-system.md` voice section if a rule for error copy isn't there yet.

### SRV-2 Capabilities: no Docker words, one warning not three (S)
- **Problem:** Callout + "Docker" row + "Docker access" row all say "launchroom(d) holds the full Docker socket… dockerd-guard" (`server-network`).
- **Change:** Rename the section "What this server can do". Drop the separate engine row from the list (move "Runs on OrbStack on macOS" style engine info to the Connection facts). Show the callout only, with one product sentence: "This server gives launchroom more access than it needs. Run the install command again to limit it." + "Show command" button. Rows keep icon + word (Full / Limited) without repeating the reason when the callout already says it. Use only `CapabilityReason` text (product copy), never the daemon's reported reason string.
- **Files:** `features/servers/server-runtime.tsx`, `app/Enums/Servers/CapabilityReason.php` (copy).
- **Evidence:** [Laravel Cloud canvas rows](https://mobbin.com/screens/63204509-6daa-4fb9-ac50-c4f4bb571442), [Tailscale machine detail](https://mobbin.com/screens/ebdac974-f9d8-4909-93f1-52a8f9f2fed8)
- **Doc:** `docs/08-dashboard-ux.md` §14 *Network* bullet (Capabilities wording), `docs/11-installation-and-dev.md` §3.2 if it names the rows.

### SRV-3 Label the spec line and add the place, Laravel Cloud style (S)
- **Problem:** "12 cores | 12 GB | 348 GB" on cards and as bare chips in the hero (`servers`, `server-detail`).
- **Change:** `specParts` returns labelled parts: "12 cores", "12 GB memory", "348 GB disk"; render with small icons (cpu, memory-stick, hard-drive) and the place first with a pin ("Helsinki"). Remove the place chip from the card's image corner then, or keep it there and drop it from the line, but not both. In the hero, show the same line instead of four separate pills.
- **Files:** `features/servers/format.ts` (`specParts`, `placeLabel`), `features/servers/fleet-card.tsx`, `features/servers/server-hero.tsx`, `components/patterns/meta-line.tsx` (icon support if missing).
- **Evidence:** [Laravel Cloud database card](https://mobbin.com/screens/f004f56a-18d9-489d-bce0-af02de6cf05d), [Supabase compute sizes](https://mobbin.com/screens/fa90b107-be68-41a2-bd91-04816a512cfd), [PlanetScale cluster size](https://mobbin.com/flows/8d72a84d-5026-4d09-befc-4287dec369da)
- **Doc:** none (presentation).

### SRV-4 Mask the join token in the install command (S)
- **Problem:** The command shows `LR_JOIN_TOKEN=lr_jt_…` in full in a big block; a screenshot or screen share leaks a working token (`add-server`).
- **Change:** Display `LR_JOIN_TOKEN=lr_jt_••••••••` (keep the last 4) and the key shortened; an eye button reveals; **Copy always copies the full command**. Keep "The link works once and expires at 12:34 PM" next to it. Since the command is the same on every platform, turn the switcher into what it really changes: either drop the tabs and show the per-platform hint as a short list ("On a Mac, start OrbStack or Docker Desktop first"), or keep tabs only where the command differs (macOS).
- **Files:** `features/servers/add-server.tsx` (`InstallCommand`), reuse the reveal pattern from variables if one exists in `components/`.
- **Evidence:** [Cloudflare create tunnel (masked token + notice)](https://mobbin.com/flows/6975b64d-4b04-4522-86ac-7977e0fc14eb), [Laravel Cloud credentials eye/copy](https://mobbin.com/flows/d0fb6efe-b327-4f2c-97cd-f69fc5de1139)
- **Doc:** `docs/design/flows.md` §10 step 3 (mention masked token); `docs/09-security.md` if it covers join-token display.

### SRV-5 "What runs here": one row per project with real usage, no empty columns (S–M)
- **Problem:** Table with a CPU column of dashes and "512 MB limit" on every row; services from the same project repeated (`server-detail`).
- **Change:** Group by project: project colour dot + name, environment, "3 services", memory used as a thin bar against the server ("1.2 GB of 12 GB"), CPU only when sampled. Limits move to a tooltip/footnote (doc already says limits are caps, not use). Rows link to the project. Keep the top stacked bar (it's good) and make it the legend.
- **Files:** `features/servers/server-usage.tsx`, the `placement` prop in `app/Queries/ServerPages.php` if grouping needs server-side data.
- **Evidence:** [Laravel Cloud "Used in ASMobbin · staging"](https://mobbin.com/flows/5b3d9862-e6ec-4817-aff8-c9f93a4b5916), [Railway cost by service rows](https://mobbin.com/flows/9c1b89cb-277a-473a-9813-62c7259f9598), [Supabase node card with inline CPU/RAM](https://mobbin.com/flows/51295d05-6586-4508-83ee-9feee90fdf11)
- **Doc:** `docs/08-dashboard-ux.md` §14 *Overview* bullet.

### SRV-6 Topology grouped by place, nodes with key/value rows (M)
- **Problem:** Nodes truncate ("serv…"), roles are icon-only and explained in a separate legend; "At a glance" and the map repeat what the topology could show (`server-topology`, `servers`).
- **Change:** Draw each place as a labelled group box ("Berlin", "Helsinki") like Laravel Cloud's region groups; inside, node cards wide enough for the full name, the device render + LED, then 2–3 rows: roles as words ("Serves traffic", "Runs apps", "Builds"), status word when not online, CPU/memory now. Keep Internet on the left and line styles (public / direct / relayed / idle). With a single server, show one group and the existing "One server does everything" hint. Phone: stack groups vertically.
- **Files:** `features/servers/fleet-topology.tsx`, `features/servers/fleet-map.ts` (place grouping already computed for the map), `pages/servers/index.tsx`.
- **Evidence:** [Laravel Cloud canvas](https://mobbin.com/screens/63204509-6daa-4fb9-ac50-c4f4bb571442), [PlanetScale topology](https://mobbin.com/flows/4090b939-6d92-45dd-9af3-0eb0e5104fe5), [Supabase node on canvas](https://mobbin.com/flows/51295d05-6586-4508-83ee-9feee90fdf11)
- **Doc:** `docs/08-dashboard-ux.md` §14 *Topology* bullet.

### SRV-7 Fewer overview panels on the Servers page (M)
- **Problem:** Four panels under the cards for 1–3 servers; the map's place list duplicates the card chips and status; map labels overlap (`servers`).
- **Change:** After SRV-6, merge "At a glance" into the topology card's footer (one line each: "20 replicas on 2 servers", "Traffic enters through server-2 and control plane", "Builds run on server-3"). Show the map only when servers are in 2+ places, as a compact strip beside System load, with the place list on the left like Vercel's CDN view (list + map, not list under map) and collision-free labels (offset or show labels only on hover when points are < 40 px apart).
- **Files:** `pages/servers/index.tsx`, `features/servers/fleet-load.tsx`, `features/servers/server-glance.tsx`, `features/servers/fleet-map.ts`.
- **Evidence:** [Vercel CDN regions list + map](https://mobbin.com/screens/ed0068c5-ccd5-40f8-8221-6676ace3d749), [Laravel Cloud org overview](https://mobbin.com/flows/66ca44b3-2a4f-47e8-94d2-fa32b249482b), [Tailscale machines page](https://mobbin.com/screens/4e6b5e40-1d4d-4b51-971f-49dfdd49a5e1)
- **Doc:** `docs/08-dashboard-ux.md` §14 (Servers page composition), `docs/design/direction.md` if it fixes the panel set.

### SRV-8 Connected state proves it works (S–M)
- **Problem:** After connecting, enrollment shows the device + "Open server / All servers". The magic moment (the second server joins the private network by itself) isn't shown.
- **Change:** In `Connected`, when other servers exist, add a mini diagram: existing server ⟷ new server, line style = path, with "Direct, 0.4 ms" once the first mesh check lands (else "Connecting…" with spinner + word). Under it one checklist of 2–3 live rows: "Reports every few seconds", "Private network: direct", "Ready for apps" (each icon + word). Single-server case: show "Ready for builds" or the role it got.
- **Files:** `pages/servers/enrollment.tsx` (`Connected`), reuse `features/servers/mesh-status.tsx` and node rendering from `fleet-topology.tsx`.
- **Evidence:** [Tailscale "Done!" with device diagram](https://mobbin.com/flows/8599ed56-357b-4026-bf3a-983a9d1070c4), [Cloudflare Connection Status under the command](https://mobbin.com/flows/6975b64d-4b04-4522-86ac-7977e0fc14eb), [Tailscale waiting panel](https://mobbin.com/screens/6cf86c73-db70-47eb-b101-b628b11943fc)
- **Doc:** `docs/design/flows.md` §10 steps 3–5.

### SRV-9 Usage tiles: consistent value + aggregation word (S)
- **Problem:** "7 %", "4.0 GB of 12 GB", "51 % of 356 GB" mix formats; the number's meaning (now? average?) is unstated (`server-detail`).
- **Change:** Every tile: big value in the same kind (percent), unit context below ("4.0 of 12 GB", "182 of 356 GB", "0.9 of 12 cores"), and a small word "now" / "avg" matching the chart. On the Resources tab put the headline top-right of each chart card with "avg." or "current" like Laravel Cloud.
- **Files:** `features/servers/server-usage.tsx`, Resources tab charts (`pages/servers/show.tsx` tab content, `components/charts/*`).
- **Evidence:** [Laravel Cloud metrics](https://mobbin.com/flows/2dbd2a60-e676-42ab-a2a8-8eabba8ad43a), [Laravel Cloud app metrics](https://mobbin.com/screens/b1cd2fc4-7079-4628-a8fd-8afe228908ce), [Neon monitoring](https://mobbin.com/screens/c5ee5c89-e7ad-43fc-828a-46315320e455)
- **Doc:** `docs/15-observability.md` §2.4 if it specifies tile format.

### SRV-10 Status with duration, one place (S)
- **Problem:** Card shows "Offline" next to the name and a red "2 days ago" in the image corner; hero shows "Online" without how long.
- **Change:** One status word with duration: "Offline for 2 days", "Online", "Joining for 3 min" in `ServerStatus`; drop the red timestamp in the card corner (keep "now"/age in grey for online servers or remove it). Hero pill uses the same component.
- **Files:** `features/servers/server-status.tsx`, `features/servers/fleet-card.tsx`, `features/servers/server-hero.tsx`.
- **Evidence:** [Laravel Cloud "Stopped 20m" / "Hibernating 15h"](https://mobbin.com/flows/208f0f71-fb17-49ed-9ee0-bf49640641e2), [Tailscale last seen column](https://mobbin.com/screens/df8e39bc-0025-4b45-916b-a620ca633c91)
- **Doc:** none.

### SRV-11 Location question out of the hero (S)
- **Problem:** "Is control plane in Berlin? [Yes] Change" sits permanently in the hero (`server-detail`).
- **Change:** Show the location as plain meta with a pin in the spec line (SRV-3). Ask the question once, as a dismissible inline row under the hero only while the location is a guess, and remember "Yes". "Change" goes to Settings → Appearance (already the target).
- **Files:** `features/servers/server-hero.tsx`.
- **Evidence:** [Laravel Cloud resource header with pin](https://mobbin.com/flows/5b3d9862-e6ec-4817-aff8-c9f93a4b5916), [Neon region shown, edited elsewhere](https://mobbin.com/flows/9c1b89cb-277a-473a-9813-62c7259f9598)
- **Doc:** `docs/08-dashboard-ux.md` §14 (hero contents) if the question is specified there.

### SRV-12 Revoke / remove confirm lists what's on the server (S)
- **Problem:** Revoke uses a generic confirm; the user doesn't see which projects and volumes are affected at the moment of decision.
- **Change:** Revoke and Retire dialogs list what's placed there (projects with replica counts, volumes and databases, "serves traffic for 12 domains"), one red consequence line ("Apps here stop until they run somewhere else"), then type the server name (reuse `features/moves/typed-confirm.tsx`). Offer "Move to another server" as the secondary action in the same dialog when there is something on it.
- **Files:** `pages/servers/show.tsx` (`DangerZone`), `features/moves/typed-confirm.tsx`.
- **Evidence:** [Laravel Cloud archive dialog](https://mobbin.com/flows/690be9ec-aa46-4d38-8566-ee813ffcd176), [Railway delete service copy](https://mobbin.com/flows/a543148f-5133-49b7-98d0-14c574424b10), [Neon delete project](https://mobbin.com/flows/4f3a0f01-3fb5-4fee-a879-e3052e36b814)
- **Doc:** `docs/08-dashboard-ux.md` §9 (deletion) / §14.

### SRV-13 Move/replace progress as source → target (M)
- **Problem:** Move progress is a list of rows; it doesn't show which server is serving right now, which is the thing people worry about.
- **Change:** Above the per-service rows, a two-node strip: source device card ⟶ target device card, an arrow labelled with the current step ("Copying postgres, 1.2 of 3.4 GB"), and a "Serving traffic" tag on whichever side serves. When the copy is done, the strip shows the switch step and **Retire {source}** sits under the source card. Same component for Replace (dead source greyed).
- **Files:** `features/moves/move-progress.tsx`, `features/moves/cutover-steps.tsx`, `features/servers/replacement-progress.tsx`, `pages/servers/move.tsx`, `pages/servers/replace.tsx`.
- **Evidence:** [PlanetScale workflow source → target](https://mobbin.com/screens/883adb7a-91a6-4ef8-9ed3-7fb234acbf20), [Neon import steps](https://mobbin.com/screens/a6cb4dd8-fad2-4128-aba2-c502157b88dc)
- **Doc:** `docs/08-dashboard-ux.md` §20 *Progress* bullet; `docs/design/flows.md` §14.

### SRV-14 Private network list: path, speed and copyable address per server (S)
- **Problem:** Settings → Network "On the network" rows show name, mesh IP and a status; the path and round trip live only on the server page (`private-network`).
- **Change:** Each row: device thumb + name, status icon + word, "Direct, 0.4 ms" (or "Relayed via Frankfurt"), address with a copy popover offering the IP and the server's internal hostname. Row links to that server's Network tab.
- **Files:** `pages/settings/network.tsx`, `features/servers/mesh-status.tsx`.
- **Evidence:** [Tailscale address copy popover](https://mobbin.com/screens/8e77de97-1ccf-432c-a2f7-55e4e7dbf376), [Railway private hostname with copy](https://mobbin.com/flows/a543148f-5133-49b7-98d0-14c574424b10), [Tailscale machines Connected column](https://mobbin.com/screens/4e6b5e40-1d4d-4b51-971f-49dfdd49a5e1)
- **Doc:** `docs/08-dashboard-ux.md` §14 *Topology* bullet (Settings card contents).

### SRV-15 Role cards show what each role needs (S)
- **Problem:** Role cards in Add server explain the role but not what it needs from the machine, which is the real question at home ("Serve traffic" needs ports 80/443 open).
- **Change:** One muted requirement line per card, Laravel Cloud's spec-line style: Run apps "Needs: always on", Serve traffic "Needs: public IP, ports 80 and 443", Build "Best with: 4+ cores". Role hints stay below for the risky combinations.
- **Files:** `features/servers/add-server.tsx` (`RolePicker`), `features/servers/role-hints.tsx`.
- **Evidence:** [Laravel Cloud configuration radio cards with spec lines](https://mobbin.com/flows/5b3d9862-e6ec-4817-aff8-c9f93a4b5916), [Tailscale "Linux server / Client device" with one-line purpose](https://mobbin.com/screens/7060540b-2ee8-4d9d-b37d-80ce117a9188)
- **Doc:** `docs/08-dashboard-ux.md` §14 *Role hints*; `docs/design/flows.md` §10 step 2.

## Not recommended
- Tables for the servers list (Tailscale, Snowflake, Vanta do this): our 1–2 server audience and device art make cards the better fit; keep cards.
- Plan/price cards (Supabase, PlanetScale): we don't sell sizes; only borrow the spec-line format.
