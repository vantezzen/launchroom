import type { StatusState } from '@/components/status';
import { Status } from '@/components/status';

const states: Record<
    App.Enums.Backups.DestinationStatus,
    [StatusState, string]
> = {
    unverified: ['idle', 'Not tested yet'],
    checking: ['progress', 'Testing'],
    ok: ['active', 'Connected'],
    failing: ['failed', 'Unreachable'],
};

export function DestinationStatus({
    status,
}: {
    status: App.Enums.Backups.DestinationStatus;
}) {
    const [state, label] = states[status];

    return <Status state={state} label={label} />;
}
