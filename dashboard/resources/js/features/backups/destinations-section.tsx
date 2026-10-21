import { router } from '@inertiajs/react';
import { MoreHorizontal, Plus } from 'lucide-react';
import { useState } from 'react';
import { Donut } from '@/components/charts/donut';
import { Logo } from '@/components/logo';
import { Section } from '@/components/patterns/section';
import { SidePanel, SheetSection } from '@/components/patterns/sheet';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
} from '@/components/ui/popover';
import { DestinationForm } from '@/features/backups/destination-form';
import { DestinationStatus } from '@/features/backups/destination-status';
import { formatBytes, storageProvider } from '@/features/backups/format';
import { destroy, test } from '@/routes/backups/destinations';

type Destination = App.Data.Backups.BackupDestinationData;
type Usage = App.Data.Backups.DestinationUsageData;

/** "Add destination" / "Edit …" sheet around the destination form. */
export function DestinationPanel({
    open,
    onOpenChange,
    scope,
    destination,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    scope: App.Enums.Backups.DestinationScope;
    destination?: Destination;
}) {
    return (
        <SidePanel
            open={open}
            onOpenChange={onOpenChange}
            size="md"
            title={
                destination
                    ? `Edit ${destination.name}`
                    : scope === 'instance'
                      ? 'Back up launchroom itself'
                      : 'Add a destination'
            }
            description={
                destination
                    ? 'Moving the endpoint, bucket or folder starts a new backup repository there.'
                    : 'Any S3-compatible bucket. Backups are encrypted before they leave your servers.'
            }
        >
            <SheetSection>
                <DestinationForm
                    destination={destination}
                    scope={scope}
                    onSuccess={() => onOpenChange(false)}
                />
            </SheetSection>
        </SidePanel>
    );
}

function UsageDonut({ usage }: { usage: Usage }) {
    const items = [
        {
            key: 'database',
            label: 'Databases',
            value: usage.databaseBytes,
            slot: 1,
        },
        { key: 'volume', label: 'Volumes', value: usage.volumeBytes, slot: 2 },
        {
            key: 'launchroom',
            label: 'launchroom',
            value: usage.controlPlaneBytes,
            slot: 3,
        },
    ].filter((item) => item.value > 0);
    const total = items.reduce((sum, item) => sum + item.value, 0);

    if (total === 0) {
        return null;
    }

    return (
        <Donut
            items={items.length === 1 ? [{ ...items[0], slot: null }] : items}
            label="Newest snapshots by kind"
            centerValue={formatBytes(total)}
            centerLabel="latest copy"
            format={formatBytes}
            size={104}
            thickness={8}
            legend={items.length > 1}
        />
    );
}

/**
 * A destination as an object card (direction §9.14): provider, bucket, connection and last backup on the left, what
 * it holds on the right. Test is the one visible action; edit and remove
 * live behind `⋯` (remove confirmed in a popover).
 */
