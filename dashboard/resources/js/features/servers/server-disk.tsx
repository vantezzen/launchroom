import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Section } from '@/components/patterns/section';
import { Status } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import { formatBytes, shortAge } from '@/lib/format';
import { cn } from '@/lib/utils';
import { freeSpace, tab } from '@/routes/servers';

type Disk = App.Data.Servers.ServerDiskData;

const MB = 1024 * 1024;

function size(mb: number | null): string {
    return formatBytes(mb === null ? null : mb * MB);
}

/** "Removed 4 images and 1.2 GB" from a cleanup's numbers. */
function removed(images: number, containers: number, mb: number): string {
    const parts = [
        images > 0 ? `${images} ${images === 1 ? 'image' : 'images'}` : null,
        containers > 0
            ? `${containers} stopped ${containers === 1 ? 'container' : 'containers'}`
            : null,
    ].filter((part): part is string => part !== null);

    if (parts.length === 0 && mb === 0) {
        return 'Nothing to remove';
    }

    return `Removed ${parts.length > 0 ? parts.join(' and ') + ', ' : ''}${size(mb)} freed`;
}

function CleanupLine({ disk }: { disk: Disk }) {
    const task = disk.cleanupTask;

    if (task && (task.status === 'pending' || task.status === 'leased')) {
        return <Status state="progress" label="Cleaning up" />;
    }

    if (task?.status === 'failed' || task?.status === 'cancelled') {
        return (
            <Status
                state="failed"
                label="Cleanup didn’t finish"
                detail={task.error ?? undefined}
            />
        );
    }

    if (task?.status === 'succeeded' && task.reclaimedMb !== null) {
        return (
            <span className="type-small text-muted-foreground">
                {removed(
                    task.removedImages ?? 0,
                    task.removedContainers ?? 0,
                    task.reclaimedMb,
                )}{' '}
                {task.finishedAt && shortAge(task.finishedAt)}
            </span>
        );
    }

    const last = disk.lastCleanup;

    if (!last) {
        return (
            <span className="type-small text-muted-foreground">
                Unused images are removed daily and when the disk is 85% full.
            </span>
        );
    }

    return (
        <span className="type-small text-muted-foreground">
            Last cleanup {shortAge(last.at)}:{' '}
            {removed(
                last.removedImages,
                last.removedContainers,
                last.reclaimedMb,
            ).toLowerCase()}
        </span>
    );
}

/**
 * Overview → Disk (G18): how full the disk is, what takes it (volumes
 * by size, images, build cache, waiting backups), file entries, the last
 * cleanup and "Free space now". Volumes are never removed by a cleanup.
 */
