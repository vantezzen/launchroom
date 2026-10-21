"""Device art pipeline (docs/design-system.md §7).

Turns the maintainer's 3D renders in docs/design/devices/*.png (never modified)
into consistent cut-outs for the dashboard:

1. background: keep the render's alpha; if a render arrives on an opaque
   background (e.g. white), flood-fill it away from the borders and feather
   the edge. Faint specks and the render's own floor shadow are dropped so
   every device gets the same shadow.
2. vendor marks: logos on a device (an apple, a raspberry) are painted out
   with a smooth surface fit + matched grain (design-system §7: no vendor
   marks on devices).
3. framing: the device is scaled to the same visual weight (equal area,
   clamped to a max width/height), centred horizontally and stood on one
   baseline in an identical canvas (320 x 200 at 1x, 16:10).
4. a soft contact shadow (tight core + broad penumbra) is drawn under it.
5. export: WebP 1x/2x and a PNG 2x fallback to public/images/devices/, plus
   a manifest with the status LED position (in % of the canvas) per device.

Run (Docker only, see the Makefile target `device-art`):
  docker run --rm --label launchroom.dev=true --network none \
    -v "$PWD/docs/design/devices":/src:ro \
    -v "$PWD/dashboard/public/images/devices":/out \
    -v "$PWD/dashboard/scripts/device-art":/work:ro \
    launchroom-dev-device-art python /work/process.py
"""

from __future__ import annotations

import json
from dataclasses import dataclass
from pathlib import Path

import numpy as np
from PIL import Image, ImageFilter
from scipy import ndimage

SRC = Path("/src")
OUT = Path("/out")
TS_OUT = Path("/ts")  # optional: resources/js/components/devices

# Canvas at 1x (CSS px). Everything else is relative to it.
CANVAS_W, CANVAS_H = 320, 200
BASELINE_Y = 162  # where every device stands
TARGET_SQRT_AREA = 156  # equal visual weight: sqrt(w * h) of the body
MAX_W, MAX_H = 280, 124
SCALES = (1, 2)


@dataclass(frozen=True)
class Device:
    key: str  # output file name
    source: str  # file in docs/design/devices
    # Boxes (source px: left, top, right, bottom) containing a vendor mark to paint out.
    marks: tuple[tuple[int, int, int, int], ...] = ()
    # Status LED in source px (centre), or None when the device has no visible LED.
    led: tuple[float, float] | None = None
    # Weight tweak: optical size relative to the others (1 = same weight).
    weight: float = 1.0
    # Display area (source px box) repainted as a calm, switched-off screen: a
    # render's wallpaper is someone's artwork and loud colour (design-system §3.6).
    screen: tuple[int, int, int, int] | None = None
    # Boxes inside the screen that are kept (a camera notch).
    screen_keep: tuple[tuple[int, int, int, int], ...] = ()
    # Height cap override in canvas px (tall devices such as a tower).
    max_h: float = MAX_H


DEVICES = (
    Device("server", "server.png", led=(1487, 511), weight=1.0),
    Device("mac", "mac.png", marks=((205, 200, 310, 242),), led=(383, 310), weight=0.98),
    Device("nuc", "nuc.png", led=(273, 724), weight=0.98),
    Device("raspberry-pi", "raspberry-pi.png", marks=((755, 240, 1015, 385),), led=(1093, 657), weight=0.9),
    # Laptop: the LED is the camera indicator in the notch; the wallpaper becomes a dark screen.
    Device("laptop", "laptop.png", led=(769, 92), weight=1.0, screen=(244, 85, 1294, 691), screen_keep=((712, 85, 828, 108),)),
    # Tower: no front LED on the render; the light sits centred on the top band above the grille.
    Device("tower", "tower.png", led=(240, 150), weight=1.0, max_h=148),
    # NAS: the top status light under the power button.
    Device("nas", "nas.png", led=(1410, 463), weight=0.98),
)