export function DestinationCard({
    destination,
    usage,
    badge,
}: {
    destination: Destination;
    usage?: Usage;
    badge?: string;
}) {
    const [editing, setEditing] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const provider = storageProvider(destination.endpoint);
    const location = `${destination.bucket}${destination.prefix !== '' ? `/${destination.prefix}` : ''}`;

    return (
        <article className="flex flex-col gap-6 rounded-xl border bg-card p-5 shadow-card sm:flex-row sm:items-center sm:justify-between">
            <div className="flex min-w-0 flex-1 flex-col gap-5">
                <div className="flex min-w-0 items-start gap-4">
                    <Logo
                        kind={provider.logo}
                        size={44}
                        name={provider.label}
                    />
                    <div className="flex min-w-0 flex-1 flex-col gap-1">
                        <div className="flex min-w-0 flex-wrap items-center gap-2">
                            <h3 className="truncate type-heading">
                                {destination.name}
                            </h3>
                            {(badge ??
                                (destination.isDefault ? 'Default' : null)) && (
                                <span className="inline-flex h-6 items-center rounded-full bg-hover px-2.5 type-small text-muted-foreground">
                                    {badge ?? 'Default'}
                                </span>
                            )}
                        </div>
                        <p
                            className="truncate type-mono-small text-muted-foreground"
                            title={`${destination.endpoint}/${location}`}
                        >
                            {location}
                        </p>
                    </div>
                </div>

                <div className="flex flex-col gap-1">
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1 type-body">
                        <DestinationStatus status={destination.status} />
                        <span className="text-muted-foreground">
                            {usage?.lastBackupAt ? (
                                <>
                                    Last backup{' '}
                                    <TimeAgo value={usage.lastBackupAt} />
                                </>
                            ) : (
                                'No backup yet'
                            )}
                        </span>
                    </div>
                    {destination.lastError !== null &&
                        destination.status === 'failing' && (
                            <p className="pl-5 type-mono-small break-words text-muted-foreground">
                                {destination.lastError}
                            </p>
                        )}
                </div>

                {destination.canManage && (
                    <div className="flex flex-wrap gap-2">
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    test(destination.id).url,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Test connection
                        </Button>
                        <Popover open={confirming} onOpenChange={setConfirming}>
                            <DropdownMenu modal={false}>
                                <PopoverAnchor asChild>
                                    <DropdownMenuTrigger asChild>
                                        <Button
                                            variant="ghost"
                                            size="icon-sm"
                                            aria-label={`Options for ${destination.name}`}
                                        >
                                            <MoreHorizontal aria-hidden="true" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                </PopoverAnchor>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        onSelect={() => setEditing(true)}
                                    >
                                        Edit
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        className="text-status-error focus:text-status-error"
                                        onSelect={() => setConfirming(true)}
                                    >
                                        Remove…
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                            <PopoverContent
                                align="end"
                                className="flex w-80 flex-col gap-3"
                            >
                                <div className="flex flex-col gap-1">
                                    <p className="type-label">
                                        Remove {destination.name}?
                                    </p>
                                    <p className="type-body text-muted-foreground">
                                        Its schedules stop. Snapshots already in
                                        the bucket stay there.
                                    </p>
                                </div>
                                <div className="flex justify-end gap-2">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => setConfirming(false)}
                                    >
                                        Cancel
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="destructive"
                                        onClick={() =>
                                            router.delete(
                                                destroy(destination.id).url,
                                                {
                                                    preserveScroll: true,
                                                    onFinish: () =>
                                                        setConfirming(false),
                                                },
                                            )
                                        }
                                    >
                                        Remove destination
                                    </Button>
                                </div>
                            </PopoverContent>
                        </Popover>
                    </div>
                )}
            </div>

            {usage && <UsageDonut usage={usage} />}

            <DestinationPanel
                open={editing}
                onOpenChange={setEditing}
                scope={destination.scope}
                destination={destination}
            />
        </article>
    );
}

/**
 * Where backups go (docs/14 §3): the team's destinations as cards; the
 * empty state offers the storage providers.
 */
export function DestinationsSection({
    destinations,
    canManage,
    usage,
    instanceDestination = null,
}: {
    destinations: Destination[];
    canManage: boolean;
    usage: Usage[];
    /** launchroom's own destination (instance owners only). */
    instanceDestination?: Destination | null;
}) {
    const [adding, setAdding] = useState(false);

    return (
        <Section
            title="Where backups go"
            description="Encrypted on your servers before anything leaves."
            actions={
                canManage &&
                destinations.length > 0 && (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setAdding(true)}
                    >
                        <Plus aria-hidden="true" />
                        Add destination
                    </Button>
                )
            }
        >
            {destinations.length === 0 ? (
                <div className="flex flex-col items-center gap-5 rounded-xl border border-dashed px-6 py-10 text-center">
                    <div className="flex gap-2" aria-hidden="true">
                        {['cloudflare', 'hetzner', 'backblaze', 's3'].map(
                            (kind) => (
                                <Logo
                                    key={kind}
                                    kind={kind}
                                    size={44}
                                    decorative
                                />
                            ),
                        )}
                    </div>
                    <div className="flex max-w-sm flex-col gap-1">
                        <p className="type-heading">
                            Keep a copy of your databases off this server
                        </p>
                        <p className="type-body text-muted-foreground">
                            Any S3-compatible bucket works. Daily backups start
                            right away.
                        </p>
                    </div>
                    {canManage && (
                        <Button onClick={() => setAdding(true)}>
                            Add destination
                        </Button>
                    )}
                </div>
            ) : (
                <div className="flex flex-col gap-3">
                    {destinations.map((destination) => (
                        <DestinationCard
                            key={destination.id}
                            destination={destination}
                            usage={usage.find(
                                (item) => item.destinationId === destination.id,
                            )}
                        />
                    ))}
                </div>
            )}

            {instanceDestination !== null && (
                <DestinationCard
                    destination={instanceDestination}
                    badge="launchroom itself"
                    usage={usage.find(
                        (item) => item.destinationId === instanceDestination.id,
                    )}
                />
            )}

            {canManage && (
                <DestinationPanel
                    open={adding}
                    onOpenChange={setAdding}
                    scope="team"
                />
            )}
        </Section>
    );
}
