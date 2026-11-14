import { Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { show as deploymentShow } from '@/routes/deployments';
import { logs as projectLogs } from '@/routes/projects';
import { LogExplorer } from './log-explorer';

/**
 * Service panel → Logs tab content (direction §9.5): the service's runtime
 * logs, the log explorer scoped to one service (no filter rail, no service
 * column). Above it, the two other places logs live: "Build logs" (the
 * latest deployment's steps) and "Open in Logs" (the environment's log
 * explorer filtered to this service, every other service one click away).
 *
 * It starts live by itself when nothing is stored to show: right away when
 * the environment doesn't keep runtime logs, else when the range is empty
 * (docs/08 §12). The panel owns the frame; this fills the height it's given.
 */
export function ServiceLogs({
    project,
    environment,
    service,
    slug,
    deployment,
    running,
}: {
    project: string;
    environment: string;
    service: string;
    /** For the project Logs page's `?service=`. */
    slug: string;
    /** The latest deployment, for "Build logs". */
    deployment: string | null;
    /** Something runs that a live tail can follow. */
    running: boolean;
}) {
    const keepsLogs =
        usePage<{
            environment?: App.Data.Canvas.EnvironmentData | null;
        }>().props.environment?.keepsRuntimeLogs ?? false;

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
                <h3 className="type-label">Runtime logs</h3>
                <div className="ml-auto flex items-center gap-4 type-small">
                    {deployment && (
                        <Link
                            href={
                                deploymentShow({
                                    project,
                                    environment,
                                    deployment,
                                }).url
                            }
                            className="inline-flex items-center gap-0.5 rounded-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            Build logs
                            <ChevronRight
                                aria-hidden="true"
                                className="size-3.5"
                            />
                        </Link>
                    )}
                    <Link
                        href={
                            projectLogs(
                                { project, environment },
                                { query: { service: slug } },
                            ).url
                        }
                        className="inline-flex items-center gap-0.5 rounded-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        Open in Logs
                        <ChevronRight aria-hidden="true" className="size-3.5" />
                    </Link>
                </div>
            </div>
            <LogExplorer
                project={project}
                environment={environment}
                service={service}
                // `?q=`, `?range=`, `?from=&to=` from a chart or an alert.
                syncUrl
                autoLive={
                    running ? (keepsLogs ? 'when-empty' : 'now') : undefined
                }
                className="h-[calc(100dvh-252px)] min-h-[420px]"
            />
        </div>
    );
}
