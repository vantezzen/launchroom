import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { TopBarSlotProvider } from '@/components/patterns/top-bar';
import {
    FloatingBarOutlet,
    FloatingBarProvider,
} from '@/components/patterns/unsaved-bar';
import { ProjectDeployToasts } from '@/features/deployments/deploy-toasts';
import { ProjectTopBar } from '@/features/projects/project-top-bar';
import { cn } from '@/lib/utils';

/**
 * Pages that fill the viewport under the top bar (design-system §4.2:
 * `full` is only the canvas and the logs explorer). Everything else scrolls
 * the window like team pages do.
 */
const FULL_BLEED = new Set(['projects/canvas', 'projects/logs']);

/**
 * Project pages: the app sidebar plus the project top bar (switchers, the
 * project tabs and the staged-changes button) instead of a breadcrumb.
 */
export default function ProjectLayout({ children }: { children: ReactNode }) {
    const { component } = usePage();
    const fullBleed = FULL_BLEED.has(component);

    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent
                variant="sidebar"
                className={cn(
                    'min-w-0',
                    fullBleed
                        ? 'h-svh min-h-0 overflow-hidden md:h-[calc(100svh-1rem)]'
                        : 'overflow-x-clip',
                )}
            >
                <TopBarSlotProvider>
                    <FloatingBarProvider>
                        <ProjectTopBar />
                        <ProjectDeployToasts />
                        {fullBleed ? (
                            <div className="relative flex min-h-0 flex-1 flex-col">
                                {children}
                            </div>
                        ) : (
                            children
                        )}
                        <FloatingBarOutlet overlay={fullBleed} />
                    </FloatingBarProvider>
                </TopBarSlotProvider>
            </AppContent>
        </AppShell>
    );
}
