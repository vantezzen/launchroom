import { Link } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { replace, show } from '@/routes/servers';

/**
 * A service that isn't fully placed (docs/02 §6): its stateful server is
 * down (never moved automatically: bring the server back, replace it with
 * the Replace-server wizard, or restore the latest backup on another
 * server), or replicas of a dead server found no other server. Colour plus
 * icon plus words.
 */
export function PlacementIssueBanner({
    issue,
    serviceName,
    backupsHref,
}: {
    issue: App.Data.Canvas.PlacementIssueData;
    serviceName: string;
    backupsHref?: string;
}) {
    const down = issue.kind === 'stateful_server_down';

    return (
        <div
            role="status"
            className="flex items-start gap-3 rounded-lg border border-status-error/40 px-4 py-3"
        >
            <TriangleAlert
                aria-hidden="true"
                className="mt-0.5 size-4 shrink-0 text-status-error"
            />
            <div className="flex flex-col gap-1">
                <span className="type-body font-medium">
                    {down
                        ? `${serviceName} is down with its server`
                        : `${serviceName} is degraded`}
                </span>
                <span className="type-small text-muted-foreground">
                    {issue.message}
                </span>
                <span className="flex flex-wrap gap-3 type-small">
                    {issue.serverId && (
                        <Link
                            href={show(issue.serverId).url}
                            className="underline underline-offset-2"
                        >
                            Open the server
                        </Link>
                    )}
                    {down && issue.serverId && (
                        <Link
                            href={replace(issue.serverId).url}
                            className="underline underline-offset-2"
                        >
                            Replace the server
                        </Link>
                    )}
                    {down && backupsHref && (
                        <Link
                            href={backupsHref}
                            className="underline underline-offset-2"
                        >
                            Restore elsewhere from a backup
                        </Link>
                    )}
                </span>
            </div>
        </div>
    );
}
