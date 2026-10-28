"""Website product screenshots (site/public/screenshots, docs/21 §4), retaken
from the live dev dashboard at 2x device pixels (1440 CSS px, 2880 px wide), so
they stay sharp on retina screens and in close-up cards. Writes
<id>-<theme>.png into /out/raw.

docker run --rm --network launchroom-dev_default --label launchroom.dev=true \
  --name launchroom-dev-site-retake -v "$PWD/dev/screenshots/site-retake:/out" \
  launchroom-dev-ui1-playwright python /out/shots.py [light|dark|both] [id ...]

Then: node site/scripts/import-screenshots.mjs <raw dir> (in the site node
container). Security shots (security-*) are taken by dev/screenshots/security.
"""
import json, os, sys, time
from playwright.sync_api import sync_playwright

exec(open("/out/lib.py").read())
OUT = "/out/raw"
os.makedirs(OUT, exist_ok=True)

SHOP = "/p/prj_01M59SM37RK2FNZD7HP65BZHH3/env_01M59SM386T9NH48BACQS4T0WM"
SHOP_STG = "env_01M59SM3ANV7Z9R2GXGMG4Y6ZG"
SHOP_PRJ = "prj_01M59SM37RK2FNZD7HP65BZHH3"
SHOP_PRD = "env_01M59SM386T9NH48BACQS4T0WM"
SHOP_API = "svc_01M59SM396684PC3FM8R8EGB0P"
SHOP_WEB = "svc_01M59SM39E967ZJRMSTR4JX4DY"
ACME = "/p/prj_01M59SDW7FHB3NNET7S9DHK1EC/env_01M59SDWA27ERZHV49HK5HD01S"
ACME_WEB = "svc_01M59SDWEBFE7ZJSK3R6EXQDG3"
BLOG = "/p/prj_01M59SEPKP5J4ZH3KX8N7B79N3/env_01M59SEPM6E2A0HFZT4PCD3795"
BLOG_WEB = "svc_01M59SEPMXF0JDMETTEVXW8Q66"
STORE = "/p/prj_01M5AVH7KS1TCDGPYTB7Y8CPVF/env_01M5AVH7MD8VTQJRDD4YPKAFPB"
STORE_PRJ = "prj_01M5AVH7KS1TCDGPYTB7Y8CPVF"
STORE_WEB = "svc_01M5AVH7NGD713M9XP0TQ8KAJC"
JOBS = "/p/prj_01M5C35P4VBW9AVCJ53ENXM9YB/env_01M5C35P65HENGRBB3WZ9BK0AB"
JOBS_WEB = "svc_01M5C35P8KAHAHPX3TTBT9GF00"
JOBS_CRON = "svc_01M5C35PDHD0K88HF0KF34MDSQ"
PLATFORM = "/p/prj_01M5ATSWHA7F120FW5CTMP6MJG/env_01M5ATSWKA8W2Z93PNBRJB84BD"
PLATFORM_WEB = "svc_01M5ATSWMVFNZJWA4NCDW26NTJ"
P3 = "/p/prj_01M59H7959N1M8KQ253NC9066S/env_01M59H795M937R9GMZEQ39H35V"
P3_NODE = "svc_01M59H7967PV97FMSPRWJDHH86"
# The Data tab shots use the Postgres of p3exit, seeded with customers, orders
# and pageviews (dev/screenshots/site-retake/data-seed.sql).
P3_PG = "svc_01M59H797AY0BZ7PJY1R9RMR68"
PITR = "/p/prj_01M5A9RC5PYA45XXGEZW10XNJ7/env_01M5A9RC6H8MENBN3K5Q58RF36"
PITR_DB = "svc_01M5A9RC83EQQEE5P94WH0K8E9"
MONGO = "/p/prj_01M5C60K8KG6WVZ22BRB4RD3SZ/env_01M5C60K9DSR6HCYNQHNBJHMWZ"
AIGEN = "/p/prj_01M5C5FWY3RG57EG174RFDTMCJ/env_01M5C5FWYD8C5R772PJQSQ8CV2"
AIGEN_SITE = "svc_01M5C5FX4CPXKC079E9HF8XASE"
SRV_CP = "srv_01M570QPN2VT13ACR4M8Z1G422"


def click_text(page, text, exact=False, nth=0, role=None):
    loc = page.get_by_role(role, name=text, exact=exact) if role else page.get_by_text(text, exact=exact)
    loc.nth(nth).click()
    page.wait_for_timeout(900)


