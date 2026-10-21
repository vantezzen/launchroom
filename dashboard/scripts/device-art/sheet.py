"""Contact sheet of the processed device art on the light and dark recessed stage."""
import json
import sys
from pathlib import Path
from PIL import Image, ImageDraw

OUT = Path(sys.argv[1] if len(sys.argv) > 1 else "/out")
DEST = Path(sys.argv[2]) if len(sys.argv) > 2 else OUT
keys = ["server", "mac", "nuc", "raspberry-pi", "laptop", "tower", "nas"]
leds = {k: v.get("led") for k, v in json.loads((OUT / "manifest.json").read_text())["devices"].items()}
for theme, bg, line in (("light", (250, 250, 250), (230, 230, 230)), ("dark", (13, 13, 13), (40, 40, 40))):
    w, h = 640, 400
    sheet = Image.new("RGB", (w * len(keys) + 20 * (len(keys) + 1), h + 40), bg)
    d = ImageDraw.Draw(sheet)
    for i, k in enumerate(keys):
        im = Image.open(OUT / f"{k}@2x.webp").convert("RGBA")
        x = 20 + i * (w + 20)
        d.rectangle((x, 20, x + w - 1, 20 + h - 1), outline=line)
        d.line((x, 20 + 162 * 2, x + w, 20 + 162 * 2), fill=line)
        sheet.paste(im, (x, 20), im)
        if leds.get(k):
            # The status LED overlay (green), as DeviceImage draws it.
            cx, cy = x + leds[k][0] / 100 * w, 20 + leds[k][1] / 100 * h
            d.ellipse((cx - 5, cy - 5, cx + 5, cy + 5), fill=(34, 197, 94))
    sheet.save(DEST / f"sheet-{theme}.png")
