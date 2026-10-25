import { Head } from '@inertiajs/react';
import { Page } from '@/components/patterns/page';
import { ProjectAnalytics } from '@/features/analytics/project-analytics';
import type { ProjectShellProps } from '@/features/projects/types';

type Props = ProjectShellProps & {
    services: App.Data.Metrics.MetricsServiceData[];
    initialService: string | null;
};

/** Project Analytics tab (docs/15 §5.4). */
export default function ProjectAnalyticsPage({
    project,
    environment,
    services,
    initialService,
}: Props) {
    return (
        <>
            <Head title={`Analytics - ${project.name}`} />
            <Page size="wide">
                {environment && (
                    <ProjectAnalytics
                        key={environment.id}
                        project={project.id}
                        projectName={project.name}
                        environment={environment.id}
                        environmentName={environment.name}
                        services={services}
                        initialService={initialService}
                    />
                )}
            </Page>
        </>
    );
}