def scroll_main_to(page, text):
    page.get_by_text(text, exact=False).first.scroll_into_view_if_needed()
    page.wait_for_timeout(600)


def scroll_heading_top(page, text, offset=0):
    """Scroll the nearest scroll container so the element with `text` sits near its top."""
    page.evaluate(
        """([t, off]) => {
            const els = [...document.querySelectorAll('h1,h2,h3,h4,p,span,div,label')]
              .filter(e => e.childElementCount === 0 && e.textContent.trim() === t);
            const el = els.find(e => e.offsetParent) || els[0];
            if (!el) return;
            let p = el.parentElement;
            while (p && !(p.scrollHeight > p.clientHeight + 4 && ['auto','scroll'].includes(getComputedStyle(p).overflowY))) p = p.parentElement;
            const box = el.getBoundingClientRect();
            if (p) { p.scrollTop += box.top - p.getBoundingClientRect().top - off; }
            else { window.scrollBy(0, box.top - off); }
        }""", [text, offset])
    page.wait_for_timeout(700)


# ---- per-shot actions -------------------------------------------------------

def a_add_database(page):
    page.get_by_role("button", name="Add").last.click(); page.wait_for_timeout(800)
    click_text(page, "Database", exact=True)
    page.wait_for_timeout(800)
    page.get_by_text("MongoDB", exact=True).first.click(); page.wait_for_timeout(1200)


def a_add_server_roles(page):
    page.get_by_text("Machine at home", exact=False).first.click(); page.wait_for_timeout(600)
    for name in ("Continue", "Next"):
        b = page.get_by_role("button", name=name)
        if b.count():
            b.first.click(); break
    page.wait_for_timeout(1200)
    build = page.get_by_role("switch", name="Build", exact=True).first
    if build.get_attribute("aria-checked") != "true":
        build.click(); page.wait_for_timeout(800)


def a_add_server(page):
    a_add_server_roles(page)
    page.get_by_role("button", name="Get the command").click()
    page.wait_for_timeout(3500)


def after_add_server(page):
    """Undo the pending server that "Get the command" created."""
    page.set_viewport_size({"width": 1440, "height": 1200})
    page.get_by_role("button", name="Remove server").first.click(); page.wait_for_timeout(800)
    page.get_by_role("dialog").get_by_role("button", name="Remove server").click()
    page.wait_for_timeout(2500)
    print("  removed the pending server", page.url, flush=True)


AFTER = {"add-server": after_add_server}
QUICK = {"settings-search"}  # shot right after the action (transient state)


def a_ai_dockerfile(page):
    title = page.get_by_text("Generate a Dockerfile", exact=True).first
    title.scroll_into_view_if_needed()
    card = title.locator("xpath=ancestor::*[.//button][1]")
    btn = card.get_by_role("button", name="View")
    if not btn.count():
        btn = card.get_by_role("button", name="Generate")
    btn.first.click(); page.wait_for_timeout(2500)


def a_capabilities(page):
    scroll_heading_top(page, "Capabilities", 250)


def a_command_palette(page):
    page.mouse.click(700, 500)
    page.keyboard.press("Control+k"); page.wait_for_timeout(1500)


def a_compose(page):
    yaml = open("/out/compose.yml").read()
    ta = page.locator("textarea").first
    ta.fill(yaml)
    page.evaluate("() => document.activeElement && document.activeElement.blur()")
    page.wait_for_timeout(4500)


def a_console(page):
    page.get_by_role("button", name="Open console").first.click()
    page.wait_for_timeout(6000)
    page.locator(".xterm, [data-terminal], canvas").first.click()
    page.keyboard.type("echo hello from launchroom && id && ls /", delay=20)
    page.keyboard.press("Enter")
    page.wait_for_timeout(3000)


def a_env_switcher(page):
    page.get_by_role("button", name="production").first.click(); page.wait_for_timeout(1000)


def open_data(page):
    b = page.get_by_role("button", name="Open data")
    if b.count():
        b.first.click()
    page.wait_for_timeout(5000)


def a_data_query(page):
    open_data(page)
    page.get_by_text("Query", exact=True).first.click(); page.wait_for_timeout(800)
    ta = page.locator("textarea").first
    ta.fill("SELECT path, count(*) AS views, round(avg(ms)) AS avg_ms\nFROM pageviews GROUP BY path ORDER BY views DESC")
    page.get_by_role("button", name="Run").first.click()
    page.get_by_text("rows", exact=False).first.wait_for(timeout=60000)
    page.wait_for_timeout(1500)


