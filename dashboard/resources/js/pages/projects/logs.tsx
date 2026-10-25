import { Head } from '@inertiajs/react';
import { Page } from '@/components/patterns/page';
import { LogExplorer } from '@/features/project-logs/log-explorer';
import type { ProjectShellProps } from '@/features/projects/types';

type Props = ProjectShellProps & {
    services: { id: string; name: string; slug: string }[];
};

/**
 * Project Logs tab (direction §9.8): the log explorer, full-bleed under the
 * project top bar. `?service=svc_…` (from a deployment's "Runtime logs"
 * card) starts filtered to that service; `?q=` carries a query and
 * `?scope=project` searches every environment of the project.
 */
export default function ProjectLogs({ project, environment, services }: Props) {
    const params = new URLSearchParams(
        typeof window === 'undefined' ? '' : window.location.search,
    );
    // `?service=` takes the public id or the slug (the panel links by slug).
    const wanted = params.get('service');
    const service = services.find(
        (item) => item.id === wanted || item.slug === wanted,
    );
    const initial =
        params.get('q') ?? (service ? `service:${service.name}` : '');

    return (
        <>
            <Head title={`Logs - ${project.name}`} />
            <Page size="full">
                {environment && (
                    <LogExplorer
                        key={environment.id}
                        project={project.id}
                        environment={environment.id}
                        environmentName={environment.name}
                        initialQuery={initial}
                        syncUrl
                    />
                )}
            </Page>
        </>
    );
}
