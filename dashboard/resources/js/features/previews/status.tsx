import { Status } from '@/components/status';
import type { StatusState } from '@/components/status';

/**
 * Status of a pull request's preview (docs/06 §5): its lifecycle, refined by
 * the health of its deployments while it runs.
 */
export function previewState(
    status: App.Enums.Previews.PreviewStatus,
    health: string,
): { state: StatusState; label: string } {
    switch (status) {
        case 'running':
            if (health === 'failed') {
                return { state: 'failed', label: 'Deploy failed' };
            }

            if (health === 'live') {
                return { state: 'active', label: 'Live' };
            }

            return { state: 'progress', label: 'Deploying' };
        case 'preparing':
            return { state: 'progress', label: 'Preparing' };
        case 'failed':
            return { state: 'failed', label: 'Failed' };
        case 'awaiting_approval':
            return { state: 'warning', label: 'Awaiting approval' };
        case 'queued':
            return { state: 'idle', label: 'Queued' };
        case 'skipped':
            return { state: 'idle', label: 'Skipped' };
        case 'expired':
            return { state: 'idle', label: 'Expired' };
        case 'closed':
            return { state: 'idle', label: 'Closed' };
    }
}

export function PreviewStatus({
    status,
    health,
}: {
    status: App.Enums.Previews.PreviewStatus;
    health: string;
}) {
    const { state, label } = previewState(status, health);

    return <Status state={state} label={label} />;
}

export const strategyLabels: Record<App.Enums.PreviewStrategy, string> = {
    new_empty: 'New empty database',
    clone: 'Clone from another database',
    share: 'Share the base database',
};

export function formatBytes(bytes: number | null): string {
    if (bytes === null) {
        return 'size unknown';
    }

    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    if (bytes < 1024 * 1024 * 1024) {
        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    }

    return `${(bytes / (1024 * 1024 * 1024)).toFixed(2)} GB`;
}