def a_data_browse(page):
    open_data(page)
    page.get_by_text("pageviews", exact=True).first.click(timeout=60000)
    page.get_by_text("Rows 1", exact=False).first.wait_for(timeout=60000)
    page.wait_for_timeout(1500)


def a_domains_edges(page):
    scroll_heading_top(page, "Edge servers", 420)


def a_logs(page):
    # The seeded "timeout" errors aged out of the log store; "health" still
    # matches real deploy lines (and one failed health check) in 30 days.
    page.locator("input").first.fill("health"); page.wait_for_timeout(500)
    try:
        page.get_by_role("button", name="All environments").first.click()
    except Exception:
        page.get_by_text("All environments").first.click()
    page.wait_for_timeout(500)
    try:
        page.get_by_role("button", name="30d", exact=True).first.click()
    except Exception:
        page.get_by_text("30d", exact=True).first.click()
    page.wait_for_timeout(3500)


def a_pitr(page):
    page.get_by_text("Any point in time", exact=True).first.click(); page.wait_for_timeout(1200)
    scroll_heading_top(page, "Point-in-time recovery", 200)


def a_restore_dialog(page):
    scroll_heading_top(page, "Restore", 120)
    btn = page.get_by_role("button", name="Restore as a new service")
    if not btn.count():
        btn = page.get_by_role("button", name="Restore")
    btn.last.click(); page.wait_for_timeout(1500)


def a_scaling(page):
    plus = page.get_by_role("button", name="Add a replica")
    if not plus.count():
        plus = page.get_by_role("button", name="Increase")
    for _ in range(2):
        plus.first.click(); page.wait_for_timeout(400)
    page.wait_for_timeout(1000)


def a_settings_search(page):
    page.get_by_placeholder("Search settings").fill("replicas"); page.wait_for_timeout(1000)
    page.keyboard.press("Enter")
    # The row is tinted only briefly (FLASH_MS): shoot once the smooth scroll ends.
    page.wait_for_selector("[data-setting-flash]", timeout=5000)
    page.wait_for_timeout(700)


def a_staged(page):
    for name in ("Review & deploy", "Review and deploy"):
        b = page.get_by_role("button", name=name)
        if b.count():
            b.first.click(); page.wait_for_timeout(2000); return
    page.get_by_text("changes", exact=False).first.click(); page.wait_for_timeout(2000)


def a_team_invite(page):
    page.get_by_role("button", name="Invite people").first.click(); page.wait_for_timeout(1200)
    page.locator("textarea").first.fill("mara@acme.com, jonas@acme.com")
    page.get_by_text("Developer", exact=True).last.click(); page.wait_for_timeout(800)


def a_template_detail(page):
    page.get_by_text("Laravel + MySQL + Redis", exact=True).first.click(); page.wait_for_timeout(2500)


def a_server_topology(page):
    page.mouse.wheel(0, 0)
    scroll_heading_top(page, "Overview", 130)


def a_metrics(page):
    # Seeded traffic is from Oct 19: the day view is empty by now.
    page.get_by_text("Week", exact=True).first.click()
    page.wait_for_timeout(3000)


def a_mtls(page):
    pass


def a_cli(page):
    pass


def a_promote(page):
    pass


