import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { formatBytes } from '@/lib/format';
import { AttentionChip } from '@/components/attention-chip';
import { REQUEST_CLASS } from '@/components/charts/request-breakdown';
import { DEVICE_LABEL, DeviceImage } from '@/components/devices';
import type { DeviceKind, DeviceStatus } from '@/components/devices';
import { ServerMap } from '@/components/map';
import type { MapLink, MapLocation } from '@/components/map';
import { formatWindow, RANGE_SETS, rangeOptions } from '@/lib/observe-scope';
import { RunStrip } from '@/components/steps/run-strip';
import type { Run } from '@/components/steps/run-strip';
import { StepChip, StepList } from '@/components/steps/step-list';
import type { Step } from '@/components/steps/step-list';
import { Donut } from '@/components/charts/donut';
import { Histogram } from '@/components/charts/histogram';
import { Meter, UsageMeter } from '@/components/charts/usage-meter';
import { MiniBars } from '@/components/charts/mini-bars';
import { Sparkline } from '@/components/charts/sparkline';
import { StackedBars } from '@/components/charts/stacked-bars';
import { StatTile } from '@/components/charts/stat-tile';
import { TimeRangeSelect } from '@/components/charts/time-range-select';
import { TimeSeriesChart } from '@/components/charts/time-series-chart';
import type { MiniBar } from '@/components/charts/mini-bars';
import type { TimeSeries } from '@/components/charts/time-series-chart';
import { bucketEntries, countFacets } from '@/features/log-explorer/facets';
import { FilterRail } from '@/features/log-explorer/filter-rail';
import { LogDetail } from '@/features/log-explorer/log-detail';
import { LogTable } from '@/features/log-explorer/log-table';
import {
    matchesQuery,
    parseLogQuery,
    toggleFilter,
} from '@/features/log-explorer/query';
import { QueryBar } from '@/features/log-explorer/query-bar';
import type { LogEntry, LogLevel } from '@/features/log-explorer/types';
import type { QueryKey } from '@/features/log-explorer/query';
import { TraceGallery } from '@/features/request-trace/trace-gallery';

/*
 * Showcase of the visual primitives with fixed sample data
 * (deterministic: seeded random, fixed clock) so screenshots are stable.
 * Rendered on /dev/design and on its own at /dev/design/visuals.
 */

const NOW = Date.UTC(2026, 9, 19, 14, 0, 0);
const MINUTE = 60_000;

