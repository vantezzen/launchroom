import { LogExplorer } from './log-explorer';

/**
 * Service panel → Logs tab content (direction §9.5): the log explorer
 * scoped to one service (no filter rail, no service column). The panel
 * owns the frame; this fills the height it's given.
 */
export function ServiceLogs({
    project,
    environment,
    service,
}: {
    project: string;
    environment: string;
    service: string;
}) {
    return (
        <LogExplorer
            project={project}
            environment={environment}
            service={service}
            className="h-[calc(100dvh-220px)] min-h-[420px]"
        />
    );
}