def remove_opaque_background(rgba: np.ndarray) -> np.ndarray:
    """Flood-fill a near-uniform background from the borders (renders on white)."""
    rgb = rgba[..., :3].astype(np.float32)
    corners = np.array([rgb[0, 0], rgb[0, -1], rgb[-1, 0], rgb[-1, -1]])
    bg = np.median(corners, axis=0)
    dist = np.linalg.norm(rgb - bg, axis=2)
    near = dist < 18
    labels, _ = ndimage.label(near)
    border = set(np.unique(np.concatenate([labels[0], labels[-1], labels[:, 0], labels[:, -1]]))) - {0}
    background = np.isin(labels, list(border))
    # Feather: alpha ramps with the distance to the background colour near the edge.
    alpha = np.where(background, 0.0, 255.0)
    edge = ndimage.binary_dilation(background, iterations=2) & ~background
    alpha[edge] = np.clip(dist[edge] / 40.0, 0, 1) * 255
    out = rgba.copy()
    out[..., 3] = alpha.astype(np.uint8)
    return out


def clean_alpha(rgba: np.ndarray) -> tuple[np.ndarray, tuple[int, int, int, int]]:
    """Drop specks and the render's floor shadow; return the body bbox."""
    alpha = rgba[..., 3].astype(np.float32)
    solid = alpha > 128
    labels, count = ndimage.label(solid)
    if count == 0:
        raise SystemExit("empty render")
    sizes = ndimage.sum(solid, labels, range(1, count + 1))
    body = labels == (int(np.argmax(sizes)) + 1)
    ys, xs = np.where(body)
    left, top, right, bottom = xs.min(), ys.min(), xs.max() + 1, ys.max() + 1

    # Keep only pixels near the body (anti-aliased edge); drop far specks and
    # anything below the body's bottom edge (the render's own floor shadow).
    near = ndimage.binary_dilation(body, iterations=3)
    alpha = np.where(near, alpha, 0)
    alpha[bottom + 1 :, :] = 0
    alpha[alpha < 12] = 0
    out = rgba.copy()
    out[..., 3] = alpha.astype(np.uint8)
    # Un-premultiply guard: fully transparent pixels get a neutral colour so
    # resampling doesn't bleed odd colours into the edge.
    out[alpha == 0, :3] = 0
    return out, (int(left), int(top), int(right), int(bottom))


