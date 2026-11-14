import { Head } from '@inertiajs/react';
import { Page } from '@/components/patterns/page';
import { ProjectAnalytics } from '@/features/analytics/project-analytics';
import { ProjectResources } from '@/features/analytics/project-resources';
import type { ProjectShellProps } from '@/features/projects/types';

type Props = ProjectShellProps & {
    view: App.Enums.AnalyticsView;
    services: App.Data.Metrics.MetricsServiceData[];
    initialService: string | null;
};

/**
 * Project Analytics tab (D75): one page with two views, Traffic (docs/15
 * §5.4) and Resources (docs/15 §2.3), each at its own URL.
 */
export default function ProjectAnalyticsPage({
    project,
    environment,
    view,
    services,
    initialService,
}: Props) {
    const View = view === 'resources' ? ProjectResources : ProjectAnalytics;

    return (
        <>
            <Head title={`Analytics - ${project.name}`} />
            <Page size="wide">
                {environment && (
                    <View
                        key={`${environment.id}-${view}`}
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
