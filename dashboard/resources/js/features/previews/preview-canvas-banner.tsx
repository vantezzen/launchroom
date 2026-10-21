import { Link } from '@inertiajs/react';
import { GitPullRequest, TriangleAlert } from 'lucide-react';
import { TimeAgo } from '@/components/time-ago';
import { previews } from '@/routes/projects';
import { PreviewStatus } from './status';

/**
 * The pull request behind a preview environment's canvas.
 */
export function PreviewCanvasBanner({
    project,
    preview,
}: {
    project: string;
    preview: App.Data.Previews.PreviewCanvasData;
}) {
    return (
        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 border-b px-3 py-2 md:px-4">
            <span className="inline-flex min-w-0 items-center gap-1.5 type-label">
                <GitPullRequest
                    aria-hidden="true"
                    className="size-3.5 text-muted-foreground"
                />
                <span className="tabular-nums">#{preview.number}</span>
                <span className="truncate">{preview.title}</span>
            </span>
            <span className="type-mono-small text-muted-foreground">
                {preview.headRef}
            </span>
            <PreviewStatus status={preview.status} health={preview.health} />
            <span className="type-small text-muted-foreground">
                copied from {preview.baseName}
            </span>
            {preview.edgeBadges.map((badge) => (
                <span
                    key={badge.serviceId}
                    className={
                        badge.tone === 'danger'
                            ? 'inline-flex items-center gap-1 type-small text-status-error'
                            : 'type-small text-muted-foreground'
                    }
                >
                    {badge.tone === 'danger' && (
                        <TriangleAlert aria-hidden="true" className="size-3" />
                    )}
                    {badge.serviceName} {badge.label}
                </span>
            ))}
            {preview.secretsWithheld && (
                <span className="type-small text-muted-foreground">
                    no secrets (fork)
                </span>
            )}
            {preview.expiresAt && (
                <span className="type-small text-muted-foreground">
                    removed <TimeAgo value={preview.expiresAt} /> without new
                    commits
                </span>
            )}
            <Link
                href={previews(project)}
                className="ml-auto type-label underline-offset-4 hover:underline"
            >
                All previews
            </Link>
        </div>
    );
}