# id: (path, width, height, dpr, full_page, action)
SHOTS = {
    "activity": ("/activity", 1440, 900, 2, False, None),
    "add-database": (MONGO, 1440, 1000, 2, False, a_add_database),
    "add-server": ("/servers/new", 1440, 998, 2, True, a_add_server),
    "add-server-roles": ("/servers/new", 1440, 900, 2, False, a_add_server_roles),
    "ai-dockerfile": (f"{AIGEN}/s/{AIGEN_SITE}/settings/build", 1440, 900, 2, False, a_ai_dockerfile),
    "alerts": ("/settings/notifications", 1440, 900, 2, True, None),
    "api-tokens": ("/settings/api-tokens", 1440, 1000, 2, False, None),
    "backup-settings": ("/settings/backups", 1440, 900, 2, True, None),
    "canvas": (SHOP, 1440, 900, 2, False, None),
    "canvas-alt": (ACME, 1440, 900, 2, False, None),
    "capabilities": (f"{JOBS}/s/{JOBS_WEB}/settings/advanced", 1440, 900, 2, False, a_capabilities),
    "cli-sign-in": ("CLI", 1440, 1010, 2, True, None),
    "command-palette": (ACME, 1440, 900, 2, False, a_command_palette),
    "compose-import": ("/projects/new/compose", 1440, 900, 2, True, a_compose),
    "config-managed": (f"{STORE}/s/{STORE_WEB}/settings/deploy", 1440, 900, 2, False, None),
    "config-problems": (f"{STORE}/d/dpl_01M5AVH7T1RMNPAK5YPMP874AW", 1440, 900, 2, True, None),
    "config-settings": (f"/p/{STORE_PRJ}/settings/config-file", 1440, 900, 2, True, None),
    "console": (f"{P3}/s/{P3_NODE}/console", 1440, 1000, 2, False, a_console),
    "cron": (f"{JOBS}/s/{JOBS_CRON}/settings/schedule", 1440, 900, 2, False, None),
    "dashboard-domain": ("/settings/dashboard-domain", 1440, 900, 2, True, None),
    "database-backups": (f"{PITR}/s/{PITR_DB}/settings/backups", 1440, 1000, 2, False, None),
    "database-browse": (f"{P3}/s/{P3_PG}/data", 1440, 1000, 2, False, a_data_browse),
    "database-data": (f"{P3}/s/{P3_PG}/data", 1440, 1000, 2, False, a_data_query),
    "deployment": (f"{SHOP}/d/dpl_01M59SM3EQGV7HDQX9E43ZVRNB", 1440, 900, 2, False, None),
    "deployment-failed": (f"{SHOP}/d/dpl_01M59SM3EG8YBF62G63W339RXC", 1440, 900, 2, True, None),
    "domains": (f"{BLOG}/s/{BLOG_WEB}/settings/domains", 1440, 960, 2, False, None),
    "domains-edges": (f"{BLOG}/s/{BLOG_WEB}/settings/domains", 1440, 960, 2, False, a_domains_edges),
    "env-switcher": (SHOP, 1440, 900, 2, False, a_env_switcher),
    "git-providers": ("/settings/git", 1440, 900, 2, True, None),
    "git-source": (f"{PLATFORM}/s/{PLATFORM_WEB}/settings/source", 1440, 1000, 2, False, None),
    "logs": (f"{SHOP}/logs", 1440, 1000, 2, False, a_logs),
    "metrics": (f"{SHOP}/metrics", 1440, 900, 2, True, a_metrics),
    "mtls": ("/settings/instance-security", 1440, 900, 2, True, None),
    "onboarding": ("/onboarding", 1440, 900, 2, False, None),
    "pitr": (f"{PITR}/s/{PITR_DB}/settings/backups", 1440, 1000, 2, False, a_pitr),
    "previews": ("/p/prj_01M5AVNP34QRTV484YQ4JK0MXC/previews", 1440, 900, 2, False, None),
    "private-network": ("/settings/network", 1440, 900, 2, True, None),
    "projects": ("/projects", 1440, 1211, 2, False, None),
    "promote": (f"/p/{SHOP_PRJ}/promote?from={SHOP_STG}&to={SHOP_PRD}", 1440, 900, 2, False, None),
    "restore-dialog": (f"{PITR}/s/{PITR_DB}/settings/backups", 1440, 1000, 2, False, a_restore_dialog),
    "scaling": (f"{SHOP}/s/{SHOP_WEB}/settings/scaling", 1440, 960, 2, False, a_scaling),
    "server-detail": (f"/servers/{SRV_CP}", 1440, 900, 2, True, None),
    "server-network": (f"/servers/{SRV_CP}/network", 1440, 900, 2, True, None),
    "server-topology": ("/servers", 1440, 1000, 2, False, a_server_topology),
    "servers": ("/servers", 1440, 900, 2, True, None),
    "setup": ("SETUP", 1440, 900, 2, False, None),
    "service-panel": (f"{SHOP}/s/{SHOP_API}/deployments", 1440, 900, 2, False, None),
    "service-settings": (f"{P3}/s/{P3_NODE}/settings/general", 1440, 960, 2, False, None),
    "settings-search": (f"{SHOP}/s/{SHOP_WEB}/settings/general", 1440, 960, 2, False, a_settings_search),
    "staged-changes": (STORE, 1440, 900, 2, False, a_staged),
    "staged-changes-blocked": (ACME, 1440, 900, 2, False, a_staged),
    "team-invite": ("/settings/team", 1440, 900, 2, False, a_team_invite),
    "template-detail": ("/projects/new/templates", 1440, 960, 2, False, a_template_detail),
    "templates": ("/projects/new/templates", 1440, 960, 2, False, None),
    "two-factor": ("/settings/security", 1440, 900, 2, True, None),
    "updates": ("/settings/updates", 1440, 900, 2, True, None),
    "variables": (f"{ACME}/s/{ACME_WEB}/settings/variables", 1440, 900, 2, False, None),
}


