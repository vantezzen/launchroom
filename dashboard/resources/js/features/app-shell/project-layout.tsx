import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import type { ReactNode } from 'react';
import { TopBarSlotProvider } from '@/components/patterns/top-bar';
import { SidebarInset } from '@/components/ui/sidebar';
import {
    FloatingBarOutlet,
    FloatingBarProvider,
} from '@/components/patterns/unsaved-bar';
import { ProjectDeployToasts } from '@/features/deployments/deploy-toasts';
import { ProjectTopBar } from '@/features/projects/project-top-bar';
import { warmPages } from '@/lib/idle';
import type { PageName } from '@/lib/pages';
import { cn } from '@/lib/utils';
import { AppShell } from './app-shell';
import { AppSidebar } from './app-sidebar';

/**
 * Pages that fill the viewport under the top bar (design-system §4.2:
 * `full` is only the canvas and the logs explorer). Everything else scrolls
 * the window like team pages do.
 */
const FULL_BLEED = new Set<string>([
    'projects/canvas',
    'projects/logs',
] satisfies PageName[]);

/** The project pages one click away: their code loads once the page is idle. */
const NEXT_PAGES: PageName[] = [
    'projects/canvas',
    'projects/deployments/show',
    'projects/deployments/index',
    'projects/logs',
];

/**
 * Project pages: the app sidebar plus the project top bar (switchers, the
 * project tabs and the staged-changes button) instead of a breadcrumb.
 */
export default function ProjectLayout({ children }: { children: ReactNode }) {
    const { component } = usePage();
    const fullBleed = FULL_BLEED.has(component);

    useEffect(() => warmPages(NEXT_PAGES), []);

    return (
        <AppShell>
            <AppSidebar />
            <SidebarInset
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
            </SidebarInset>
        </AppShell>
    );
}
