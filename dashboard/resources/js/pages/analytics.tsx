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
    readiness,
}: {
    team: { name: string };
    readiness: App.Data.Analytics.TrafficReadinessData;
}) {
    return (
        <>
            <Head title="Analytics" />
            <Page size="wide">
                <TeamAnalytics teamName={team.name} readiness={readiness} />
            </Page>
        </>
    );
}

TeamAnalyticsPage.layout = {
    breadcrumbs: [{ title: 'Analytics', href: index() }],
};
