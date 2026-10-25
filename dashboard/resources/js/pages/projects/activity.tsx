import { Head } from '@inertiajs/react';
import { Page, PageHeader } from '@/components/patterns/page';
import { ActivityFeed } from '@/features/activity/activity-feed';
import type { ProjectShellProps } from '@/features/projects/types';
import { activity } from '@/routes/projects';

type Props = ProjectShellProps & {
    entries: App.Data.Activity.ActivityEntryData[];
    filters: { project: string | null; kind: string | null };
};

/**
 * Project activity: the team feed filtered to this project (deploys,
 * rollbacks, promotions, environment copies, services added).
 */
export default function ProjectActivity({ project, entries, filters }: Props) {
    return (
        <>
            <Head title={`Activity - ${project.name}`} />
            <Page>
                <PageHeader
                    title="Activity"
                    description={`What happened in ${project.name}, newest first.`}
                />
                <ActivityFeed
                    entries={entries}
                    url={activity(project.id).url}
                    filters={filters}
                    teamKinds={false}
                />
            </Page>
        </>
    );
}
