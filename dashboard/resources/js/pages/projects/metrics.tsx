import { Head } from '@inertiajs/react';
import { Page } from '@/components/patterns/page';
import { ProjectMetrics } from '@/features/metrics/project-metrics';
import type { ProjectShellProps } from '@/features/projects/types';

type Props = ProjectShellProps & {
    services: App.Data.Metrics.MetricsServiceData[];
};

/** Project Metrics tab (docs/15 §2.3). */
export default function ProjectMetricsPage({
    project,
    environment,
    services,
}: Props) {
    return (
        <>
            <Head title={`Metrics - ${project.name}`} />
            <Page size="wide">
                {environment && (
                    <ProjectMetrics
                        key={environment.id}
                        project={project.id}
                        environment={environment.id}
                        environmentName={environment.name}
                        services={services}
                    />
                )}
            </Page>
        </>
    );
}
