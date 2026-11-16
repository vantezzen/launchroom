import { Link } from '@inertiajs/react';
import { Hammer } from 'lucide-react';
import { useState } from 'react';
import { StatTile } from '@/components/charts/stat-tile';
import { TimeRangeSelect } from '@/components/charts/time-range-select';
import { UsageMeter } from '@/components/charts/usage-meter';
import { colorForSlot } from '@/components/charts/palette';
import { MetaLine } from '@/components/patterns/meta-line';
import { Section } from '@/components/patterns/section';
import { RANGE_SETS, rangeOptions } from '@/lib/observe-scope';
import { canvas as projectCanvas } from '@/routes/projects';
import { formatCores, formatMemory, plural } from '@/lib/format';
import { formatOf } from '@/features/servers/format';

type MetricsRange = (typeof RANGE_SETS.resources)[number];

type Metrics = App.Data.Metrics.ServerMetricsData | undefined;

function times(metrics: Metrics): number[] | undefined {
    return metrics?.timestamps.map((t) => new Date(t).getTime());
}

function hasData(values: (number | null)[] | undefined): boolean {
    return (values ?? []).filter((v) => v !== null).length >= 2;
}

/**
 * Resources over time (direction §9.2): CPU, memory and disk as stat tiles
 * with one signal sparkline each. Without samples the tiles fall back to
 * the specs (or hide), never an empty chart.
 */
export function ServerUsage({
    metrics,
    specs,
    range,
    onRangeChange,
}: {
    metrics: Metrics;
    specs: App.Data.Servers.ServerSpecsData | null;
    range: MetricsRange;
    onRangeChange: (range: MetricsRange) => void;
}) {
    const current = metrics?.current ?? null;
    const host = metrics?.host;
    const series = (
        values: (number | null)[] | undefined,
        format: (v: number) => string,
    ) =>
        hasData(values)
            ? {
                  values: values ?? [],
                  times: times(metrics),
                  min: 0,
                  max: 100,
                  format,
              }
            : undefined;
    const pct = (v: number) => `${Math.round(v)}%`;
    const cpuPct = current?.cpuPct ?? null;
    const memPct =
        current?.memUsedMb != null && current.memTotalMb
            ? (current.memUsedMb / current.memTotalMb) * 100
            : null;
    // The big number is the latest sample; the sparkline is the range.
    const now = <span className="type-small text-muted-foreground">now</span>;

    if (!current && !hasData(host?.cpuPct)) {
        return null;
    }

    return (
        <Section
            id="disk"
            className="scroll-mt-20"
            title="Usage"
            actions={
                <TimeRangeSelect
                    value={range}
                    onChange={onRangeChange}
                    options={rangeOptions(RANGE_SETS.resources)}
                />
            }
        >
            <div className="grid gap-5 sm:grid-cols-3">
                <StatTile
                    label="CPU"
                    value={cpuPct !== null ? String(Math.round(cpuPct)) : null}
                    unit="%"
                    action={now}
                    hint={
                        cpuPct !== null && specs?.cpus
                            ? `${((cpuPct / 100) * specs.cpus).toFixed(1)} of ${formatCores(specs.cpus)}`
                            : specs?.cpus
                              ? formatCores(specs.cpus)
                              : undefined
                    }
                    series={series(host?.cpuPct, pct)}
                />
                <StatTile
                    label="Memory"
                    value={memPct !== null ? String(Math.round(memPct)) : null}
                    unit="%"
                    action={now}
                    hint={
                        current?.memUsedMb != null && current.memTotalMb
                            ? formatOf(current.memUsedMb, current.memTotalMb)
                            : undefined
                    }
                    series={series(host?.memPct, pct)}
                />
                <StatTile
                    label="Disk"
                    value={
                        current?.diskPct != null
                            ? String(Math.round(current.diskPct))
                            : null
                    }
                    unit="%"
                    action={now}
                    hint={
                        current?.diskPct != null && current.diskTotalMb
                            ? formatOf(
                                  (current.diskPct / 100) * current.diskTotalMb,
                                  current.diskTotalMb,
                              )
                            : undefined
                    }
                    series={series(host?.diskPct, pct)}
                />
            </div>
        </Section>
    );
}

type Workload = App.Data.Servers.ServerWorkloadData;

/** One project environment on this server ("shop / production"). */
type Row = {
    key: string;
    projectId: string;
    projectName: string;
    environmentId: string;
    environmentName: string;
    services: number;
    replicas: number;
    rollingOut: boolean;
    draining: boolean;
    limitMb: number;
    /** Measured now, summed over its services; null before the first sample. */
    memMb: number | null;
    cpuCores: number | null;
    colorSlot: number | null;
};