function seeded(seed: number) {
    let state = seed >>> 0;

    return () => {
        state = (state + 0x6d2b79f5) >>> 0;
        let t = state;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);

        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

function walk(
    seed: number,
    count: number,
    base: number,
    spread: number,
    lo = 0,
    hi = 100,
): number[] {
    const random = seeded(seed);
    let value = base;

    return Array.from({ length: count }, () => {
        value += (random() - 0.5) * spread;
        value += (base - value) * 0.15;

        return Math.round(Math.min(hi, Math.max(lo, value)) * 10) / 10;
    });
}

const HOUR_TIMES = Array.from(
    { length: 60 },
    (_, i) => NOW - (59 - i) * MINUTE,
);

function Section({
    id,
    title,
    description,
    children,
}: {
    id: string;
    title: string;
    description: string;
    children: ReactNode;
}) {
    return (
        <section id={id} className="flex scroll-mt-8 flex-col gap-4">
            <div className="flex flex-col gap-1">
                <h2 className="type-heading">{title}</h2>
                <p className="type-body text-muted-foreground">{description}</p>
            </div>
            {children}
        </section>
    );
}

function Panel({
    children,
    className = '',
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={`rounded-[14px] border bg-card p-5 shadow-[0_1px_2px_rgb(0_0_0/0.04)] dark:shadow-none ${className}`}
        >
            {children}
        </div>
    );
}

function Label({ children }: { children: ReactNode }) {
    return <p className="mb-3 type-label text-muted-foreground">{children}</p>;
}

const KINDS: DeviceKind[] = [
    'cloud',
    'dedicated',
    'vm',
    'compact',
    'laptop',
    'minipc',
    'tower',
    'nas',
    'sbc',
    'unknown',
];
const STATUSES: DeviceStatus[] = [
    'online',
    'busy',
    'unreachable',
    'offline',
    'pending',
];

function DevicesSection() {
    return (
        <Section
            id="devices"
            title="Device art"
            description="Maintainer renders on one canvas, baseline and shadow; status applied by the component."
        >
            <div className="grid grid-cols-2 gap-4 md:grid-cols-5">
                {KINDS.map((kind) => (
                    <div
                        key={kind}
                        className="flex flex-col items-center gap-2 rounded-[14px] border bg-(--recessed) p-3"
                    >
                        <DeviceImage
                            device={kind}
                            size={176}
                            status={kind === 'unknown' ? 'pending' : 'online'}
                        />
                        <span className="type-small text-muted-foreground">
                            {DEVICE_LABEL[kind]}{' '}
                            <span className="font-mono text-muted-foreground">
                                {kind}
                            </span>
                        </span>
                    </div>
                ))}
            </div>
            <div className="grid grid-cols-2 gap-4 md:grid-cols-5">
                {STATUSES.map((status) => (
                    <div
                        key={status}
                        className="flex flex-col items-center gap-2 rounded-[14px] border bg-(--recessed) p-3"
                    >
                        <DeviceImage
                            device="minipc"
                            size={176}
                            status={status}
                        />
                        <span className="type-small text-muted-foreground capitalize">
                            {status}
                        </span>
                    </div>
                ))}
            </div>
            <div className="grid grid-cols-2 gap-4 md:grid-cols-3">
                {(
                    [
                        ['laptop', 'busy'],
                        ['tower', 'unreachable'],
                        ['nas', 'busy'],
                    ] as const
                ).map(([kind, status]) => (
                    <div
                        key={kind}
                        className="flex flex-col items-center gap-2 rounded-[14px] border bg-(--recessed) p-3"
                    >
                        <DeviceImage device={kind} size={300} status={status} />
                        <span className="type-small text-muted-foreground">
                            {DEVICE_LABEL[kind]}, {status}
                        </span>
                    </div>
                ))}
            </div>
            <Panel className="flex flex-wrap items-end gap-8 overflow-x-auto">
                {[64, 120, 200, 300].map((size) => (
                    <div
                        key={size}
                        className="flex flex-col items-center gap-2"
                    >
                        <DeviceImage device="dedicated" size={size} />
                        <span className="type-small text-muted-foreground">
                            {size} px
                        </span>
                    </div>
                ))}
            </Panel>
        </Section>
    );
}

function MetricsSection() {
    const [range, setRange] =
        useState<(typeof RANGE_SETS.resources)[number]>('1h');
    const cpu = useMemo(() => walk(1, 60, 34, 14), []);
    const memory = useMemo(() => walk(2, 60, 64, 3), []);
    const disk = useMemo(() => walk(3, 60, 41, 0.4), []);
    const network = useMemo(() => walk(4, 60, 18, 16, 0, 200), []);
    const bars: MiniBar[] = useMemo(() => {
        const random = seeded(9);

        return Array.from({ length: 14 }, (_, i) => {
            const value = Math.floor(random() * 6);

            return {
                key: String(i),
                label: `Oct ${6 + i}`,
                value: i === 4 ? 0 : value,
                failed: i === 9 ? 1 : 0,
                current: i === 13,
            };
        });
    }, []);
    const runs: Run[] = Array.from({ length: 30 }, (_, i) => ({
        key: String(i),
        status: i === 11 ? 'failed' : i === 22 ? 'none' : 'ok',
        label: `Oct ${i - 10 < 1 ? 20 + i : i - 10}: ${i === 11 ? 'failed' : 'succeeded'}`,
    }));

    return (
        <Section
            id="metrics"
            title="Metrics"
            description="Stat tiles, sparklines, mini bars, meters. One accent colour; status colours only with meaning."
        >
            <div className="flex items-center justify-between">
                <span className="type-small text-muted-foreground">
                    TimeRangeSelect
                </span>
                <TimeRangeSelect
                    value={range}
                    onChange={setRange}
                    options={rangeOptions(RANGE_SETS.resources)}
                />
            </div>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile
                    label="CPU"
                    value="34"
                    unit="%"
                    hint="avg 29%"
                    series={{
                        values: cpu,
                        times: HOUR_TIMES,
                        min: 0,
                        max: 100,
                        format: (v) => `${v}%`,
                    }}
                />
                <StatTile
                    label="Memory"
                    value="5.1"
                    unit="/ 8 GB"
                    delta={{ label: '+0.4 GB' }}
                    series={{
                        values: memory,
                        times: HOUR_TIMES,
                        min: 0,
                        max: 100,
                        format: (v) => `${v}%`,
                    }}
                />
                <StatTile
                    label="Disk"
                    value="41"
                    unit="%"
                    hint="66 GB free"
                    series={{
                        values: disk,
                        times: HOUR_TIMES,
                        min: 0,
                        max: 100,
                        format: (v) => `${v}%`,
                    }}
                />
                <StatTile
                    label="Errors"
                    value="3"
                    unit="/ hour"
                    delta={{ label: '−12', tone: 'good' }}
                    hint="No metrics yet: value only"
                />
            </div>
            <div className="grid gap-4 md:grid-cols-3">
                <Panel>
                    <Label>Sparkline: inline, 24 px</Label>
                    <div className="flex items-center gap-3">
                        <span className="w-14 type-small text-muted-foreground">
                            Network
                        </span>
                        <Sparkline
                            values={network}
                            label="Network, last hour"
                            className="flex-1"
                        />
                        <span className="w-16 text-right type-small tabular-nums">
                            18 MB/s
                        </span>
                    </div>
                    <div className="mt-3 flex items-center gap-3">
                        <span className="w-14 type-small text-muted-foreground">
                            No data
                        </span>
                        <Sparkline
                            values={[]}
                            label="Empty"
                            className="flex-1"
                        />
                        <span className="w-16 text-right type-small text-muted-foreground tabular-nums">
                            –
                        </span>
                    </div>
                </Panel>
                <Panel>
                    <Label>MiniBars: 14 days of deploys</Label>
                    <MiniBars bars={bars} label="Deploys, last 14 days" />
                    <Label>
                        <span className="mt-4 block">
                            RunStrip: 30 days of backups
                        </span>
                    </Label>
                    <RunStrip runs={runs} label="Backups, last 30 days" />
                </Panel>
                <Panel className="flex flex-col gap-4">
                    <Label>Meter: single value</Label>
                    <div className="flex flex-col gap-1.5">
                        <div className="flex justify-between type-small">
                            <span className="text-muted-foreground">Disk</span>
                            <span className="tabular-nums">41%</span>
                        </div>
                        <Meter
                            value={41}
                            label="Disk used"
                            warnAt={85}
                            criticalAt={95}
                        />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <div className="flex justify-between type-small">
                            <span className="text-muted-foreground">
                                Volume (past warning)
                            </span>
                            <span className="tabular-nums">88%</span>
                        </div>
                        <Meter
                            value={88}
                            label="Volume used"
                            warnAt={85}
                            criticalAt={95}
                        />
                    </div>
                </Panel>
            </div>
        </Section>
    );
}

function BreakdownSection() {
    const projects = [
        { key: 'shop', label: 'shop', value: 2.1e9, slot: 1 },
        { key: 'blog', label: 'blog', value: 1.2e9, slot: 2 },
        { key: 'analytics', label: 'analytics', value: 0.5e9, slot: 3 },
        { key: 'crm', label: 'crm', value: 0.3e9, slot: 4 },
        { key: 'wiki', label: 'wiki', value: 0.2e9, slot: 5 },
        { key: 'status', label: 'status', value: 0.1e9, slot: 6 },
    ];

    return (
        <Section
            id="breakdowns"
            title="Breakdowns"
            description="Multi-colour only where comparing is the point: top four in their slot colour, the rest grey."
        >
            <div className="grid gap-4 md:grid-cols-3">
                <Panel className="md:col-span-2">
                    <Label>UsageMeter: memory by project (8 GB)</Label>
                    <UsageMeter
                        items={projects}
                        total={8e9}
                        label="Memory by project"
                        format={formatBytes}
                    />
                    <div className="mt-6">
                        <Label>UsageMeter: one item (signal)</Label>
                        <UsageMeter
                            items={[
                                { key: 'used', label: 'Used', value: 3.4e9 },
                            ]}
                            total={8e9}
                            label="Memory used"
                        />
                    </div>
                </Panel>
                <Panel className="flex flex-col gap-6">
                    <div>
                        <Label>Donut: backups by kind</Label>
                        <Donut
                            items={[
                                {
                                    key: 'pg',
                                    label: 'Postgres',
                                    value: 14.2e9,
                                    slot: 1,
                                },
                                {
                                    key: 'vol',
                                    label: 'Volumes',
                                    value: 7.1e9,
                                    slot: 2,
                                },
                                {
                                    key: 'cp',
                                    label: 'Control plane',
                                    value: 2.5e9,
                                    slot: 7,
                                },
                            ]}
                            total={40e9}
                            label="Backup storage by kind"
                            format={formatBytes}
                            centerValue="23.8 GB"
                            centerLabel="of 40 GB"
                        />
                    </div>
                    <div>
                        <Label>Donut: single value</Label>
                        <Donut
                            items={[{ key: 'used', label: 'Used', value: 41 }]}
                            total={100}
                            label="Disk used"
                            centerValue="41%"
                            centerLabel="Used"
                            size={104}
                            thickness={10}
                        />
                    </div>
                </Panel>
            </div>
        </Section>
    );
}

function ChartsSection() {
    const single: TimeSeries[] = useMemo(
        () => [
            {
                key: 'cpu',
                label: 'CPU',
                points: walk(5, 60, 30, 12).map((v, i) => ({
                    t: HOUR_TIMES[i],
                    v: i > 52 && i < 55 ? null : v,
                })),
            },
        ],
        [],
    );
    const replicas: TimeSeries[] = useMemo(
        () =>
            Array.from({ length: 6 }, (_, r) => ({
                key: `r${r}`,
                label: `api-${r + 1}`,
                slot: r + 1,
                points: walk(10 + r, 60, 380 - r * 50, 40, 0, 1024).map(
                    (v, i) => ({ t: HOUR_TIMES[i], v }),
                ),
            })),
        [],
    );

    return (
        <Section
            id="charts"
            title="Time series"
            description="Full charts: one y-axis, hairline grid, crosshair tooltip (arrow keys too), deploy markers, table view."
        >
            <div className="grid gap-4 lg:grid-cols-2">
                <Panel>
                    <Label>CPU: single series with deploy markers</Label>
                    <TimeSeriesChart
                        series={single}
                        title="CPU over the last hour"
                        yMax={100}
                        formatValue={(v) => `${Math.round(v)}%`}
                        markers={[
                            { t: NOW - 41 * MINUTE, label: '3b7d5e1' },
                            {
                                t: NOW - 12 * MINUTE,
                                label: '8c41e0f',
                                tone: 'error',
                            },
                        ]}
                    />
                </Panel>
                <Panel>
                    <Label>Memory per replica: 6 series → 4 + other</Label>
                    <TimeSeriesChart
                        series={replicas}
                        title="Memory per replica"
                        formatValue={(v) => `${Math.round(v)} MB`}
                    />
                </Panel>
            </div>
            <Panel>
                <Label>
                    Requests per hour by class: stacked bars with deploy
                    markers; click a bar to drill into its logs
                </Label>
                <StackedBars
                    title="Requests per hour, last 24 hours"
                    times={Array.from(
                        { length: 24 },
                        (_, i) => NOW - (23 - i) * 3_600_000,
                    )}
                    bucketMs={3_600_000}
                    markers={[
                        {
                            t: NOW - 16 * 3_600_000,
                            label: '3b7d5e1',
                            detail: 'web 3b7d5e1: live',
                            href: '#',
                        },
                        {
                            t: NOW - 6 * 3_600_000,
                            label: '9c2e1aa',
                            tone: 'error',
                            detail: 'web 9c2e1aa: failed',
                            href: '#',
                        },
                    ]}
                    drill={(window) => [
                        {
                            label: `Logs from ${formatWindow(window)}`,
                            href: '#',
                        },
                        { label: 'Errors only', href: '#' },
                    ]}
                    series={[
                        {
                            key: '2xx',
                            ...REQUEST_CLASS.success,
                            values: Array.from(
                                { length: 24 },
                                (_, i) =>
                                    400 + Math.round(300 * Math.sin(i / 3.5)),
                            ),
                        },
                        {
                            key: '3xx',
                            ...REQUEST_CLASS.redirect,
                            values: Array.from({ length: 24 }, () => 40),
                        },
                        {
                            key: '4xx',
                            ...REQUEST_CLASS.clientError,
                            values: Array.from({ length: 24 }, (_, i) =>
                                i % 5 === 0 ? 60 : 12,
                            ),
                        },
                        {
                            key: 'blocked',
                            ...REQUEST_CLASS.blocked,
                            values: Array.from({ length: 24 }, (_, i) =>
                                i > 10 && i < 14 ? 70 : 4,
                            ),
                        },
                        {
                            key: '5xx',
                            ...REQUEST_CLASS.serverError,
                            values: Array.from({ length: 24 }, (_, i) =>
                                i === 17 ? 90 : 0,
                            ),
                        },
                    ]}
                />
            </Panel>
            <Panel>
                <Label>Attention chips: open alerts, Action needed</Label>
                <div className="flex flex-wrap items-center gap-2">
                    <AttentionChip label="Unhealthy" tone="error" more={1} />
                    <AttentionChip label="Errors 8%" />
                    <AttentionChip label="Disk 93%" size="sm" />
                    <AttentionChip
                        label="Action needed"
                        tone="error"
                        count={3}
                    />
                </div>
            </Panel>
            <Panel>
                <Label>
                    Empty series → the caller&apos;s empty state, no frame
                </Label>
                <TimeSeriesChart
                    series={[]}
                    title="Requests"
                    empty={
                        <p className="type-body text-muted-foreground">
                            No requests yet. Metrics appear a minute after the
                            first request.
                        </p>
                    }
                />
            </Panel>
        </Section>
    );
}

const BUILD_LOG = [
    '$ pnpm install --frozen-lockfile',
    'Lockfile is up to date, resolution step is skipped',
    'Progress: resolved 1, reused 0, downloaded 0, added 0',
    'Packages: +812',
    '\u001b[33mWARN\u001b[0m deprecated inflight@1.0.6',
    '\u001b[31mERR_PNPM_OUTDATED_LOCKFILE\u001b[0m Cannot install with "frozen-lockfile" because pnpm-lock.yaml is not up to date with package.json',
    'Failure reason:',
    'specifiers in the lockfile ({"next":"16.0.3"}) don\'t match specs in package.json ({"next":"16.1.0"})',
    'process "pnpm install --frozen-lockfile" did not complete successfully: exit code: 1',
    '<script>alert("logs are text")</script>',
].map((text, index) => ({ number: index + 3, text }));

function StepsSection() {
    const failed: Step[] = [
        {
            id: 'plan',
            name: 'Plan',
            status: 'succeeded',
            summary: 'Next.js 16.1, pnpm 9.12, Node 22',
            duration: '3s',
        },
        {
            id: 'build',
            name: 'Build',
            status: 'failed',
            chips: <StepChip tone="error">1 error</StepChip>,
            duration: '41s',
            content: (
                <pre className="overflow-x-auto p-4 type-mono-small">
                    {BUILD_LOG.map((line) => line.text).join('\n')}
                </pre>
            ),
        },
        { id: 'release', name: 'Release', status: 'skipped' },
        { id: 'rollout', name: 'Roll out', status: 'skipped' },
        { id: 'health', name: 'Health check', status: 'skipped' },
        { id: 'route', name: 'Route traffic', status: 'skipped' },
    ];
    const running: Step[] = [
        {
            id: 'plan',
            name: 'Plan',
            status: 'succeeded',
            duration: '2s',
            content: (
                <p className="p-4 type-small text-muted-foreground">
                    Detected Laravel 13 with PHP 8.4
                </p>
            ),
        },
        {
            id: 'build',
            name: 'Build',
            status: 'succeeded',
            duration: '1m 02s',
            content: (
                <p className="p-4 type-small text-muted-foreground">
                    Build output…
                </p>
            ),
        },
        { id: 'migrate', name: 'Migrate', status: 'succeeded', duration: '4s' },
        {
            id: 'health',
            name: 'Health check',
            status: 'running',
            summary: '1 of 2 replicas healthy',
            duration: '12s',
        },
        { id: 'route', name: 'Route traffic', status: 'pending' },
    ];

    return (
        <Section
            id="steps"
            title="Steps"
            description="StepList: the failing step opens on its error."
        >
            <div className="grid gap-4 lg:grid-cols-2">
                <StepList steps={failed} />
                <StepList steps={running} />
            </div>
        </Section>
    );
}

const SERVICES = ['api', 'web', 'queue', 'scheduler'];
const MESSAGES: [LogLevel, string][] = [
    ['info', 'GET /api/orders 200 in 23ms'],
    ['info', 'Processing job App\\Jobs\\SyncStock'],
    ['info', '\u001b[32m✓\u001b[0m cache warmed in 112ms'],
    ['debug', 'query took 4.1ms: select * from "orders" where "id" = ?'],
    ['warning', 'Slow query (812ms): select * from "products"'],
    ['error', 'SQLSTATE[08006] connection to server at "postgres" timed out'],
    [
        'error',
        'Illuminate\\Http\\Client\\ConnectionException: cURL error 28: Operation timed out after 10000 milliseconds with 0 bytes received for https://stock.supplier.example/v2/items',
    ],
    ['info', 'POST /webhooks/stripe 204 in 9ms'],
];

function makeEntries(count: number): LogEntry[] {
    const random = seeded(42);
    const from = NOW - 60 * MINUTE;

    return Array.from({ length: count }, (_, i) => {
        const pick = random();
        const [level, message] =
            MESSAGES[
                pick < 0.02
                    ? 5
                    : pick < 0.03
                      ? 6
                      : pick < 0.08
                        ? 4
                        : Math.floor(random() * 4) === 3
                          ? 3
                          : [0, 1, 2, 7][Math.floor(random() * 4)]
            ];
        const service = SERVICES[Math.floor(random() * SERVICES.length)];
        const t = from + (i / count) * 60 * MINUTE + random() * 500;

        return {
            id: `l${i}`,
            time: new Date(t).toISOString(),
            level,
            service,
            replica:
                service === 'api' ? String(1 + Math.floor(random() * 2)) : null,
            server: random() > 0.4 ? 'fra-1' : 'hel-1',
            deployment: t > NOW - 41 * MINUTE ? '3b7d5e1' : '1f0c2d9',
            stream:
                level === 'error' || level === 'warning' ? 'stderr' : 'stdout',
            message,
        };
    });
}

function LogsSection() {
    const [big, setBig] = useState(false);
    const all = useMemo(() => makeEntries(big ? 100_000 : 2_400), [big]);
    const [query, setQuery] = useState('level:error');
    const parsed = useMemo(() => parseLogQuery(query), [query]);
    const entries = useMemo(
        () => all.filter((entry) => matchesQuery(entry, parsed)).reverse(),
        [all, parsed],
    );
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const selected =
        entries.find((entry) => entry.id === selectedId) ?? entries[1] ?? null;
    const buckets = useMemo(
        () =>
            bucketEntries(
                all.filter((entry) =>
                    matchesQuery(entry, {
                        ...parsed,
                        filters: parsed.filters.filter(
                            (f) => f.key !== 'level',
                        ),
                    }),
                ),
                NOW - 60 * MINUTE,
                NOW,
                60,
            ),
        [all, parsed],
    );
    const facets = useMemo(() => countFacets(all), [all]);
    const selectedFilters = Object.fromEntries(
        facets.map((group) => [
            group.key,
            parsed.filters
                .filter((f) => f.key === group.key && !f.negated)
                .map((f) => f.value),
        ]),
    );
    const index = selected ? entries.indexOf(selected) : -1;

    return (
        <Section
            id="logs"
            title="Log explorer core"
            description="Query bar, histogram with deploy markers, filter rail with counts, virtualized table, detail drawer."
        >
            <div className="flex flex-col overflow-hidden rounded-[14px] border bg-card">
                <div className="flex flex-col gap-3 border-b p-4">
                    <div className="flex items-start gap-3">
                        <QueryBar
                            value={query}
                            onChange={setQuery}
                            className="flex-1"
                        />
                        <button
                            type="button"
                            onClick={() => setBig((value) => !value)}
                            className="h-9 shrink-0 rounded-lg border px-3 type-label hover:bg-(--hover)"
                            aria-pressed={big}
                        >
                            {big ? '2.4k lines' : '100k lines'}
                        </button>
                    </div>
                    <Histogram
                        buckets={buckets}
                        label="Log lines per minute"
                        markers={[
                            { t: NOW - 41 * MINUTE, label: 'deploy 3b7d5e1' },
                        ]}
                        highlight={selected ? Date.parse(selected.time) : null}
                    />
                </div>
                <div className="grid h-[520px] grid-cols-1 md:grid-cols-[220px_1fr] xl:grid-cols-[220px_1fr_340px]">
                    <FilterRail
                        groups={facets}
                        selected={selectedFilters}
                        onToggle={(group, value) =>
                            setQuery((q) =>
                                toggleFilter(q, group as QueryKey, value),
                            )
                        }
                        className="overflow-auto border-r p-4 max-md:hidden"
                    />
                    <LogTable
                        entries={entries}
                        selectedId={selected?.id}
                        onSelect={(entry) => setSelectedId(entry.id)}
                        highlight={parsed.text.join(' ')}
                        empty={
                            <p className="p-6 type-body text-muted-foreground">
                                No lines match. Try a wider time range or remove
                                a filter.
                            </p>
                        }
                    />
                    {selected && (
                        <LogDetail
                            entry={selected}
                            className="overflow-auto border-l max-xl:hidden"
                            onPrevious={
                                index > 0
                                    ? () => setSelectedId(entries[index - 1].id)
                                    : undefined
                            }
                            onNext={
                                index < entries.length - 1
                                    ? () => setSelectedId(entries[index + 1].id)
                                    : undefined
                            }
                            footer={
                                <p className="type-small text-muted-foreground">
                                    Similar lines: 4 in the last hour, first at
                                    13:30.
                                </p>
                            }
                        />
                    )}
                </div>
            </div>
        </Section>
    );
}

function MapSection() {
    const locations: MapLocation[] = [
        {
            id: 'ber',
            label: 'Berlin',
            sublabel: 'home',
            lat: 52.52,
            lon: 13.4,
            servers: 3,
        },
        {
            id: 'fra',
            label: 'Frankfurt',
            lat: 50.11,
            lon: 8.68,
            servers: 1,
            edge: true,
        },
        { id: 'hel', label: 'Helsinki', lat: 60.17, lon: 24.94, servers: 1 },
        {
            id: 'nyc',
            label: 'New York',
            lat: 40.71,
            lon: -74.0,
            servers: 1,
            status: 'unreachable',
            edge: true,
        },
        { id: 'sgp', label: 'Singapore', lat: 1.35, lon: 103.82, servers: 1 },
    ];
    const links: MapLink[] = [
        { from: 'fra', to: 'ber' },
        { from: 'fra', to: 'hel' },
        { from: 'ber', to: 'hel', relayed: true },
        { from: 'fra', to: 'nyc' },
        { from: 'fra', to: 'sgp' },
    ];

    return (
        <Section
            id="map"
            title="Server map"
            description="Dotted land map (Natural Earth 110 m), bubbles sized by servers, edges ringed, mesh arcs."
        >
            <ServerMap locations={locations} links={links} />
        </Section>
    );
}

function TraceSection() {
    return (
        <Section
            id="trace"
            title="Request trace"
            description="A request's path through the edge and app servers, one per way it can end; drawn on once."
        >
            <TraceGallery />
        </Section>
    );
}

export const VISUAL_SECTIONS = [
    'devices',
    'metrics',
    'breakdowns',
    'charts',
    'steps',
    'logs',
    'map',
    'trace',
];

export default function VisualPrimitives() {
    return (
        <div className="flex flex-col gap-10">
            <DevicesSection />
            <MetricsSection />
            <BreakdownSection />
            <ChartsSection />
            <StepsSection />
            <LogsSection />
            <MapSection />
            <TraceSection />
        </div>
    );
}