# The first-run screen can't be reached once an owner exists: serve the login
# page's HTML with the Inertia page swapped to `setup` (as v2/ws4/shoot_auth.py
# does), so the real bundle renders the real component. Nothing is written.
SETUP_SERVER = {
    "id": "srv_demo", "name": "fra-1", "status": "online", "roles": ["edge", "runtime", "build"],
    "isControlPlane": True, "daemonVersion": "0.5.0", "lastSeenAt": None, "mesh": None, "workloads": 0,
    "device": {"kind": "cloud", "detected": "cloud", "overridden": False, "model": "Hetzner Cloud",
               "provider": "hetzner", "providerName": "Hetzner", "virtualized": True},
    "location": None,
    "specs": {"cpus": 4, "memoryMb": 8192, "diskTotalMb": 163840, "arch": "amd64", "cpuModel": "AMD EPYC", "osName": "Ubuntu 24.04"},
}


def setup_page(b, page):
    import re
    anon = b.new_context()
    html = anon.request.get(BASE + "/login").text()
    anon.close()
    m = re.search(r'(<script data-page="app" type="application/json">)(.*?)(</script>)', html, re.S)
    data = json.loads(m.group(2))
    data["component"] = "setup"
    data["props"] = {**data["props"], "token": "lr_setup_demo", "passwordRules": "minlength: 8;",
                     "server": SETUP_SERVER, "instanceUrl": "https://launchroom.acme.com"}
    data["url"] = "/setup"
    body = html[:m.start(2)] + json.dumps(data).replace("</", "<\\/") + html[m.end(2):]
    page.route(BASE + "/__setup", lambda r: r.fulfill(status=200, content_type="text/html", body=body))
    return "/__setup"


def cli_code(page):
    r = page.request.post(BASE + "/api/v1/cli/sessions", data=json.dumps({"clientName": "launchroom-cli", "hostname": "bennett-mbp", "version": "0.1.0"}),
                          headers={"Content-Type": "application/json", "Accept": "application/json"})
    body = r.json()
    print("  cli session:", r.status, flush=True)
    data = body.get("data", body)
    return data.get("verificationUrlComplete") or ("/cli/login?code=" + data["userCode"])


def shoot(b, state, sid, scheme):
    path, w, h, dpr, full, action = SHOTS[sid]
    ctx = b.new_context(viewport={"width": w, "height": h}, color_scheme=scheme, storage_state=state, device_scale_factor=dpr)
    page = ctx.new_page(); page.route("**/*", reroute)
    try:
        if path == "SETUP":
            path = setup_page(b, page)
        if path == "CLI":
            path = cli_code(page)
            if path.startswith("http"):
                path = path.split("://", 1)[1].split("/", 1)[1]
                path = "/" + path
        page.goto(BASE + path, wait_until="domcontentloaded", timeout=90000)
        # Under load the app can mount late: wait for real content, not a blank page.
        for _ in range(90):
            if page.evaluate("() => document.body ? document.body.innerText.trim().length : 0") > 40:
                break
            page.wait_for_timeout(1000)
        settle(page, 3000)
        if action:
            action(page)
        if sid not in QUICK:
            page.wait_for_timeout(1200)
        if full:
            height = page.evaluate("() => document.documentElement.scrollHeight")
            page.set_viewport_size({"width": w, "height": max(h, height)})
            page.wait_for_timeout(1500)
        page.mouse.move(w - 2, h - 2)
        page.screenshot(path=f"{OUT}/{sid}-{scheme}.png")
        print("shot", sid, scheme, flush=True)
        if sid in AFTER:
            AFTER[sid](page)
    except Exception as e:
        print("FAIL", sid, scheme, repr(e)[:300], flush=True)
        try:
            page.screenshot(path=f"{OUT}/{sid}-{scheme}.fail.png")
        except Exception:
            pass
    ctx.close()


with sync_playwright() as p:
    b = p.chromium.launch()
    ctx = b.new_context(viewport={"width": 1440, "height": 900})
    page = ctx.new_page(); page.route("**/*", reroute)
    login(page)
    state = ctx.storage_state()
    ctx.close()
    mode = sys.argv[1] if len(sys.argv) > 1 else "both"
    ids = sys.argv[2:] or list(SHOTS)
    schemes = ["light", "dark"] if mode == "both" else [mode]
    for sid in ids:
        for scheme in schemes:
            if sid == "compose-import" and scheme == "dark":
                continue
            shoot(b, state, sid, scheme)
    b.close()
