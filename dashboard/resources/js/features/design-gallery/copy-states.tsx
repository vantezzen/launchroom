import { CopyRunRow } from '@/features/data-transfer/copy-run';
import type { CopyRun, CopyStep } from '@/features/data-transfer/api';

/**
 * Copies between environments (D110, docs/14 §14.2) with sample data: a
 * copy in every step, done, failed with the snapshot to restore, and
 * cancelled. Buttons do nothing.
 */

const ago = (minutes: number) =>
    new Date(Date.now() - minutes * 60_000).toISOString();

type Key = CopyStep['key'];
type State = CopyStep['state'];

const labels: Record<Key, string> = {
    export: 'Copy the data from production',
    snapshot: 'Safety snapshot of staging',
    stop: 'Stop api and worker',
    import: 'Load the data into staging',
    start: 'Start api and worker again',
};

function steps(
    states: [State, State, State, State, State],
    details: Partial<Record<Key, string>> = {},
): CopyStep[] {
    return (Object.keys(labels) as Key[]).map((key, index) => ({
        key,
        label: labels[key],
        state: states[index],
        detail: details[key] ?? null,
    }));
}

function run(id: string, overrides: Partial<CopyRun>): CopyRun {
    return {
        id,
        status: 'exporting',
        subject: 'database',
        sourceEnvironmentName: 'production',
        sourceEnvironmentKind: 'production',
        targetEnvironmentName: 'staging',
        mountPath: null,
        point: 'live',
        snapshotAt: null,
        sizeBytes: 1_240_000_000,
        steps: [],
        error: null,
        actorName: 'Mara',
        createdAt: ago(3),
        finishedAt: null,
        cancellable: false,
        safetySnapshot: null,
        warnings: [],
        ...overrides,
    };
}

const samples: { title: string; run: CopyRun }[] = [
    {
        title: 'Copying the source (cancellable)',
        run: run('cpy_1', {
            cancellable: true,
            steps: steps(
                ['current', 'waiting', 'waiting', 'waiting', 'waiting'],
                {
                    export: '412 MB so far',
                },
            ),
        }),
    },
    {
        title: 'Waiting for the apps to stop',
        run: run('cpy_2', {
            status: 'importing',
            cancellable: true,
            steps: steps(['done', 'done', 'current', 'waiting', 'waiting'], {
                export: 'Live data, 1.2 GB',
                snapshot: 'Snapshot 3f9a1c2e',
                stop: 'Waiting for worker to stop',
            }),
        }),
    },
    {
        title: 'Loading the data',
        run: run('cpy_3', {
            status: 'importing',
            steps: steps(['done', 'done', 'done', 'current', 'waiting'], {
                export: 'Live data, 1.2 GB',
                snapshot: 'Snapshot 3f9a1c2e',
                import: 'Restoring: 640 MB read',
            }),
        }),
    },
    {
        title: 'Copied',
        run: run('cpy_4', {
            status: 'succeeded',
            point: 'latest_backup',
            createdAt: ago(40),
            finishedAt: ago(36),
            steps: steps(['done', 'done', 'done', 'done', 'done'], {
                export: 'Backup, 1.2 GB',
                snapshot: 'Snapshot 3f9a1c2e',
                import: '14 tables, 1.3 GB',
            }),
        }),
    },
    {
        title: 'Loading failed: the snapshot from before is offered',
        run: run('cpy_5', {
            status: 'failed',
            createdAt: ago(90),
            safetySnapshot: 'a1b2c3d4e5f6',
            error: 'Loading the data failed: pg_restore failed: relation "events" already exists',
            steps: [
                ...steps(['done', 'done', 'done', 'failed', 'done'], {
                    export: 'Live data, 1.2 GB',
                    snapshot: 'Snapshot 3f9a1c2e',
                }),
            ].map((step) =>
                step.key === 'start'
                    ? {
                          ...step,
                          detail: 'Started again after the import ended',
                      }
                    : step,
            ),
        }),
    },
    {
        title: 'Copying the source failed: nothing changed',
        run: run('cpy_6', {
            status: 'failed',
            createdAt: ago(200),
            error: 'Copying the data out of production failed: pg_dump failed: connection refused. staging is unchanged.',
            steps: steps([
                'failed',
                'waiting',
                'waiting',
                'waiting',
                'waiting',
            ]),
        }),
    },
    {
        title: 'Cancelled, files of a volume',
        run: run('cpy_7', {
            status: 'cancelled',
            subject: 'volume',
            mountPath: '/app/storage',
            sourceEnvironmentName: 'qa',
            sourceEnvironmentKind: 'persistent',
            createdAt: ago(600),
            steps: [
                {
                    key: 'export',
                    label: 'Copy the data from qa',
                    state: 'cancelled',
                    detail: null,
                },
                {
                    key: 'snapshot',
                    label: 'Safety snapshot of staging',
                    state: 'waiting',
                    detail: null,
                },
                {
                    key: 'stop',
                    label: 'Stop api',
                    state: 'waiting',
                    detail: null,
                },
                {
                    key: 'import',
                    label: 'Load the data into staging',
                    state: 'waiting',
                    detail: null,
                },
                {
                    key: 'start',
                    label: 'Start api again',
                    state: 'waiting',
                    detail: null,
                },
            ],
        }),
    },
];

export function CopyStatesGallery() {
    return (
        <div className="grid gap-6 lg:grid-cols-2">
            {samples.map((sample) => (
                <div key={sample.run.id} className="flex flex-col gap-2">
                    <p className="type-small text-muted-foreground">
                        {sample.title}
                    </p>
                    <ul className="flex flex-col divide-y overflow-hidden rounded-xl border bg-card shadow-card">
                        <CopyRunRow
                            run={sample.run}
                            cancelling={false}
                            canRestore
                            onCancel={() => undefined}
                            onRestore={() => undefined}
                        />
                    </ul>
                </div>
            ))}
        </div>
    );
}