export function ServerDisk({
    serverId,
    disk,
}: {
    serverId: string;
    disk: Disk;
}) {
    const [busy, setBusy] = useState(false);
    const [all, setAll] = useState(false);
    const total = disk.totalMb ?? 0;
    const used = total > 0 && disk.freeMb !== null ? total - disk.freeMb : null;
    const percent = used !== null && total > 0 ? (used * 100) / total : null;
    const volumes = all ? disk.volumes : disk.volumes.slice(0, 6);
    const volumeMb = disk.volumes.reduce((sum, v) => sum + v.sizeMb, 0);
    const retired = disk.volumes.filter((v) => v.retired);
    const running =
        disk.cleanupTask?.status === 'pending' ||
        disk.cleanupTask?.status === 'leased';
    const lowInodes =
        disk.inodesFreePercent !== null && disk.inodesFreePercent < 10;

    const facts: { label: string; value: string }[] = [
        { label: 'Volumes', value: size(volumeMb) },
        { label: 'Images', value: size(disk.imagesMb) },
        { label: 'Build cache', value: size(disk.buildCacheMb) },
    ];

    if (disk.walBacklogMb !== null && disk.walBacklogMb > 0) {
        facts.push({
            label: 'Waiting backup data',
            value: size(disk.walBacklogMb),
        });
    }

    if (disk.registryMb !== null) {
        facts.push({ label: 'Image registry', value: size(disk.registryMb) });
    }

    const free = () => {
        setBusy(true);
        router.post(
            freeSpace(serverId).url,
            {},
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    return (
        <Section id="disk" title="Disk">
            <div className="flex flex-col rounded-xl border bg-card shadow-card">
                <div className="flex flex-col gap-3 p-6">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <span className="type-body">
                            {disk.freeMb !== null && total > 0
                                ? `${size(disk.freeMb)} free of ${size(total)}`
                                : 'Not reported yet'}
                        </span>
                        {disk.inodesFreePercent !== null && (
                            <span
                                className={cn(
                                    'type-small tabular-nums',
                                    lowInodes
                                        ? 'text-status-progress'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {lowInodes && 'Few file entries left: '}
                                {Math.floor(disk.inodesFreePercent)}% of file
                                entries free
                            </span>
                        )}
                    </div>
                    {percent !== null && (
                        <div
                            className="h-1.5 overflow-hidden rounded-full bg-recessed"
                            role="meter"
                            aria-label="Disk used"
                            aria-valuemin={0}
                            aria-valuemax={100}
                            aria-valuenow={Math.round(percent)}
                        >
                            <div
                                className={cn(
                                    'h-full rounded-full',
                                    percent >= 90
                                        ? 'bg-status-error'
                                        : percent >= 80
                                          ? 'bg-status-progress'
                                          : 'bg-foreground',
                                )}
                                style={{ width: `${Math.min(100, percent)}%` }}
                            />
                        </div>
                    )}
                    <dl className="grid grid-cols-2 gap-x-6 gap-y-3 pt-1 sm:grid-cols-4">
                        {facts.map((fact) => (
                            <div key={fact.label} className="flex flex-col">
                                <dt className="type-small text-muted-foreground">
                                    {fact.label}
                                </dt>
                                <dd className="type-body tabular-nums">
                                    {fact.value}
                                </dd>
                            </div>
                        ))}
                    </dl>
                </div>

                {disk.volumes.length > 0 && (
                    <div className="border-t px-6 py-3">
                        <ul className="divide-y">
                            {volumes.map((volume) => (
                                <li
                                    key={volume.id}
                                    className="flex items-baseline justify-between gap-3 py-2"
                                >
                                    <span className="flex min-w-0 flex-col">
                                        <span className="truncate type-body">
                                            {volume.label}
                                            {volume.retired && (
                                                <span className="ml-2 type-small text-muted-foreground">
                                                    No longer used
                                                </span>
                                            )}
                                        </span>
                                        <span className="truncate type-small text-muted-foreground">
                                            {[volume.detail, volume.mountPath]
                                                .filter(Boolean)
                                                .join(', ')}
                                        </span>
                                    </span>
                                    <span className="shrink-0 type-small tabular-nums">
                                        {size(volume.sizeMb)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                        <div className="flex flex-wrap items-center justify-between gap-2 pt-2 type-small text-muted-foreground">
                            <span>
                                {disk.measuredAt && (
                                    <>
                                        Measured{' '}
                                        <TimeAgo value={disk.measuredAt} />
                                    </>
                                )}
                                {retired.length > 0 && (
                                    <>
                                        {disk.measuredAt && '. '}
                                        <Link
                                            href={
                                                tab({
                                                    server: serverId,
                                                    tab: 'settings',
                                                }).url
                                            }
                                            className="text-signal underline-offset-4 hover:underline"
                                        >
                                            Review unused volumes
                                        </Link>
                                    </>
                                )}
                            </span>
                            {disk.volumes.length > 6 && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => setAll(!all)}
                                >
                                    {all
                                        ? 'Show fewer'
                                        : `All ${disk.volumes.length} volumes`}
                                </Button>
                            )}
                        </div>
                    </div>
                )}

                <div className="flex flex-wrap items-center justify-between gap-3 border-t px-6 py-3">
                    <CleanupLine disk={disk} />
                    {disk.canFreeSpace ? (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={free}
                            disabled={busy || running}
                        >
                            Free space now
                        </Button>
                    ) : (
                        disk.freeSpaceUnavailable && (
                            <span className="type-small text-muted-foreground">
                                {disk.freeSpaceUnavailable}
                            </span>
                        )
                    )}
                </div>
            </div>
        </Section>
    );
}
