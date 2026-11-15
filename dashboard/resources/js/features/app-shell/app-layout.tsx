import { useEffect } from 'react';
import type { ReactNode } from 'react';
import {
    TopBar,
    TopBarBreadcrumbs,
    TopBarSlotProvider,
} from '@/components/patterns/top-bar';
import {
    FloatingBarOutlet,
    FloatingBarProvider,
} from '@/components/patterns/unsaved-bar';
import { SidebarInset } from '@/components/ui/sidebar';
import { RecoveryKitBanner } from '@/features/backups/recovery-kit-banner';
import { RecoveryModeBanner } from '@/features/backups/recovery-mode-banner';
import { MoveBanner } from '@/features/moves/move-banner';
import { warmPages } from '@/lib/idle';
import type { PageName } from '@/lib/pages';
import type { BreadcrumbItem } from '@/types';
import { AppShell } from './app-shell';
import { AppSidebar } from './app-sidebar';

/** Team pages lead into projects: the canvas code loads once the page is idle. */
const NEXT_PAGES: PageName[] = ['projects/canvas'];

/**
 * Team pages: sidebar, the team top bar (breadcrumb left, the page's
 * `<TopBarActions>` right), then the page's own `<Page>` container.
 */
export default function AppLayout({
    breadcrumbs = [],
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: ReactNode;
}) {
    useEffect(() => warmPages(NEXT_PAGES), []);

    return (
        <AppShell>
            <AppSidebar />
            <SidebarInset className="min-w-0 overflow-x-clip">
                <TopBarSlotProvider>
                    <FloatingBarProvider>
                        <TopBar>
                            <TopBarBreadcrumbs breadcrumbs={breadcrumbs} />
                        </TopBar>
                        <MoveBanner />
                        <RecoveryModeBanner />
                        <RecoveryKitBanner />
                        {children}
                        <FloatingBarOutlet />
                    </FloatingBarProvider>
                </TopBarSlotProvider>
            </SidebarInset>
        </AppShell>
    );
}
