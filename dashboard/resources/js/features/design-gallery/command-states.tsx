import type { ReactNode } from 'react';
import type { Run, TabData } from '@/features/commands/api';
import { CommandsView } from '@/features/commands/commands-tab';
import { RunView } from '@/features/commands/run-detail';

/**
 * Every state of the Commands tab (docs/08 §22, D105) with sample data, for
 * review and screenshots. Run and Cancel do nothing here.
 */

type Line = App.Data.Commands.CommandOutputLineData;

const minutesAgo = (minutes: number): string =>
    new Date(Date.now() - minutes * 60_000).toISOString();

const laravel: App.Data.Commands.CommandCatalogueData = {
    framework: 'Laravel',
    awaitingBuild: false,
    commands: [
        {
            name: 'about',
            label: 'Show app details',
            description:
                'Shows the Laravel and PHP versions, the environment and the drivers the app uses.',
            argv: ['php', 'artisan', 'about'],
            danger: false,
            source: 'framework',
        },
        {
            name: 'migrate-status',
            label: 'Show migration status',
            description: 'Lists every migration and whether it has run.',
            argv: ['php', 'artisan', 'migrate:status'],
            danger: false,
            source: 'framework',
        },
        {
            name: 'migrate',
            label: 'Run migrations',
            description: 'Runs the migrations that haven’t run yet.',
            argv: ['php', 'artisan', 'migrate', '--force'],
            danger: true,
            source: 'framework',
        },
        {
            name: 'queue-restart',
            label: 'Restart queue workers',
            description:
                'Tells the queue workers to restart once they finish their current job.',
            argv: ['php', 'artisan', 'queue:restart'],
            danger: false,
            source: 'framework',
        },
        {
            name: 'reindex',
            label: 'reindex',
            description: 'Rebuilds the search index from the posts table.',
            argv: ['php', 'artisan', 'scout:import', 'App\\Models\\Post'],
            danger: false,
            source: 'config',
        },
    ],
};

function run(patch: Partial<Run>): Run {
    return {
        id: 'run_01JDEMO0000000000000000',
        status: 'succeeded',
        finished: true,
        argv: ['php', 'artisan', 'migrate:status'],
        name: 'migrate-status',
        exitCode: 0,
        error: null,
        timeoutSeconds: 600,
        outputTruncated: false,
        service: 'svc_web',
        environment: 'env_production',
        deployment: 'dpl_demo',
        server: 'fra-1',
        ranBy: 'Ana Weber',
        viaApi: false,
        cancelledBy: null,
        createdAt: minutesAgo(4),
        startedAt: minutesAgo(4),
        finishedAt: minutesAgo(3.9),
        durationMs: 1840,
        ...patch,
    };
}

const runs: Run[] = [
    run({
        id: 'run_a1',
        status: 'running',
        finished: false,
        argv: ['php', 'artisan', 'migrate', '--force'],
        name: 'migrate',
        exitCode: null,
        createdAt: minutesAgo(0.5),
        startedAt: minutesAgo(0.5),
        finishedAt: null,
        durationMs: null,
    }),
    run({ id: 'run_a2' }),
    run({
        id: 'run_a3',
        status: 'failed',
        exitCode: 1,
        argv: ['php', 'artisan', 'db:seed', '--class=DemoSeeder', '--force'],
        name: null,
        ranBy: 'Jonas Kraft',
        viaApi: true,
        createdAt: minutesAgo(52),
        durationMs: 3200,
    }),
    run({
        id: 'run_a4',
        status: 'timed_out',
        exitCode: null,
        argv: ['php', 'artisan', 'scout:import', 'App\\Models\\Post'],
        name: 'reindex',
        createdAt: minutesAgo(180),
        durationMs: 600_000,
    }),
    run({
        id: 'run_a5',
        status: 'cancelled',
        exitCode: null,
        argv: ['php', 'artisan', 'queue:work', '--once'],
        name: null,
        cancelledBy: 'Ana Weber',
        createdAt: minutesAgo(1440),
        durationMs: 12_000,
    }),
    run({
        id: 'run_a6',
        status: 'error',
        exitCode: null,
        argv: ['ls', '-la', 'storage'],
        name: null,
        error: 'fra-1 went offline before it started.',
        createdAt: minutesAgo(2880),
        durationMs: null,
    }),
];

function tab(patch: Partial<TabData>): TabData {
    return {
        catalogue: laravel,
        runs,
        moreRuns: null,
        blocked: null,
        environment: 'production',
        production: true,
        defaultTimeout: 600,
        timeouts: [60, 600, 1800, 3600],
        ...patch,
    };
}

const now = new Date().toISOString();
const output = (texts: [string, string?][]): Line[] =>
    texts.map(([text, stream = 'stdout'], index) => ({
        number: index + 1,
        time: now,
        stream,
        text,
    }));

