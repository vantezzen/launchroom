import { router } from '@inertiajs/react';
import { Archive } from 'lucide-react';
import { useState } from 'react';
import { ConfirmPopover } from '@/components/patterns/confirm-popover';
import { MetaLine } from '@/components/patterns/meta-line';
import { SidePanel } from '@/components/patterns/sheet';
import { Status } from '@/components/status';
import type { StatusState } from '@/components/status';
import { Button } from '@/components/ui/button';
import { shortAge } from '@/features/servers/format';
import { confirm } from '@/routes/servers/retired-volumes';

type RetiredVolume = App.Data.Servers.RetiredVolumeData;

const reasons: Record<string, string> = {
    environment_deleted: 'Environment deleted',
    project_deleted: 'Project deleted',
    preview_destroyed: 'Preview closed',
    volume_removed: 'Removed from its service',
};

function state(volume: RetiredVolume): { state: StatusState; label: string } {
    if (volume.status === 'deleted') {
        return { state: 'idle', label: 'Deleted' };
    }

    if (volume.status === 'requested') {
        return volume.message
            ? { state: 'warning', label: 'Not deleted yet' }
            : { state: 'progress', label: 'Deleting' };
    }

    if (volume.needsConfirmation) {
        return { state: 'warning', label: 'Needs your OK' };
    }

    return { state: 'idle', label: 'Kept' };
}

/** "in 3 days", or null when it's due. */
function inDays(iso: string): string | null {
    const days = Math.ceil((new Date(iso).getTime() - Date.now()) / 86_400_000);

    return days <= 0 ? null : `in ${days} ${days === 1 ? 'day' : 'days'}`;
}

function until(iso: string): string {
    const days = inDays(iso);

    return days === null ? 'Deleted soon' : `Deleted ${days}`;
}

/**
 * Volumes of deleted environments, projects and previews (docs/05 §1.1),
 * as one row (direction §9.2) opening a sheet with every volume. Volumes
 * whose database had backups wait for an explicit OK.
 */
export function RetiredVolumes({
    serverId,
    volumes,
    canConfirm,
}: {
    serverId: string;
    volumes: RetiredVolume[];
    canConfirm: boolean;
}) {
    const [open, setOpen] = useState(false);

    if (volumes.length === 0) {
        return null;
    }

    const kept = volumes.filter((v) => v.status !== 'deleted');
    const waiting = volumes.filter(
        (v) => v.needsConfirmation && v.status === 'retained',
    ).length;
    const next = kept
        .map((v) => v.retainUntil)
        .sort()
        .at(0);

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="flex w-full items-center gap-4 rounded-xl border bg-card px-5 py-4 text-left shadow-card hover:border-border-strong focus-visible:ring-2 focus-visible:ring-signal focus-visible:outline-none"
            >
                <Archive
                    className="size-5 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <span className="flex min-w-0 flex-1 flex-col">
                    <span className="type-body font-medium">
                        {volumes.length} retired{' '}
                        {volumes.length === 1 ? 'volume' : 'volumes'}
                    </span>
                    <MetaLine className="type-small text-muted-foreground">
                        Data of deleted environments and previews
                        {next && `Next deletion ${inDays(next) ?? 'soon'}`}
                        {waiting > 0 &&
                            `${waiting} ${waiting === 1 ? 'needs' : 'need'} your OK`}
                    </MetaLine>
                </span>
                <span className="type-small text-signal">Review</span>
            </button>

            <SidePanel
                open={open}
                onOpenChange={setOpen}
                title="Retired volumes"
                description="Kept on this server until their retention ends, then deleted."
                size="md"
            >
                <ul className="flex flex-col divide-y">
                    {volumes.map((volume) => {
                        const { state: tone, label } = state(volume);
                        const confirmable =
                            canConfirm &&
                            volume.needsConfirmation &&
                            volume.status === 'retained';

                        return (
                            <li
                                key={volume.id}
                                className="flex flex-col gap-2 px-5 py-4"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div className="flex min-w-0 flex-col gap-0.5">
                                        <span className="type-body font-medium">
                                            {volume.label}
                                        </span>
                                        <MetaLine className="type-mono-small text-muted-foreground">
                                            {volume.name}
                                            {volume.mountPath}
                                        </MetaLine>
                                    </div>
                                    <Status
                                        state={tone}
                                        label={label}
                                        className="shrink-0 type-small"
                                    />
                                </div>
                                <div className="flex flex-wrap items-center justify-between gap-2 type-small text-muted-foreground">
                                    <MetaLine>
                                        {reasons[volume.reason] ??
                                            volume.reason}
                                        {volume.status === 'deleted'
                                            ? `Deleted ${shortAge(volume.deletedAt) ?? ''}`
                                            : until(volume.retainUntil)}
                                    </MetaLine>
                                    {confirmable && (
                                        <ConfirmPopover
                                            title="Allow deleting this volume?"
                                            consequence="The database backups stay in their destination. The volume is deleted when its retention ends."
                                            confirmLabel="Allow deletion"
                                            tone="destructive"
                                            onConfirm={() =>
                                                new Promise<void>((resolve) =>
                                                    router.post(
                                                        confirm({
                                                            server: serverId,
                                                            volume: volume.id,
                                                        }).url,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                            onFinish: () =>
                                                                resolve(),
                                                        },
                                                    ),
                                                )
                                            }
                                        >
                                            <Button size="sm" variant="outline">
                                                Allow deletion
                                            </Button>
                                        </ConfirmPopover>
                                    )}
                                </div>
                                {volume.message && (
                                    <p className="type-small text-muted-foreground">
                                        {volume.message}
                                    </p>
                                )}
                            </li>
                        );
                    })}
                </ul>
            </SidePanel>
        </>
    );
}
