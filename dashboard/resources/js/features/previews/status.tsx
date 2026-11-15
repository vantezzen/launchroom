import { Status, previewStatus } from '@/components/status';
import type { StatusState } from '@/components/status';

/**
 * Status of a pull request's preview (docs/06 §5): its lifecycle, refined by
 * the health of its deployments while it runs.
 */
export function previewState(
    status: App.Enums.Previews.PreviewStatus,
    health: string,
): { state: StatusState; label: string } {
    if (status !== 'running') {
        return previewStatus[status];
    }

    if (health === 'failed') {
        return { state: 'failed', label: 'Deploy failed' };
    }

    return health === 'live'
        ? { state: 'active', label: 'Live' }
        : { state: 'progress', label: 'Deploying' };
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
