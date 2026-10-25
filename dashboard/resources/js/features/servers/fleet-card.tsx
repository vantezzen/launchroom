import { Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import type { ReactNode } from 'react';
import { CardChip, ObjectCard } from '@/components/cards';
import { colorForSlot } from '@/components/charts/palette';
import { Sparkline } from '@/components/charts/sparkline';
import { DeviceImage } from '@/components/devices/device-image';
import { MetaLine } from '@/components/patterns/meta-line';
import { Status } from '@/components/status';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    deviceKind,
    deviceStatus,
    formatMb,
    formatUsedOf,
    placeLabel,
    shortAge,
    shortDuration,
    specParts,
} from '@/features/servers/format';
import { RoleChipLabel, ServerStatus } from '@/features/servers/server-status';
import { PendingCardMenu } from '@/features/servers/remove-pending';
import { cn } from '@/lib/utils';
import { create, replace as replaceRoute, show } from '@/routes/servers';
import { show as enrollmentRoute } from '@/routes/servers/enrollments';

type Server = App.Data.Servers.ServerData;
type Usage = App.Data.Metrics.ServerUsageData;

/** Projects by memory on this server, largest first. */
function RunsLine({ usedBy }: { usedBy: App.Data.Metrics.ProjectUsageData[] }) {
    const named = usedBy
        .filter((item) => item.projectId !== null && item.memMb > 0)
        .sort((a, b) => b.memMb - a.memMb);

    if (named.length === 0) {
        return null;
    }

    const shown = named.slice(0, 3);
    const rest = named.length - shown.length;

    return (
        <p className="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 type-small text-muted-foreground">
            <span>Runs</span>
            {shown.map((item) => (
                <span
                    key={item.projectId}
                    className="inline-flex min-w-0 items-center gap-1.5 text-foreground"
                    title={`${item.name}: ${formatMb(item.memMb) ?? ''} memory`}
                >
                    <span
                        aria-hidden="true"
                        className="size-2 shrink-0 rounded-full"
                        style={{
                            backgroundColor:
                                item.colorSlot === null
                                    ? 'var(--chart-other)'
                                    : colorForSlot(item.colorSlot + 1),
                        }}
                    />
                    <span className="truncate">{item.name}</span>
                </span>
            ))}
            {rest > 0 && <span>+{rest} more</span>}
        </p>
    );
}

function Usage({
    label,
    value,
    series,
}: {
    label: string;
    value: string;
    series: (number | null)[];
}) {
    return (
        <div className="flex min-w-0 flex-col gap-1.5">
            <div className="flex items-baseline justify-between gap-2 type-small">
                <span className="text-muted-foreground">{label}</span>
                <span className="font-medium tabular-nums">{value}</span>
            </div>
            <Sparkline
                values={series}
                min={0}
                max={100}
                height={22}
                label={`${label}, last hour`}
            />
        </div>
    );
}

function WhatToCheck({ server }: { server: Server }) {
    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button
                    size="sm"
                    variant="outline"
                    className="relative z-10 bg-card"
                >
                    What to check
                </Button>
            </PopoverTrigger>
            <PopoverContent align="start" className="w-80">
                <p className="type-label">
                    {server.name} hasn’t reported for{' '}
                    {shortDuration(server.lastSeenAt) ?? 'a while'}
                </p>
                <ol className="mt-3 flex list-decimal flex-col gap-2 pl-4 type-small text-muted-foreground">
                    <li>Is the machine on, and does it have internet?</li>
                    <li>Is Docker running on it?</li>
                    <li>
                        Did its address or firewall change? It only needs to
                        reach this dashboard over HTTPS.
                    </li>
                </ol>
                <Link
                    href={show(server.id)}
                    className="mt-3 inline-block type-small text-signal underline-offset-4 hover:underline"
                >
                    Open {server.name}
                </Link>
            </PopoverContent>
        </Popover>
    );
}

/** Replaces the metrics on a server that stopped reporting. */
function UnreachableNote({
    server,
    canReplace,
}: {
    server: Server;
    canReplace: boolean;
}) {
    const replicas = server.workloads;

    return (
        <div className="flex flex-col gap-3 rounded-lg bg-recessed p-3.5">
            <p className="type-small text-muted-foreground">
                No report for {shortDuration(server.lastSeenAt) ?? 'a while'}.{' '}
                {replicas > 0
                    ? `${replicas} ${replicas === 1 ? 'replica was' : 'replicas were'} placed here.`
                    : 'Nothing was placed here.'}
            </p>
            <div className="flex flex-wrap gap-2">
                <WhatToCheck server={server} />
                {canReplace && (
                    <Button
                        asChild
                        size="sm"
                        variant="ghost"
                        className="relative z-10"
                    >
                        <Link href={replaceRoute(server.id)}>
                            Replace server
                        </Link>
                    </Button>
                )}
            </div>
        </div>
    );
}

/**
 * A server in the fleet grid (direction §9.1, maintainer decision §5 #2):
 * the device, its name, status and roles, two quiet usage lines (CPU and
 * memory, signal colour only) and which projects run there. Without
 * metrics it is the picture, the status and the specs.
 */
