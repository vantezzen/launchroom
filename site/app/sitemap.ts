import type { MetadataRoute } from 'next';
import { featurePages } from '@/lib/features';
import { siteUrl } from '@/lib/shared';
import { source } from '@/lib/source';

export const dynamic = 'force-static';

export default function sitemap(): MetadataRoute.Sitemap {
    const pages = ['/', ...featurePages.map((f) => f.href), ...source.getPages().map((p) => p.url)];
    return pages.map((path) => ({
        url: `${siteUrl}${path === '/' ? '' : path}`,
        changeFrequency: 'weekly',
        priority: path === '/' ? 1 : path.startsWith('/features') ? 0.8 : 0.6,
    }));
}
