import type { MetadataRoute } from 'next';

export const dynamic = 'force-static';

export default function manifest(): MetadataRoute.Manifest {
    return {
        name: 'launchroom',
        short_name: 'launchroom',
        description: 'One place to deploy. Open source, on servers you choose.',
        start_url: '/',
        display: 'browser',
        background_color: '#ffffff',
        theme_color: '#171717',
        icons: [{ src: '/icon.svg', type: 'image/svg+xml', sizes: 'any' }],
    };
}
