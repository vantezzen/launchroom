import { readFile } from 'node:fs/promises';
import { join } from 'node:path';
import { ImageResponse } from 'next/og';

// Served as /og.png, prerendered at build time (image/png on every host).
export const dynamic = 'force-static';
const size = { width: 1200, height: 630 };

const MARK =
    'M25 0H75C88.81 0 100 11.19 100 25V75C100 88.81 88.81 100 75 100H25C11.19 100 0 88.81 0 75V25C0 11.19 11.19 0 25 0ZM36.15 22.4C34.63 22.4 33.4 23.63 33.4 25.15V54.35C33.4 54.61 33.44 54.86 33.5 55.1H33.4C33.4 63.12 38.12 70.38 45.44 73.64C52.77 76.91 61.33 75.55 67.28 70.19L59.32 61.34C56.86 63.56 53.31 64.12 50.28 62.77C47.25 61.42 45.3 58.42 45.3 55.1H45.2C45.26 54.86 45.3 54.61 45.3 54.35V25.15C45.3 23.63 44.07 22.4 42.55 22.4Z';

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
                    <path d={MARK} fill="#171717" fillRule="evenodd" />
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
