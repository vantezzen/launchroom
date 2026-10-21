import { Head } from '@inertiajs/react';
import { Page } from '@/components/patterns/page';
import { LogExplorer } from '@/features/project-logs/log-explorer';
import { index } from '@/routes/logs';

/**
 * Team Logs (sidebar, owners and admins; docs/08 §12): the log explorer
 * over every project of the team, full-bleed. `project:` and
 * `environment:` narrow it; there is no Live here (tails follow one
 * environment, from a project's Logs tab).
 */
export default function TeamLogs() {
    const params = new URLSearchParams(
        typeof window === 'undefined' ? '' : window.location.search,
    );

    return (
        <>
            <Head title="Logs" />
            {/* Full-bleed like the project Logs tab: the table scrolls, not the page (52 px top bar). */}
            <Page
                size="full"
                className="h-[calc(100svh-52px)] flex-none md:h-[calc(100svh-1rem-52px)]"
            >
                <LogExplorer
                    team
                    initialQuery={params.get('q') ?? ''}
                    syncUrl
                />
            </Page>
        </>
    );
}

TeamLogs.layout = {
    breadcrumbs: [{ title: 'Logs', href: index() }],
};
