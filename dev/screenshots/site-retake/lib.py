"""Shared helpers for the website screenshot retake (see shots.py)."""
import base64, hmac, hashlib, socket, struct, threading, time

BASE = "http://dashboard:8000"
EMAIL = "intf-owner@example.test"
SECRET = "KRUGKIDROVUWG2ZAMJZG653OEBTG66BA"


def _pipe(a, b):
    try:
        while True:
            d = a.recv(65536)
            if not d:
                break
            b.sendall(d)
    except OSError:
        pass
    finally:
        for x in (a, b):
            try:
                x.shutdown(socket.SHUT_RDWR)
            except OSError:
                pass


def _forward(port, target):
    """The page opens Reverb's websocket at localhost:6001; route() can't
    rewrite websockets, so forward that port inside this container."""
    srv = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    srv.bind(("127.0.0.1", port))
    srv.listen(64)
    while True:
        c, _ = srv.accept()
        try:
            u = socket.create_connection(target)
        except OSError:
            c.close()
            continue
        threading.Thread(target=_pipe, args=(c, u), daemon=True).start()
        threading.Thread(target=_pipe, args=(u, c), daemon=True).start()


threading.Thread(target=_forward, args=(6001, ("reverb", 6001)), daemon=True).start()


def totp(secret=SECRET):
    key = base64.b32decode(secret)
    h = hmac.new(key, struct.pack(">Q", int(time.time()) // 30), hashlib.sha1).digest()
    o = h[19] & 15
    return "%06d" % ((struct.unpack(">I", h[o:o + 4])[0] & 0x7FFFFFFF) % 1000000)


def reroute(route):
    url = route.request.url
    if url.startswith("http://localhost:5173"):
        headers = {**route.request.headers, "host": "localhost:5173"}
        for attempt in range(4):
            try:
                route.fulfill(response=route.fetch(url=url.replace("http://localhost:5173", "http://vite:5173"), headers=headers, timeout=60000))
                return
            except Exception:
                time.sleep(0.5 * (attempt + 1))
        route.abort()
    elif url.startswith("http://localhost:8000"):
        route.continue_(url=url.replace("http://localhost:8000", "http://dashboard:8000"))
    else:
        route.continue_()


def settle(page, ms=2500):
    try:
        page.wait_for_load_state("networkidle", timeout=8000)
    except Exception:
        pass
    page.wait_for_timeout(ms)


def _wait_fresh_window():
    """Fortify refuses a TOTP code twice: wait for a window no earlier run used."""
    last = "/out/raw/.totp-last"
    try:
        used = int(open(last).read())
    except Exception:
        used = 0
    while int(time.time()) // 30 <= used:
        time.sleep(1)
    open(last, "w").write(str(int(time.time()) // 30))


def login(page, email=EMAIL, secret=SECRET):
    for attempt in range(5):
        try:
            page.goto(BASE + "/login", timeout=60000)
            settle(page)
            if "/login" not in page.url:
                break
            page.fill("input[name=email]", email)
            page.fill("input[name=password]", "password")
            page.keyboard.press("Enter")
            page.wait_for_url(lambda u: "/login" not in u, timeout=60000)
            page.wait_for_timeout(1000)
            if "two-factor" in page.url:
                _wait_fresh_window()
                page.locator("input").first.click()
                page.keyboard.type(totp(secret))
                page.wait_for_timeout(300)
                page.keyboard.press("Enter")
                page.wait_for_url(lambda u: "two-factor" not in u, timeout=60000)
            if "/login" not in page.url and "two-factor" not in page.url:
                break
        except Exception as e:
            print("  login attempt failed:", repr(e)[:120], flush=True)
            time.sleep(5)
    print("logged in", page.url, flush=True)
