// Imports curated dashboard screenshots into public/screenshots (docs/21 §4).
//
//   node scripts/import-screenshots.mjs <raw-dir>
//
// <raw-dir> holds <id>-light.png and/or <id>-dark.png (a .webp is taken as is)
// and a manifest.json ([{ id, alt, caption }]). Each image is stored ONCE, at
// full size, as public/screenshots/<id>-<theme>.webp (lossless from a PNG):
// next/image resizes on request. lib/screenshots.json records alt text,
// caption, and per theme the intrinsic size (no layout shift) and a tiny blur
// placeholder. Existing entries not in the manifest are kept.
import { copyFileSync, existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import sharp from 'sharp';

const THEMES = ['light', 'dark'];

const site = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const raw = resolve(process.argv[2] ?? '');
const out = join(site, 'public/screenshots');
const manifestPath = join(site, 'lib/screenshots.json');
mkdirSync(out, { recursive: true });

const input = JSON.parse(readFileSync(join(raw, 'manifest.json'), 'utf8'));
const manifest = existsSync(manifestPath) ? JSON.parse(readFileSync(manifestPath, 'utf8')) : {};

for (const shot of input) {
    const entry = { alt: shot.alt, caption: shot.caption ?? null };
    for (const theme of THEMES) {
        const png = join(raw, `${shot.id}-${theme}.png`);
        const webp = join(raw, `${shot.id}-${theme}.webp`);
        const src = existsSync(png) ? png : existsSync(webp) ? webp : null;
        if (!src) continue;
        const target = join(out, `${shot.id}-${theme}.webp`);
        if (src === png) {
            await sharp(src).webp({ lossless: true, effort: 6 }).toFile(target);
        } else {
            copyFileSync(src, target);
        }
        const { width, height } = await sharp(target).metadata();
        const tiny = await sharp(target).resize({ width: 16 }).webp({ quality: 50 }).toBuffer();
        entry[theme] = { width, height, blur: `data:image/webp;base64,${tiny.toString('base64')}` };
    }
    if (!entry.light && !entry.dark) {
        console.warn(`skipped ${shot.id}: no images`);
        continue;
    }
    manifest[shot.id] = entry;
    console.log(`imported ${shot.id} (${THEMES.filter((t) => entry[t]).join(', ')})`);
}

const sorted = Object.fromEntries(Object.entries(manifest).sort(([a], [b]) => a.localeCompare(b)));
writeFileSync(manifestPath, `${JSON.stringify(sorted, null, 2)}\n`);