function rows(workloads: Workload[], metrics: Metrics): Row[] {
    const byEnv = new Map<string, Row & { serviceIds: Set<string> }>();

    for (const workload of workloads) {
        const key = `${workload.projectId}:${workload.environmentId}`;
        const row = byEnv.get(key) ?? {
            key,
            projectId: workload.projectId,
            projectName: workload.projectName,
            environmentId: workload.environmentId,
            environmentName: workload.environmentName,
            services: 0,
            replicas: 0,
            rollingOut: false,
            draining: false,
            limitMb: 0,
            memMb: null,
            cpuCores: null,
            colorSlot: null,
            serviceIds: new Set<string>(),
        };

        if (!row.serviceIds.has(workload.serviceId)) {
            row.serviceIds.add(workload.serviceId);
            const usage = metrics?.services.find(
                (s) =>
                    s.serviceId === workload.serviceId &&
                    s.environmentId === workload.environmentId,
            );

            if (usage?.currentMemMb != null) {
                row.memMb = (row.memMb ?? 0) + usage.currentMemMb;
            }

            if (usage?.currentCpuCores != null) {
                row.cpuCores = (row.cpuCores ?? 0) + usage.currentCpuCores;
            }
        }

        row.services = row.serviceIds.size;
        row.replicas += 1;
        row.limitMb += workload.memoryMb;
        row.rollingOut ||= !workload.active && workload.role === 'serving';
        row.draining ||= workload.role === 'draining';
        byEnv.set(key, row);
    }

    const slots = new Map(
        (metrics?.usedBy ?? []).map((item) => [item.projectId, item.colorSlot]),
    );

    return [...byEnv.values()]
        .map((row): Row => ({
            key: row.key,
            projectId: row.projectId,
            projectName: row.projectName,
            environmentId: row.environmentId,
            environmentName: row.environmentName,
            services: row.services,
            replicas: row.replicas,
            rollingOut: row.rollingOut,
            draining: row.draining,
            limitMb: row.limitMb,
            memMb: row.memMb,
            cpuCores: row.cpuCores,
            colorSlot: slots.get(row.projectId) ?? null,
        }))
        .sort(
            (a, b) =>
                (b.memMb ?? 0) - (a.memMb ?? 0) ||
                a.projectName.localeCompare(b.projectName),
        );
}

/** CPU of a project as a share of the server ("3%"), or in cores when the core count is unknown. */
function formatServiceCpu(
    cores: number | null | undefined,
    serverCores: number | null | undefined,
): string | null {
    if (cores === null || cores === undefined) {
        return null;
    }

    if (serverCores) {
        const pct = (cores / serverCores) * 100;

        return pct > 0 && pct < 1 ? '<1%' : `${Math.round(pct)}%`;
    }

    return `${cores < 1 ? cores.toFixed(2) : cores.toFixed(1)} cores`;
}

function MemoryBar({
    usedMb,
    totalMb,
    slot,
}: {
    usedMb: number;
    totalMb: number;
    slot: number | null;
}) {
    const share = totalMb > 0 ? Math.min(100, (usedMb / totalMb) * 100) : 0;

    return (
        <span
            aria-hidden="true"
            className="block h-1 w-full overflow-hidden rounded-full bg-hover"
        >
            <span
                className="block h-full rounded-full"
                style={{
                    width: `${Math.max(share, 1)}%`,
                    backgroundColor:
                        slot === null
                            ? 'var(--chart-other)'
                            : colorForSlot(slot + 1),
                }}
            />
        </span>
    );
}

/**
 * "What runs here" (docs/08 §14): memory by project as a stacked bar (the
 * legend), then one row per project environment with its services, the
 * memory it uses now against the server, and CPU once it was sampled.
 * Memory limits are caps, not use: they only show on hover.
 */
