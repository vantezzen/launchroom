import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import {
    TopBar,
    TopBarBreadcrumbs,
    TopBarSlotProvider,
} from '@/components/patterns/top-bar';
import {
    FloatingBarOutlet,
    FloatingBarProvider,
} from '@/components/patterns/unsaved-bar';
import { RecoveryKitBanner } from '@/features/backups/recovery-kit-banner';
import { RecoveryModeBanner } from '@/features/backups/recovery-mode-banner';
import { MoveBanner } from '@/features/moves/move-banner';
import type { AppLayoutProps } from '@/types';

/**
 * Team pages: sidebar, the team top bar (breadcrumb left, the page's
 * `<TopBarActions>` right), then the page's own `<Page>` container.
 */
export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar" className="min-w-0 overflow-x-clip">
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
            </AppContent>
        </AppShell>
    );
}
