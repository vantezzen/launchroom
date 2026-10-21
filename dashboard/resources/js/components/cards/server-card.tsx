import type { InertiaLinkProps } from '@inertiajs/react';
import type { ReactNode } from 'react';
import type { Breakdown } from '@/components/charts/palette';
import { Sparkline } from '@/components/charts/sparkline';
import { UsageMeter } from '@/components/charts/usage-meter';
import { DeviceImage } from '@/components/devices/device-image';
import type {
    DeviceKind,
    DeviceStatus,
} from '@/components/devices/device-image';
import { CardChip, ObjectCard } from './object-card';

export type ServerCardMetric = {
    /** Current value as text ("34%", "5.1 / 8 GB"); null hides the metric. */
    value: string | null;
    /** Last hour; fewer than two points → no sparkline, just the value. */
    series?: (number | null)[];
    max?: number;
};

export type ServerCardProps = {
    name: string;
    href?: NonNullable<InertiaLinkProps['href']>;
    device: DeviceKind;
    deviceStatus: DeviceStatus;
    /** StatusPill/Status (word + cue). */
    status: ReactNode;
    /** "Hetzner · Frankfurt", "At home · Berlin". */
    place?: string | null;
    /** "12s ago" (TimeAgo). */
    lastSeen?: ReactNode;
    /** Quiet note right of the name ("Runs this dashboard"). */
    note?: ReactNode;
    roles?: string[];
    cpu?: ServerCardMetric;
    memory?: ServerCardMetric;
    /** Memory by project; top 3 coloured by their slot, rest grey. */
    usedBy?: { items: Breakdown[]; total: number; caption: string };
    /** "4 cores · 8 GB · 160 GB". */
    specs?: string;
    /** "Disk 41%". */
    disk?: string;
    /** Callout replacing the metrics (unreachable explanation + actions). */
    alert?: ReactNode;
};

function Metric({
    label,
    metric,
}: {
    label: string;
    metric?: ServerCardMetric;
}) {
    if (!metric || metric.value === null) {
        return null;
    }

    return (
        <div className="flex min-w-0 flex-col gap-1.5">
            <div className="flex items-baseline justify-between gap-2 type-small">
                <span className="text-muted-foreground">{label}</span>
                <span className="font-medium tabular-nums">{metric.value}</span>
            </div>
            <Sparkline
                values={metric.series ?? []}
                min={0}
                max={metric.max}
                height={22}
                label={`${label} last hour`}
            />
        </div>
    );
}

/**
 * Fleet card (direction §9.1, maintainer decision §5 #2: fewer elements, one
 * accent): device on the stage, name + status, roles, CPU and memory with
 * signal sparklines, an optional "used by" meter, specs footer. Without
 * metrics it shows specs and status only.
 */
export function ServerCard({
    name,
    href,
    device,
    deviceStatus,
    status,
    place,
    lastSeen,
    note,
    roles = [],
    cpu,
    memory,
    usedBy,
    specs,
    disk,
    alert,
}: ServerCardProps) {
    const hasMetrics = Boolean(cpu?.value ?? memory?.value);

    return (
        <ObjectCard
            title={name}
            href={href}
            media={
                <DeviceImage
                    device={device}
                    status={deviceStatus}
                    size={232}
                    className="mt-4"
                />
            }
            mediaLabel={place ?? undefined}
            mediaMeta={lastSeen}
            status={status}
            meta={note}
            chips={
                roles.length > 0
                    ? roles.map((role) => (
                          <CardChip key={role}>{role}</CardChip>
                      ))
                    : undefined
            }
            footer={
                specs || disk ? (
                    <>
                        <span className="truncate">{specs}</span>
                        {disk && <span className="shrink-0">{disk}</span>}
                    </>
                ) : undefined
            }
        >
            {alert ??
                (hasMetrics && (
                    <div className="grid grid-cols-2 gap-5">
                        <Metric label="CPU" metric={cpu} />
                        <Metric label="Memory" metric={memory} />
                    </div>
                ))}
            {!alert && usedBy && usedBy.items.length > 0 && (
                <div className="flex flex-col gap-2">
                    <span className="type-small text-muted-foreground">
                        {usedBy.caption}
                    </span>
                    <UsageMeter
                        items={usedBy.items}
                        total={usedBy.total}
                        label={`${name} memory by project`}
                        max={3}
                        height={6}
                    />
                </div>
            )}
        </ObjectCard>
    );
}
