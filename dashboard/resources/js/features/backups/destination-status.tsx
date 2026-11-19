import type { StatusState } from '@/components/status';
import { Status } from '@/components/status';
import type { Destination } from './page-types';

const states: Record<
    App.Enums.Backups.DestinationStatus,
    [StatusState, string]
> = {
    unverified: ['idle', 'Not tested yet'],
    checking: ['progress', 'Testing the connection'],
    ok: ['active', 'Connected'],
    failing: ['failed', 'Can’t connect'],
};

/** A failing test, by cause, with what fixes it. */
export const failures: Record<
    NonNullable<Destination['errorKind']>,
    { label: string; fix: string }
> = {
    wrong_credentials: {
        label: 'Wrong keys',
        fix: 'Check the access key and its permission to read and write this bucket.',
    },
    wrong_repository_password: {
        label: 'Wrong repository password',
        fix: 'This folder holds backups with another repository password. Enter the one from the recovery kit, or use another folder.',
    },
    bucket_missing: {
        label: 'Bucket not found',
        fix: 'Create the bucket first, or check its name and the endpoint’s region.',
    },
    unreachable: {
        label: 'Unreachable',
        fix: 'Check the endpoint URL. Your servers need to reach it over HTTPS.',
    },
    other: {
        label: 'Can’t connect',
        fix: 'Check the endpoint, bucket and keys.',
    },
};

export function DestinationStatus({
    destination,
}: {
    destination: Pick<Destination, 'status' | 'errorKind'>;
}) {
    const [state, label] = states[destination.status];
    const failure =
        destination.status === 'failing' && destination.errorKind !== null
            ? failures[destination.errorKind]
            : null;

    return <Status state={state} label={failure?.label ?? label} />;
}
