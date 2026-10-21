import { Head } from '@inertiajs/react';
import { Page, PageHeader } from '@/components/patterns/page';
import { ActivityFeed } from '@/features/activity/activity-feed';
import { AuditVerificationLine } from '@/features/security/audit-verification-line';
import { index } from '@/routes/activity';

type Props = {
    projects: App.Data.Canvas.ProjectLinkData[];
    entries: App.Data.Activity.ActivityEntryData[];
    filters: { project: string | null; kind: string | null };
    auditVerification: App.Data.Security.AuditVerificationData | null;
};

/**
 * Team activity (sidebar, direction §5.4): deploys, rollbacks, promotions
 * and changes across the team's projects, grouped by day, filterable by
 * kind and project.
 */
export default function TeamActivity({
    projects,
    entries,
    filters,
    auditVerification,
}: Props) {
    return (
        <>
            <Head title="Activity" />
            <Page>
                <PageHeader
                    title="Activity"
                    description="Deploys, promotions and changes across your projects."
                    actions={
                        <AuditVerificationLine
                            verification={auditVerification}
                        />
                    }
                />
                <ActivityFeed
                    entries={entries}
                    url={index().url}
                    filters={filters}
                    projects={projects}
                />
            </Page>
        </>
    );
}

TeamActivity.layout = {
    breadcrumbs: [{ title: 'Activity', href: index() }],
};