export function WhatRunsHere({
    placement,
    metrics,
    cores = null,
}: {
    placement: App.Data.Servers.ServerPlacementData;
    metrics: Metrics;
    /** The server's cores: CPU shows as a share of the server. */
    cores?: number | null;
}) {
    const [all, setAll] = useState(false);
    const list = rows(placement.workloads, metrics);
    const visible = all ? list : list.slice(0, 6);
    const services = new Set(placement.workloads.map((w) => w.serviceId)).size;
    // The backend folds small projects into one "Other" row (projectId
    // null): it stays grey whatever its size.
    const usedBy = (metrics?.usedBy ?? []).filter((item) => item.memMb > 0);
    const total = metrics?.current?.memTotalMb ?? 0;
    // Build servers: the builder's CPU, beside the services it competes with.
    const buildCpu = metrics?.current?.buildCpuCores ?? null;
    // Limits are caps, not reservations: what's left for new services is
    // the memory the server really has free.
    const free =
        placement.freeMemoryMb !== null
            ? `${formatMemory(Math.max(0, placement.freeMemoryMb))} free for new services`
            : null;

    return (
        <Section
            title="What runs here"
            description={
                list.length > 0
                    ? `${plural(services, 'service')} in ${plural(list.length, 'project environment')}${free ? `, ${free}` : ''}`
                    : undefined
            }
        >
            <div className="overflow-hidden rounded-xl border bg-card shadow-card">
                {usedBy.length > 0 && total > 0 && (
                    <div className="border-b px-6 py-5">
                        <UsageMeter
                            items={usedBy.map((item) => ({
                                key: item.projectId ?? 'other',
                                label: item.name,
                                value: item.memMb,
                                slot:
                                    item.colorSlot === null
                                        ? null
                                        : item.colorSlot + 1,
                                other: item.projectId === null,
                            }))}
                            total={total}
                            max={3}
                            label="Memory by project"
                            format={(mb) => formatMemory(mb, '')}
                        />
                    </div>
                )}
                {list.length === 0 ? (
                    <p className="px-6 py-8 text-center type-body text-muted-foreground">
                        {buildCpu !== null
                            ? 'No app services run here.'
                            : 'Nothing runs here yet.'}
                    </p>
                ) : (
                    <ul className="divide-y">
                        {visible.map((row) => {
                            const cpu = formatServiceCpu(row.cpuCores, cores);
                            const limits = `Memory limits add up to ${formatMemory(row.limitMb)}. They cap each service, they aren’t reserved.`;

                            return (
                                <li key={row.key}>
                                    <Link
                                        href={
                                            projectCanvas({
                                                project: row.projectId,
                                                environment: row.environmentId,
                                            }).url
                                        }
                                        title={limits}
                                        className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-6 gap-y-2 px-6 py-3.5 hover:bg-hover sm:grid-cols-[minmax(0,1fr)_minmax(8rem,12rem)]"
                                    >
                                        <span className="flex min-w-0 items-center gap-3">
                                            <span
                                                aria-hidden="true"
                                                className="size-2.5 shrink-0 rounded-full"
                                                style={{
                                                    backgroundColor:
                                                        row.colorSlot === null
                                                            ? 'var(--chart-other)'
                                                            : colorForSlot(
                                                                  row.colorSlot +
                                                                      1,
                                                              ),
                                                }}
                                            />
                                            <span className="flex min-w-0 flex-col">
                                                <span className="truncate type-body font-medium">
                                                    {row.projectName}
                                                </span>
                                                <MetaLine className="type-small text-muted-foreground">
                                                    {row.environmentName}
                                                    {plural(
                                                        row.services,
                                                        'service',
                                                    )}
                                                    {row.replicas >
                                                        row.services &&
                                                        plural(
                                                            row.replicas,
                                                            'replica',
                                                        )}
                                                    {row.rollingOut &&
                                                        'Rolling out'}
                                                    {row.draining && 'Draining'}
                                                </MetaLine>
                                            </span>
                                        </span>
                                        <span className="flex min-w-0 flex-col items-end gap-1.5 text-right type-small tabular-nums">
                                            {row.memMb !== null ? (
                                                <>
                                                    <span>
                                                        {total > 0
                                                            ? `${formatMemory(row.memMb)} of ${formatMemory(total)}`
                                                            : formatMemory(
                                                                  row.memMb,
                                                              )}
                                                    </span>
                                                    {total > 0 && (
                                                        <MemoryBar
                                                            usedMb={row.memMb}
                                                            totalMb={total}
                                                            slot={row.colorSlot}
                                                        />
                                                    )}
                                                </>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    Not measured yet
                                                </span>
                                            )}
                                            {cpu !== null && (
                                                <span className="text-muted-foreground">
                                                    CPU {cpu}
                                                </span>
                                            )}
                                        </span>
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                )}
                {buildCpu !== null && (
                    <div className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-6 border-t px-6 py-3.5">
                        <span className="flex min-w-0 items-center gap-3">
                            <Hammer
                                aria-hidden="true"
                                className="size-3.5 shrink-0 text-muted-foreground"
                            />
                            <span className="flex min-w-0 flex-col">
                                <span className="type-body font-medium">
                                    Builds
                                </span>
                                <span className="type-small text-muted-foreground">
                                    CPU over the last minute
                                </span>
                            </span>
                        </span>
                        <span
                            className="text-right type-small tabular-nums"
                            title={`${buildCpu.toFixed(2)} cores`}
                        >
                            CPU {formatServiceCpu(buildCpu, cores)}
                        </span>
                    </div>
                )}
                {list.length > visible.length && (
                    <button
                        type="button"
                        onClick={() => setAll(true)}
                        className="w-full border-t px-6 py-3 text-left type-small text-muted-foreground hover:bg-hover hover:text-foreground focus-visible:ring-2 focus-visible:ring-signal focus-visible:outline-none focus-visible:ring-inset"
                    >
                        Show {list.length - visible.length} more
                    </button>
                )}
            </div>
        </Section>
    );
}