export function FleetCard({
    server,
    usage,
    canReplace,
    canRemove = false,
}: {
    server: Server;
    usage?: Usage;
    canReplace: boolean;
    /** Waiting for approval: "⋯ Remove server" (revokes its key). */
    canRemove?: boolean;
}) {
    const current = usage?.current ?? null;
    const problem =
        server.status === 'unreachable' || server.status === 'offline';
    const disk = current?.diskPct ?? null;
    const lastSeen = shortAge(server.lastSeenAt);
    const cpu =
        current?.cpuPct != null ? `${Math.round(current.cpuPct)}%` : null;
    const memory =
        current?.memUsedMb != null && current.memTotalMb
            ? formatUsedOf(current.memUsedMb, current.memTotalMb)
            : null;
    const specs = specParts(server.specs);

    return (
        <ObjectCard
            className="h-full"
            title={server.name}
            href={show(server.id)}
            media={
                <DeviceImage
                    device={deviceKind(server)}
                    status={deviceStatus(server.status)}
                    size={232}
                    className="mt-4"
                />
            }
            tone={problem ? 'error' : 'default'}
            mediaLabel={placeLabel(server) ?? undefined}
            mediaMeta={
                lastSeen && (
                    <span
                        className={cn(problem && 'text-status-error')}
                        title={
                            server.lastSeenAt
                                ? `Last report ${new Date(server.lastSeenAt).toLocaleString()}`
                                : undefined
                        }
                    >
                        {lastSeen}
                    </span>
                )
            }
            status={
                <ServerStatus
                    status={server.status}
                    className="type-small text-muted-foreground"
                />
            }
            meta={
                server.isControlPlane ? (
                    'Runs this dashboard'
                ) : canRemove && server.status === 'pending_approval' ? (
                    <PendingCardMenu
                        target={{
                            kind: 'server',
                            id: server.id,
                            name: server.name,
                        }}
                    />
                ) : undefined
            }
            chips={server.roles.map((role) => (
                <CardChip key={role}>
                    <RoleChipLabel role={role} />
                </CardChip>
            ))}
            footer={
                specs.length > 0 || disk !== null ? (
                    <>
                        <MetaLine className="flex-nowrap overflow-hidden whitespace-nowrap">
                            {specs}
                        </MetaLine>
                        {disk !== null && disk >= 80 && (
                            <span className="shrink-0 text-status-progress">
                                Disk {Math.round(disk)}%
                            </span>
                        )}
                    </>
                ) : undefined
            }
        >
            {problem ? (
                <UnreachableNote server={server} canReplace={canReplace} />
            ) : (
                (cpu !== null || memory !== null) && (
                    <div className="flex flex-col gap-4">
                        <div className="grid grid-cols-2 gap-5">
                            {cpu !== null && (
                                <Usage
                                    label="CPU"
                                    value={cpu}
                                    series={usage?.cpuPct ?? []}
                                />
                            )}
                            {memory !== null && (
                                <Usage
                                    label="Memory"
                                    value={memory}
                                    series={usage?.memPct ?? []}
                                />
                            )}
                        </div>
                        {usage && <RunsLine usedBy={usage.usedBy} />}
                    </div>
                )
            )}
        </ObjectCard>
    );
}

/** An enrolment waiting for its server (direction §9.1). */
export function PendingEnrollmentCard({
    enrollment,
    canRemove = false,
}: {
    enrollment: App.Data.Servers.EnrollmentData;
    canRemove?: boolean;
}) {
    return (
        <ObjectCard
            title={enrollment.name ?? 'New server'}
            href={enrollmentRoute(enrollment.id)}
            media={
                <DeviceImage
                    device="unknown"
                    status="pending"
                    size={232}
                    className="mt-4"
                />
            }
            status={<Status state="progress" label="Waiting to connect" />}
            meta={
                canRemove ? (
                    <PendingCardMenu
                        target={{
                            kind: 'enrollment',
                            id: enrollment.id,
                            name: enrollment.name,
                        }}
                    />
                ) : undefined
            }
            chips={enrollment.roles.map((role) => (
                <CardChip key={role}>
                    <RoleChipLabel role={role} />
                </CardChip>
            ))}
            footer={
                <>
                    <span>
                        Install link expires{' '}
                        {new Date(enrollment.expiresAt).toLocaleTimeString([], {
                            hour: '2-digit',
                            minute: '2-digit',
                        })}
                    </span>
                    <span className="text-foreground">Show steps</span>
                </>
            }
        />
    );
}

/** The ghost card while the fleet is small (empty-state catalogue, direction §9.16). */
export function AddServerCard({ children }: { children?: ReactNode }) {
    return (
        <Link
            href={create()}
            className="group flex min-h-[300px] flex-col items-center justify-center gap-4 rounded-[14px] border border-dashed p-6 text-center outline-none hover:border-border-strong focus-visible:ring-2 focus-visible:ring-signal"
        >
            <DeviceImage device="unknown" size={160} />
            <span className="flex flex-col items-center gap-1">
                <span className="inline-flex items-center gap-1.5 type-heading">
                    <Plus className="size-4" aria-hidden="true" />
                    Add a server
                </span>
                <span className="max-w-[30ch] type-small text-muted-foreground">
                    {children ??
                        'For builds, more capacity or a second edge. A machine at home works too.'}
                </span>
            </span>
        </Link>
    );
}