const migrateOutput = output([
    ['Starting one-off container', 'system'],
    [''],
    ['   INFO  Running migrations.'],
    [''],
    [
        '  2026_11_02_101500_create_invoices_table \u001b[90m.........\u001b[39m \u001b[32m14.2ms DONE\u001b[39m',
    ],
    [
        '  2026_11_12_083000_add_paid_at_to_invoices \u001b[90m.......\u001b[39m \u001b[32m6.8ms DONE\u001b[39m',
    ],
    [
        '  2026_11_19_164200_create_refunds_table \u001b[90m..........\u001b[39m \u001b[33mRUNNING\u001b[39m',
    ],
]);

const statusOutput = output([
    ['Starting one-off container', 'system'],
    [''],
    [
        '  Migration name \u001b[90m.............................................\u001b[39m Batch / Status',
    ],
    [
        '  0001_01_01_000000_create_users_table \u001b[90m.....................\u001b[39m [1] \u001b[32mRan\u001b[39m',
    ],
    [
        '  0001_01_01_000001_create_cache_table \u001b[90m.....................\u001b[39m [1] \u001b[32mRan\u001b[39m',
    ],
    [
        '  0001_01_01_000002_create_jobs_table \u001b[90m......................\u001b[39m [1] \u001b[32mRan\u001b[39m',
    ],
    [
        '  2026_11_02_101500_create_invoices_table \u001b[90m..................\u001b[39m [2] \u001b[32mRan\u001b[39m',
    ],
    [
        '  2026_11_19_164200_create_refunds_table \u001b[90m...................\u001b[39m \u001b[33mPending\u001b[39m',
    ],
    ['Exited with code 0', 'system'],
]);

const failedOutput = output([
    ['Starting one-off container', 'system'],
    ['   INFO  Seeding database.'],
    [''],
    [
        '  Database\\Seeders\\DemoSeeder \u001b[90m......................\u001b[39m RUNNING',
    ],
    ['', 'stderr'],
    ['   Illuminate\\Database\\UniqueConstraintViolationException', 'stderr'],
    [
        '  SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "users_email_unique"',
        'stderr',
    ],
    ['  DETAIL:  Key (email)=(demo@example.com) already exists.', 'stderr'],
    ['Exited with code 1', 'system'],
]);

const noop = async () => undefined;

function Frame({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-2">
            <p className="type-small text-muted-foreground">{label}</p>
            <div className="max-w-[680px] rounded-2xl border bg-card p-6 shadow-float">
                {children}
            </div>
        </div>
    );
}

function Detail({
    label,
    run: value,
    lines,
}: {
    label: string;
    run: Run;
    lines: Line[];
}) {
    return (
        <Frame label={label}>
            <div className="flex flex-col gap-6">
                <RunView
                    run={value}
                    lines={lines}
                    data={tab({})}
                    onRunAgain={noop}
                    onCancelled={() => undefined}
                />
            </div>
        </Frame>
    );
}

export default function CommandStates() {
    return (
        <div className="grid gap-10">
            <Frame label="A Laravel app: its commands, one from launchroom.json, recent runs">
                <CommandsView
                    data={tab({})}
                    logo="laravel"
                    onRun={noop}
                    onOpen={() => undefined}
                />
            </Frame>
            <Frame label="Never deployed">
                <CommandsView
                    data={tab({
                        runs: [],
                        blocked:
                            'Commands run in the live version. Deploy this service first.',
                    })}
                    logo="laravel"
                    onRun={noop}
                    onOpen={() => undefined}
                />
            </Frame>
            <Frame label="An image service without a framework, no runs yet">
                <CommandsView
                    data={tab({
                        catalogue: {
                            framework: null,
                            commands: [],
                            awaitingBuild: false,
                        },
                        runs: [],
                    })}
                    logo="docker"
                    onRun={noop}
                    onOpen={() => undefined}
                />
            </Frame>
            <Frame label="A build from before framework commands; a server that needs an update">
                <CommandsView
                    data={tab({
                        catalogue: {
                            framework: 'Django',
                            commands: [],
                            awaitingBuild: true,
                        },
                        runs: runs.slice(1, 3),
                        blocked:
                            'Update launchroom on fra-1 to run commands there.',
                    })}
                    logo="django"
                    onRun={noop}
                    onOpen={() => undefined}
                />
            </Frame>
            <Detail
                label="Queued"
                run={run({ ...runs[0], status: 'queued', startedAt: null })}
                lines={[]}
            />
            <Detail label="Running" run={runs[0]} lines={migrateOutput} />
            <Detail label="Succeeded" run={runs[1]} lines={statusOutput} />
            <Detail label="Failed" run={runs[2]} lines={failedOutput} />
            <Detail
                label="Timed out"
                run={runs[3]}
                lines={output([
                    ['Starting one-off container', 'system'],
                    ['Importing [App\\Models\\Post] 0/48210'],
                    [
                        'Output stopped after 5 MiB. The command keeps running.',
                        'system',
                    ],
                ])}
            />
            <Detail
                label="Cancelled"
                run={runs[4]}
                lines={output([
                    ['Starting one-off container', 'system'],
                    ['Processing jobs from the [default] queue.'],
                ])}
            />
            <Detail label="Couldn’t run" run={runs[5]} lines={[]} />
        </div>
    );
}
