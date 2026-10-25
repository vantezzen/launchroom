import { useCurrentUrl } from '@/hooks/use-current-url';
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import { index as settingsHome } from '@/routes/settings';
import type { BreadcrumbItem } from '@/types';

export default function AppLayout({
    breadcrumbs = [],
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
}) {
    const { currentUrl } = useCurrentUrl();

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
