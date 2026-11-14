import { useEffect } from 'react';
import { useCurrentUrl } from '@/hooks/use-current-url';
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import { warmPages } from '@/lib/idle';
import { index as settingsHome } from '@/routes/settings';
import type { BreadcrumbItem } from '@/types';

/** Team pages lead into projects: the canvas code loads once the page is idle. */
const NEXT_PAGES = ['projects/canvas'];

export default function AppLayout({
    breadcrumbs = [],
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
}) {
    const { currentUrl } = useCurrentUrl();

    useEffect(() => warmPages(NEXT_PAGES), []);

    return (
        <AppLayoutTemplate
            breadcrumbs={
                // `/settings` is the settings list (Profile beside it on wide
                // screens): the trail ends at "Settings".
                currentUrl === settingsHome().url
                    ? breadcrumbs.slice(0, 1)
                    : breadcrumbs
            }
        >
            {children}
        </AppLayoutTemplate>
    );
}
