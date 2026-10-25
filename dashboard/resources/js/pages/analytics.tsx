import { Head } from '@inertiajs/react';
import { Page } from '@/components/patterns/page';
import { TeamAnalytics } from '@/features/analytics/team-analytics';
import { index } from '@/routes/analytics';

/**
 * Team Analytics (sidebar; docs/15 §5.4): requests of every project, then
 * a project's Analytics tab, then one service.
 */
export default function TeamAnalyticsPage({
    team,
}: {
    team: { name: string };
}) {
    return (
        <>
            <Head title="Analytics" />
            <Page size="wide">
                <TeamAnalytics teamName={team.name} />
            </Page>
        </>
    );
}

TeamAnalyticsPage.layout = {
    breadcrumbs: [{ title: 'Analytics', href: index() }],
};
