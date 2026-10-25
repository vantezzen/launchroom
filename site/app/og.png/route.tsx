import { readFile } from 'node:fs/promises';
import { join } from 'node:path';
import { ImageResponse } from 'next/og';

// Served as /og.png, prerendered at build time (image/png on every host).
export const dynamic = 'force-static';
const size = { width: 1200, height: 630 };

const MARK =
    'M93.75 53.12C93.75 68.66 81.16 81.25 65.62 81.25H56.25V62.5C56.25 59.05 53.45 56.25 50 56.25C46.55 56.25 43.75 59.05 43.75 62.5V81.25H25C14.64 81.25 6.25 72.86 6.25 62.5C6.25 52.14 14.64 43.75 25 43.75C29.96 43.75 34.47 45.68 37.83 48.82C39.9 35.33 51.55 25 65.62 25C81.16 25 93.75 37.59 93.75 53.12Z';

/** The site's social card, rendered once at build time. */
export async function GET() {
    const fonts = join(process.cwd(), 'node_modules/geist/dist/fonts');
    const [semibold, regular, mono] = await Promise.all([
        readFile(join(fonts, 'geist-sans/Geist-SemiBold.ttf')),
        readFile(join(fonts, 'geist-sans/Geist-Regular.ttf')),
        readFile(join(fonts, 'geist-mono/GeistMono-Regular.ttf')),
    ]);

    return new ImageResponse(
        <div
            style={{
                width: '100%',
                height: '100%',
                display: 'flex',
                flexDirection: 'column',
                justifyContent: 'space-between',
                padding: '72px 80px',
                background: '#ffffff',
                color: '#171717',
                fontFamily: 'Geist',
            }}
        >
            <div style={{ display: 'flex', alignItems: 'center', gap: 18 }}>
                <svg width="52" height="52" viewBox="0 0 100 100">
                    <path d={MARK} fill="#171717" />
                </svg>
                <span style={{ fontSize: 34, fontWeight: 600, letterSpacing: '-0.01em' }}>launchroom</span>
            </div>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 28 }}>
                <span style={{ fontSize: 104, fontWeight: 600, letterSpacing: '-0.045em', lineHeight: 1 }}>
                    One place to deploy.
                </span>
                <span style={{ fontSize: 32, color: '#4d4d4d', lineHeight: 1.35, maxWidth: 940 }}>
                    Everything a cloud host does for your apps, on your own server. Free and open source.
                </span>
            </div>
            <div style={{ display: 'flex', gap: 28, fontSize: 24, color: '#4d4d4d', fontFamily: 'Geist Mono' }}>
                <span>launchroom.vantezzen.io</span>
                <span>·</span>
                <span>Free · MIT</span>
            </div>
        </div>,
        {
            ...size,
            fonts: [
                { name: 'Geist', data: semibold, weight: 600 },
                { name: 'Geist', data: regular, weight: 400 },
                { name: 'Geist Mono', data: mono, weight: 400 },
            ],
        },
    );
}
