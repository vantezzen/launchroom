import { Link } from '@inertiajs/react';
import { Hammer } from 'lucide-react';
import { useState } from 'react';
import { StatTile } from '@/components/charts/stat-tile';
import { TimeRangeSelect } from '@/components/charts/time-range-select';
import type { TimeRange } from '@/components/charts/time-range-select';
import { UsageMeter } from '@/components/charts/usage-meter';
import { Logo } from '@/components/logo';
import { MetaLine } from '@/components/patterns/meta-line';
import { Section } from '@/components/patterns/section';
import { Sparkline } from '@/components/charts/sparkline';
import { formatMb } from '@/features/servers/format';
import services from '@/routes/projects/services';

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
    range: TimeRange;
    onRangeChange: (range: TimeRange) => void;
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

    if (!current && !hasData(host?.cpuPct)) {
        return null;
    }

    return (
        <Section
            title="Usage"
            actions={<TimeRangeSelect value={range} onChange={onRangeChange} />}
        >
            <div className="grid gap-5 sm:grid-cols-3">
                <StatTile
                    label="CPU"
                    value={
                        current?.cpuPct != null
                            ? String(Math.round(current.cpuPct))
                            : null
                    }
                    unit="%"
                    hint={specs?.cpus ? `${specs.cpus} cores` : undefined}
                    series={series(host?.cpuPct, pct)}
                />
                <StatTile
                    label="Memory"
                    value={
                        current?.memUsedMb != null
                            ? (formatMb(current.memUsedMb) ?? '').split(' ')[0]
                            : null
                    }
                    unit={
                        current?.memUsedMb != null && current.memTotalMb
                            ? `${(formatMb(current.memUsedMb) ?? '').split(' ')[1]} of ${formatMb(current.memTotalMb)}`
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
                    unit={
                        current?.diskTotalMb
                            ? `% of ${formatMb(current.diskTotalMb)}`
                            : '%'
                    }
                    series={series(host?.diskPct, pct)}
                />
            </div>
        </Section>
    );
}

type Workload = App.Data.Servers.ServerWorkloadData;

type Row = {
    key: string;
    serviceId: string;
    serviceName: string;
    projectId: string;
    projectName: string;
    environmentId: string;
    environmentName: string;
    replicas: number;
    rollingOut: boolean;
    draining: boolean;
    limitMb: number;
    usage?: App.Data.Metrics.ServiceUsageSeriesData;
};

function rows(workloads: Workload[], metrics: Metrics): Row[] {
    const byService = new Map<string, Row>();

    for (const workload of workloads) {
        const key = `${workload.serviceId}:${workload.environmentId}`;
        const row = byService.get(key) ?? {
            key,
            serviceId: workload.serviceId,
            serviceName: workload.serviceName,
            projectId: workload.projectId,
            projectName: workload.projectName,
            environmentId: workload.environmentId,
            environmentName: workload.environmentName,
            replicas: 0,
            rollingOut: false,
            draining: false,
            limitMb: 0,
            usage: metrics?.services.find(
                (s) =>
                    s.serviceId === workload.serviceId &&
                    s.environmentId === workload.environmentId,
            ),
        };
        row.replicas += 1;
        row.limitMb += workload.memoryMb;
        row.rollingOut ||= !workload.active && workload.role === 'serving';
        row.draining ||= workload.role === 'draining';
        byService.set(key, row);
    }

    return [...byService.values()].sort(
        (a, b) =>
            (b.usage?.currentMemMb ?? b.limitMb) -
            (a.usage?.currentMemMb ?? a.limitMb),
    );
}

