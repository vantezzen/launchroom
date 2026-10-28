import { createMDX } from 'fumadocs-mdx/next';

const withMDX = createMDX();

/** @type {import('next').NextConfig} */
const config = {
    // Dashboard screenshots (components/screenshot.tsx) use 85: at the default
    // 75 their faint canvas grid and thin borders smear.
    images: { qualities: [75, 85] },
    async redirects() {
        return [
            // "Logs and metrics" became "Analytics and metrics" (docs/21 §2).
            { source: '/features/observability', destination: '/features/analytics', permanent: true },
        ];
    },
    async headers() {
        return [
            // The installer: `curl -fsSL https://launchroom.vantezzen.io/install | sh`
            // must always get the current script (docs/21 §3).
            {
                source: '/install',
                headers: [
                    { key: 'Content-Type', value: 'text/plain; charset=utf-8' },
                    { key: 'Cache-Control', value: 'public, max-age=0, must-revalidate' },
                ],
            },
            // JSON Schemas referenced by $schema/$id: fetched by editors and validators.
            {
                source: '/schema/:path*',
                headers: [
                    { key: 'Cache-Control', value: 'public, max-age=3600' },
                    { key: 'Access-Control-Allow-Origin', value: '*' },
                ],
            },
        ];
    },
};

export default withMDX(config);
