import { Head } from '@inertiajs/react';
import { Page } from '@/components/patterns/page';
import { ProjectAnalytics } from '@/features/analytics/project-analytics';
import { ProjectResources } from '@/features/analytics/project-resources';
import ProjectLayout from '@/features/app-shell/project-layout';

type Props = App.Data.Canvas.ProjectShellPropsData & {
    view: App.Enums.AnalyticsView;
    services: App.Data.Metrics.MetricsServiceData[];
    initialService: string | null;
    readiness: App.Data.Analytics.TrafficReadinessData;
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
    readiness,
    abilities,
}: Props) {
    return (
        <>
            <Head title={`Analytics - ${project.name}`} />
            <Page size="wide">
                {environment &&
                    (view === 'resources' ? (
                        <ProjectResources
                            key={`${environment.id}-resources`}
                            project={project.id}
                            environment={environment.id}
                            environmentName={environment.name}
                            services={services}
                            initialService={initialService}
                        />
                    ) : (
                        <ProjectAnalytics
                            key={`${environment.id}-traffic`}
                            project={project.id}
                            environment={environment.id}
                            environmentName={environment.name}
                            services={services}
                            initialService={initialService}
                            readiness={readiness}
                            canDeploy={abilities.deploy}
                        />
                    ))}
            </Page>
        </>
    );
}

ProjectAnalyticsPage.layout = ProjectLayout;