/** CPU of a service as a share of the server ("3%"), or in cores when the core count is unknown. */
export function formatServiceCpu(
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

/**
 * "What runs here" (direction §9.2): memory by project (top 3 in their
 * colour, the rest grey) and one row per service, largest first, with its
 * current CPU (share of the server) and memory (sparkline + value).
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
    const hasUsage = list.some((row) => row.usage?.currentMemMb != null);
    // The backend folds small projects into one "Other" row (projectId
    // null): it stays grey whatever its size.
    const usedBy = (metrics?.usedBy ?? []).filter((item) => item.memMb > 0);
    const total = metrics?.current?.memTotalMb ?? 0;
    // Build servers: the builder's CPU, beside the services it competes with.
    const buildCpu = metrics?.current?.buildCpuCores ?? null;
    const reserved =
        placement.schedulableMemoryMb !== null
            ? `${formatMb(placement.allocatedMemoryMb)} of ${formatMb(placement.schedulableMemoryMb)} reserved`
            : `${formatMb(placement.allocatedMemoryMb)} reserved`;

    return (
        <Section
            title="What runs here"
            description={
                list.length > 0
                    ? `${list.length} ${list.length === 1 ? 'service' : 'services'}, ${reserved}`
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
                            format={(mb) => formatMb(mb) ?? ''}
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
                    <>
                        {hasUsage && (
                            <div
                                aria-hidden="true"
                                className="hidden grid-cols-[32px_minmax(0,1fr)_56px_120px_72px] gap-x-4 border-b px-6 py-2.5 type-small text-muted-foreground sm:grid"
                            >
                                <span />
                                <span>Service</span>
                                <span className="text-right">CPU</span>
                                <span className="col-span-2 text-right">
                                    Memory
                                </span>
                            </div>
                        )}
                        <ul className="divide-y">
                            {visible.map((row) => {
                                const cpu = formatServiceCpu(
                                    row.usage?.currentCpuCores,
                                    cores,
                                );
                                const memory =
                                    row.usage?.currentMemMb != null
                                        ? formatMb(row.usage.currentMemMb)
                                        : null;

                                return (
                                    <li key={row.key}>
                                        <Link
                                            href={
                                                services.show({
                                                    project: row.projectId,
                                                    environment:
                                                        row.environmentId,
                                                    service: row.serviceId,
                                                }).url
                                            }
                                            className="grid grid-cols-[32px_minmax(0,1fr)_auto] items-center gap-x-4 px-6 py-3.5 hover:bg-hover sm:grid-cols-[32px_minmax(0,1fr)_56px_120px_72px]"
                                        >
                                            <Logo
                                                kind="app"
                                                name={row.serviceName}
                                                size={32}
                                            />
                                            <span className="flex min-w-0 flex-col">
                                                <span className="truncate type-body font-medium">
                                                    {row.serviceName}
                                                </span>
                                                <MetaLine className="type-small text-muted-foreground">
                                                    {`${row.projectName} / ${row.environmentName}`}
                                                    {row.replicas === 1
                                                        ? '1 replica'
                                                        : `${row.replicas} replicas`}
                                                    {row.rollingOut &&
                                                        'Rolling out'}
                                                    {row.draining && 'Draining'}
                                                </MetaLine>
                                            </span>
                                            <span
                                                className="hidden text-right type-small tabular-nums sm:block"
                                                title={
                                                    row.usage
                                                        ?.currentCpuCores !=
                                                    null
                                                        ? `${row.usage.currentCpuCores.toFixed(2)} cores`
                                                        : undefined
                                                }
                                            >
                                                {cpu ?? (
                                                    <span className="text-muted-foreground">
                                                        –
                                                    </span>
                                                )}
                                            </span>
                                            <span className="hidden sm:block">
                                                <Sparkline
                                                    values={
                                                        row.usage?.memMb ?? []
                                                    }
                                                    min={0}
                                                    height={22}
                                                    label={`${row.serviceName} memory`}
                                                />
                                            </span>
                                            <span className="flex flex-col items-end text-right type-small tabular-nums">
                                                {memory ?? (
                                                    <span className="text-muted-foreground">
                                                        {formatMb(row.limitMb)}{' '}
                                                        limit
                                                    </span>
                                                )}
                                                {cpu !== null && (
                                                    <span className="text-muted-foreground sm:hidden">
                                                        CPU {cpu}
                                                    </span>
                                                )}
                                            </span>
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    </>
                )}
                {buildCpu !== null && (
                    <div className="grid grid-cols-[32px_minmax(0,1fr)_auto] items-center gap-x-4 border-t px-6 py-3.5 sm:grid-cols-[32px_minmax(0,1fr)_56px_120px_72px]">
                        <span
                            aria-hidden="true"
                            className="flex size-8 items-center justify-center rounded-[9px] border bg-card"
                        >
                            <Hammer className="size-4 text-muted-foreground" />
                        </span>
                        <span className="flex min-w-0 flex-col">
                            <span className="type-body font-medium">
                                Builds
                            </span>
                            <span className="type-small text-muted-foreground">
                                CPU over the last minute
                            </span>
                        </span>
                        <span
                            className="text-right type-small tabular-nums"
                            title={`${buildCpu.toFixed(2)} cores`}
                        >
                            <span className="sm:hidden">CPU </span>
                            {formatServiceCpu(buildCpu, cores)}
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