def paint_out(rgba: np.ndarray, box: tuple[int, int, int, int]) -> np.ndarray:
    """Replace a vendor mark with a quadratic surface fit plus matched grain."""
    l, t, r, b = box
    patch = rgba[t:b, l:r, :3].astype(np.float32)
    h, w = patch.shape[:2]
    yy, xx = np.mgrid[0:h, 0:w]
    xn, yn = xx / w, yy / h
    design = np.stack([np.ones_like(xn), xn, yn, xn * xn, yn * yn, xn * yn], axis=-1).reshape(-1, 6)

    lum = patch.mean(axis=2)
    # The mark: pixels deviating from a heavy median of the patch border.
    frame = np.zeros((h, w), bool)
    frame[:6, :] = frame[-6:, :] = frame[:, :6] = frame[:, -6:] = True
    fitted = np.empty_like(patch)
    coef_l, *_ = np.linalg.lstsq(design[frame.ravel()], lum.ravel()[frame.ravel()], rcond=None)
    lum_fit = (design @ coef_l).reshape(h, w)
    mark = np.abs(lum - lum_fit) > 6
    mark = ndimage.binary_opening(mark, iterations=1)
    mark = ndimage.binary_dilation(mark, iterations=max(3, w // 60))

    clean = ~mark
    for c in range(3):
        coef, *_ = np.linalg.lstsq(design[clean.ravel()], patch[..., c].ravel()[clean.ravel()], rcond=None)
        fitted[..., c] = (design @ coef).reshape(h, w)

    # Grain: the residual noise of the clean surface, re-used so the fill
    # doesn't look plastic next to the original texture.
    rng = np.random.default_rng(7)
    resid = (patch - fitted)[clean]
    sigma = float(resid.std()) if resid.size else 0.0
    grain = ndimage.gaussian_filter(rng.normal(0, 1, (h, w)), 0.6)
    grain = grain / (grain.std() or 1) * sigma * 0.9
    fill = fitted + grain[..., None]

    weight = ndimage.gaussian_filter(mark.astype(np.float32), max(1.5, w / 120))
    weight = np.clip(weight * 1.6, 0, 1)[..., None]
    out = rgba.copy()
    out[t:b, l:r, :3] = np.clip(patch * (1 - weight) + fill * weight, 0, 255).astype(np.uint8)
    return out


def blank_screen(rgba: np.ndarray, box: tuple[int, int, int, int], keep: tuple[tuple[int, int, int, int], ...]) -> np.ndarray:
    """Paint a display area as a switched-off screen: a dark vertical gradient
    with a faint diagonal sheen, so it reads as glass in light and dark themes."""
    l, t, r, b = box
    h, w = b - t, r - l
    yy, xx = np.mgrid[0:h, 0:w].astype(np.float32)
    top, bottom = np.array([46, 50, 58], np.float32), np.array([22, 24, 29], np.float32)
    grad = top + (bottom - top) * (yy / max(h - 1, 1))[..., None]
    sheen = np.clip(1 - np.abs((xx / w) - (yy / h) * 0.6 - 0.25) / 0.18, 0, 1) * 7
    fill = np.clip(grad + sheen[..., None], 0, 255).astype(np.uint8)
    mask = np.ones((h, w), bool)
    lum = rgba[t:b, l:r, :3].astype(np.float32).mean(axis=2)
    for kl, kt, kr, kb in keep:
        # Inside a keep box only the dark hardware (the notch, its camera) stays.
        box_mask = np.zeros((h, w), bool)
        box_mask[max(kt - t, 0) : kb - t, max(kl - l, 0) : kr - l] = True
        mask[box_mask & (lum < 70)] = False
    out = rgba.copy()
    region = out[t:b, l:r, :3]
    region[mask] = fill[mask]
    out[t:b, l:r, :3] = region
    return out


def contact_shadow(scale: int, left: float, right: float) -> Image.Image:
    """Two stacked soft ellipses under the body: a tight core and a penumbra."""
    w, h = CANVAS_W * scale, CANVAS_H * scale
    layer = np.zeros((h, w), np.float32)
    yy, xx = np.mgrid[0:h, 0:w].astype(np.float32)
    cx = (left + right) / 2 * scale
    half = (right - left) / 2 * scale
    base = BASELINE_Y * scale
    for rx, ry, strength, dy in ((half * 0.98, 3.2 * scale, 0.42, 0.0), (half * 1.08, 9.0 * scale, 0.16, 2.0 * scale)):
        d = ((xx - cx) / rx) ** 2 + ((yy - base - dy) / ry) ** 2
        layer = np.maximum(layer, strength * np.clip(1 - d, 0, 1) ** 1.6)
    layer = ndimage.gaussian_filter(layer, 1.2 * scale)
    shadow = np.zeros((h, w, 4), np.uint8)
    shadow[..., 3] = np.clip(layer * 255, 0, 255).astype(np.uint8)
    return Image.fromarray(shadow)


def process(device: Device) -> dict:
    src = np.array(Image.open(SRC / device.source).convert("RGBA"))
    corners = src[[0, 0, -1, -1], [0, -1, 0, -1], 3]
    if corners.min() > 200:
        src = remove_opaque_background(src)
    for box in device.marks:
        src = paint_out(src, box)
    if device.screen:
        src = blank_screen(src, device.screen, device.screen_keep)
    src, (left, top, right, bottom) = clean_alpha(src)

    bw, bh = right - left, bottom - top
    s = TARGET_SQRT_AREA * device.weight / np.sqrt(bw * bh)
    s = min(s, MAX_W / bw, device.max_h / bh)
    dw, dh = bw * s, bh * s
    dx = (CANVAS_W - dw) / 2
    dy = BASELINE_Y - dh

    # Crop with a small margin so edge anti-aliasing survives the resample.
    m = 4
    crop = Image.fromarray(src).crop((left - m, top - m, right + m, bottom + m))
    meta = {"key": device.key, "body": [round(dx, 2), round(dy, 2), round(dw, 2), round(dh, 2)]}
    if device.led:
        lx = dx + (device.led[0] - left) * s
        ly = dy + (device.led[1] - top) * s
        meta["led"] = [round(lx / CANVAS_W * 100, 2), round(ly / CANVAS_H * 100, 2)]

    for scale in SCALES:
        canvas = Image.new("RGBA", (CANVAS_W * scale, CANVAS_H * scale), (0, 0, 0, 0))
        canvas.alpha_composite(contact_shadow(scale, dx, dx + dw))
        size = (round((bw + 2 * m) * s * scale), round((bh + 2 * m) * s * scale))
        # Resample in premultiplied space to avoid dark/bright fringes.
        piece = crop.convert("RGBa").resize(size, Image.LANCZOS).convert("RGBA")
        if s * scale > 1:
            piece = piece.filter(ImageFilter.UnsharpMask(radius=1.2, percent=40, threshold=2))
        canvas.alpha_composite(piece, (round((dx - m * s) * scale), round((dy - m * s) * scale)))
        canvas.save(OUT / f"{device.key}@{scale}x.webp", "WEBP", quality=90, method=6)
        if scale == 2:
            canvas.save(OUT / f"{device.key}@{scale}x.png", optimize=True)
    return meta


def _num(value: float | int) -> str:
    return json.dumps(value)


def _list(values: list) -> str:
    return "[" + ", ".join(_num(v) for v in values) + "]"


def manifest_json(manifest: dict) -> str:
    """JSON in the repo's formatter style (4 spaces, inline number arrays)."""
    lines = ["{", f'    "canvas": {_list(manifest["canvas"])},', f'    "baseline": {manifest["baseline"]},', '    "devices": {']
    items = list(manifest["devices"].items())
    for i, (key, meta) in enumerate(items):
        fields = [f'"key": "{meta["key"]}"', f'"body": {_list(meta["body"])}'] + ([f'"led": {_list(meta["led"])}'] if "led" in meta else [])
        lines.append(f'        "{key}": {{')
        lines += [f"            {field}{',' if j < len(fields) - 1 else ''}" for j, field in enumerate(fields)]
        lines.append("        }" + ("," if i < len(items) - 1 else ""))
    lines += ["    }", "}"]
    return "\n".join(lines) + "\n"


def manifest_ts(manifest: dict) -> str:
    """TypeScript module in the repo's formatter style (vp fmt clean)."""
    def ident(key: str) -> str:
        return key if key.replace("_", "").isalnum() else f"'{key}'"

    lines = [
        "// Generated by dashboard/scripts/device-art/process.py (`make device-art`). Do not edit.",
        "// Canvas size, baseline and per-device body box (1x px) and status LED position (% of canvas).",
        "export const DEVICE_ART = {",
        f"    canvas: {_list(manifest['canvas'])},",
        f"    baseline: {manifest['baseline']},",
        "    devices: {",
    ]
    for key, meta in manifest["devices"].items():
        lines.append(f"        {ident(key)}: {{")
        lines.append(f"            key: '{meta['key']}',")
        lines.append(f"            body: {_list(meta['body'])},")
        if "led" in meta:
            lines.append(f"            led: {_list(meta['led'])},")
        lines.append("        },")
    lines += ["    },", "} as const;"]
    return "\n".join(lines) + "\n"


def main() -> None:
    OUT.mkdir(parents=True, exist_ok=True)
    manifest = {
        "canvas": [CANVAS_W, CANVAS_H],
        "baseline": BASELINE_Y,
        "devices": {d.key: process(d) for d in DEVICES},
    }
    (OUT / "manifest.json").write_text(manifest_json(manifest))
    if TS_OUT.is_dir():
        (TS_OUT / "device-art.generated.ts").write_text(manifest_ts(manifest))
    print(manifest_json(manifest), end="")


if __name__ == "__main__":
    main()
