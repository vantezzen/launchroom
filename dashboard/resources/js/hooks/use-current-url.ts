import type { InertiaLinkProps } from '@inertiajs/react';
import { usePage } from '@inertiajs/react';
import { toUrl } from '@/lib/utils';

export function useCurrentUrl(): {
    currentUrl: string;
    /** True on the URL's page and below it. */
    isCurrentOrParentUrl: (
        urlToCheck: NonNullable<InertiaLinkProps['href']>,
    ) => boolean;
} {
    const page = usePage();
    const currentUrl = new URL(
        page.url,
        typeof window !== 'undefined'
            ? window.location.origin
            : 'http://localhost',
    ).pathname;

    const isCurrentOrParentUrl = (
        urlToCheck: NonNullable<InertiaLinkProps['href']>,
    ) => {
        const urlString = toUrl(urlToCheck);

        if (!urlString.startsWith('http')) {
            return currentUrl.startsWith(urlString);
        }

        try {
            return currentUrl.startsWith(new URL(urlString).pathname);
        } catch {
            return false;
        }
    };

    return { currentUrl, isCurrentOrParentUrl };
}
