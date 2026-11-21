import { router } from '@inertiajs/react';
import { Archive, MoreHorizontal, Plus } from 'lucide-react';
import { useState } from 'react';
import { Donut } from '@/components/charts/donut';
import { CopyValue } from '@/components/copy-value';
import { Logo } from '@/components/logo';
import { ConfirmPopover } from '@/components/patterns/confirm-popover';
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
import { Spinner } from '@/components/ui/spinner';
import { formatBytes, plural } from '@/lib/format';
import { requestJson } from '@/lib/http';
import { ActionReason } from './action-reason';
import { DestinationForm } from './destination-form';
import { DestinationStatus, failures } from './destination-status';
import { storageProvider } from './format';
import type {
    BackupStorage,
    Destination,
    RemovedDestination,
} from './page-types';

/**
 * What the storage holds: a donut only when it holds more than one kind
 * (the split is the information), else the plain size.
 */
function UsageStat({ usage }: { usage: NonNullable<Destination['usage']> }) {
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

    if (items.length === 1) {
        return (
            <div className="flex shrink-0 flex-col sm:items-end">
                <span className="type-title tabular-nums">
                    {formatBytes(total)}
                </span>
                <span className="type-small text-muted-foreground">
                    newest copies
                </span>
            </div>
        );
    }

    return (
        <Donut
            items={items}
            label="Newest copies by kind"
            centerValue={formatBytes(total)}
            centerLabel="newest copies"
            format={formatBytes}
            size={104}
            thickness={8}
            legend
        />
    );
}

function location(d: { bucket: string; prefix: string }): string {
    return `${d.bucket}${d.prefix !== '' ? `/${d.prefix}` : ''}`;
}

function repository(d: {
    endpoint: string;
    bucket: string;
    prefix: string;
}): string {
    const prefix = d.prefix.replace(/^\/+|\/+$/g, '');

    return `s3:${d.endpoint.replace(/\/+$/, '')}/${d.bucket}${prefix !== '' ? `/${prefix}` : ''}`;
}

/**
 * The repository password, for reading the backups with plain restic when
 * launchroom itself is gone (docs/14 §3). Fetched only on request (audited,
 * throttled) and kept in this panel's state until it closes.
 */
export function RepositoryPasswordPanel({
    storage,
    url,
    open,
    onOpenChange,
}: {
    storage: { name: string; endpoint: string; bucket: string; prefix: string };
    url: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [password, setPassword] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const show = async () => {
        setBusy(true);
        setError(null);

        try {
            const result = await requestJson<{ value: string }>(
                'post',
                url,
                {},
            );
            setPassword(result.value);
        } catch (failure) {
            setError(
                failure instanceof Error
                    ? failure.message
                    : 'Could not show the password.',
            );
        } finally {
            setBusy(false);
        }
    };

    return (
        <SidePanel
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    setPassword(null);
                    setError(null);
                }

                onOpenChange(next);
            }}
            size="md"
            title="Repository password"
            description={`With this password and an access key for ${storage.bucket}, restic can read the backups of ${storage.name} without launchroom.`}
        >
            <SheetSection>
                <div className="flex flex-col gap-4">
                    <div className="grid gap-2">
                        <span className="type-small text-muted-foreground">
                            Repository
                        </span>
                        <CopyValue
                            value={repository(storage)}
                            label="Copy repository"
                        />
                    </div>
                    {password === null ? (
                        <div className="flex flex-col items-start gap-3">
                            <p className="type-body text-muted-foreground">
                                Keep it outside launchroom, for example in a
                                password manager. Showing it is recorded in the
                                audit log.
                            </p>
                            <Button
                                variant="outline"
                                disabled={busy}
                                onClick={() => void show()}
                            >
                                {busy && <Spinner />}
                                Show password
                            </Button>
                            {error !== null && (
                                <p className="type-small text-status-error">
                                    {error}
                                </p>
                            )}
                        </div>
                    ) : (
                        <div className="grid gap-2">
                            <span className="type-small text-muted-foreground">
                                Password
                            </span>
                            <CopyValue value={password} label="Copy password" />
                            <p className="type-small text-muted-foreground">
                                Set it as RESTIC_PASSWORD, with
                                RESTIC_REPOSITORY and the bucket’s access key,
                                then run restic snapshots to list the backups.
                            </p>
                        </div>
                    )}
                </div>
            </SheetSection>
        </SidePanel>
    );
}

/** Edit a storage's endpoint, bucket, folder or keys (re-tests on save). */
export function EditStoragePanel({
    destination,
    open,
    onOpenChange,
}: {
    destination: Destination;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    if (destination.updateUrl === null) {
        return null;
    }

    return (
        <SidePanel
            open={open}
            onOpenChange={onOpenChange}
            size="md"
            title={`Edit ${destination.name}`}
            description="Moving the bucket or folder starts a new backup repository there."
        >
            <SheetSection>
                <DestinationForm
                    key={String(open)}
                    url={destination.updateUrl}
                    method="put"
                    destination={destination}
                    askRepositoryPassword={
                        destination.errorKind === 'wrong_repository_password'
                    }
                    submitLabel="Save and test"
                    onSuccess={() => onOpenChange(false)}
                />
            </SheetSection>
        </SidePanel>
    );
}

/**
 * A storage as an object card: provider, bucket, connection (by cause
 * when it fails, with the fix), last backup, what it holds. Test is the
 * visible action; edit and remove live behind `⋯`. Removing keeps the
 * backups in the bucket and their history here (owner decision 4).
 */
function StorageCard({ destination }: { destination: Destination }) {
    const [editing, setEditing] = useState(false);
    const [removing, setRemoving] = useState(false);
    const [testing, setTesting] = useState(false);
    const [revealing, setRevealing] = useState(false);
    const provider = storageProvider(destination.endpoint);
    const failure =
        destination.status === 'failing' && destination.errorKind !== null
            ? failures[destination.errorKind]
            : null;
    const runs = destination.runCount;
    const removeUrl = destination.removeUrl;

    return (
        <article className="flex flex-col gap-6 rounded-xl border bg-card p-5 shadow-card sm:flex-row sm:items-center sm:justify-between">
            <div className="flex min-w-0 flex-1 flex-col gap-4">
                <div className="flex min-w-0 items-start gap-4">
                    <Logo
                        kind={provider.logo}
                        size={44}
                        name={provider.label}
                    />
                    <div className="flex min-w-0 flex-1 flex-col gap-1">
                        <h3 className="truncate type-heading">
                            {destination.name}
                        </h3>
                        <p
                            className="truncate type-mono-small text-muted-foreground"
                            title={`${destination.endpoint}/${location(destination)}`}
                        >
                            {location(destination)}
                        </p>
                        {destination.sharedWithLaunchroom && (
                            <p className="type-small text-muted-foreground">
                                Also holds launchroom itself, in its own folder.
                            </p>
                        )}
                    </div>
                </div>

                <div className="flex flex-col gap-1">
                    <div className="flex flex-wrap items-center gap-x-4 gap-y-1 type-body">
                        <DestinationStatus destination={destination} />
                        <span className="text-muted-foreground">
                            {destination.usage?.lastBackupAt != null ? (
                                <>
                                    Last backup{' '}
                                    <TimeAgo
                                        value={destination.usage.lastBackupAt}
                                    />
                                </>
                            ) : (
                                'No backup yet'
                            )}
                        </span>
                    </div>
                    {failure !== null && (
                        <p className="type-small text-muted-foreground">
                            {failure.fix}
                            {destination.updateUrl !== null && (
                                <>
                                    {' '}
                                    <button
                                        type="button"
                                        className="rounded-sm font-medium text-signal underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        onClick={() => setEditing(true)}
                                    >
                                        {destination.errorKind ===
                                        'wrong_repository_password'
                                            ? 'Enter the password'
                                            : 'Change the connection'}
                                    </button>
                                </>
                            )}
                        </p>
                    )}
                    {destination.status === 'failing' &&
                        destination.lastError !== null && (
                            <p className="type-mono-small break-words text-muted-foreground">
                                {destination.lastError}
                            </p>
                        )}
                </div>

                {(destination.testUrl !== null || removeUrl !== null) && (
                    <div className="flex flex-wrap gap-2">
                        {destination.testUrl !== null && (
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={
                                    testing || destination.status === 'checking'
                                }
                                onClick={() =>
                                    router.post(
                                        destination.testUrl ?? '',
                                        {},
                                        {
                                            preserveScroll: true,
                                            onStart: () => setTesting(true),
                                            onFinish: () => setTesting(false),
                                        },
                                    )
                                }
                            >
                                {(testing ||
                                    destination.status === 'checking') && (
                                    <Spinner />
                                )}
                                {destination.status === 'checking'
                                    ? 'Testing'
                                    : 'Test connection'}
                            </Button>
                        )}
                        <ConfirmPopover
                            open={removing}
                            onOpenChange={setRemoving}
                            title={`Remove ${destination.name}?`}
                            consequence={
                                <>
                                    New backups stop until you add storage
                                    again.{' '}
                                    {runs > 0
                                        ? `The ${plural(runs, 'backup')} already in ${destination.bucket} stay there, and their history stays here.`
                                        : `Anything already in ${destination.bucket} stays there.`}{' '}
                                    launchroom keeps their repository password,
                                    so adding the same bucket and folder again
                                    picks them up.
                                </>
                            }
                            confirmLabel="Remove storage"
                            tone="destructive"
                            onConfirm={() =>
                                new Promise<void>((resolve) =>
                                    router.delete(removeUrl ?? '', {
                                        preserveScroll: true,
                                        onFinish: () => resolve(),
                                    }),
                                )
                            }
                        >
                            <DropdownMenu modal={false}>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        variant="ghost"
                                        size="icon-sm"
                                        aria-label={`More for ${destination.name}`}
                                    >
                                        <MoreHorizontal aria-hidden="true" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    {destination.updateUrl !== null && (
                                        <DropdownMenuItem
                                            onSelect={() => setEditing(true)}
                                        >
                                            Edit
                                        </DropdownMenuItem>
                                    )}
                                    {destination.repositoryPasswordUrl !==
                                        null && (
                                        <DropdownMenuItem
                                            onSelect={() => setRevealing(true)}
                                        >
                                            Repository password…
                                        </DropdownMenuItem>
                                    )}
                                    {removeUrl !== null && (
                                        <DropdownMenuItem
                                            variant="destructive"
                                            onSelect={() => setRemoving(true)}
                                        >
                                            Remove…
                                        </DropdownMenuItem>
                                    )}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </ConfirmPopover>
                    </div>
                )}
            </div>

            {destination.usage !== null && (
                <UsageStat usage={destination.usage} />
            )}

            <EditStoragePanel
                destination={destination}
                open={editing}
                onOpenChange={setEditing}
            />
            {destination.repositoryPasswordUrl !== null && (
                <RepositoryPasswordPanel
                    storage={destination}
                    url={destination.repositoryPasswordUrl}
                    open={revealing}
                    onOpenChange={setRevealing}
                />
            )}
        </article>
    );
}

/** Removed storage: its history stays readable, re-adding reconnects it. */
function RemovedRow({
    removed,
    onReconnect,
}: {
    removed: RemovedDestination;
    onReconnect?: (removed: RemovedDestination) => void;
}) {
    const [revealing, setRevealing] = useState(false);

    return (
        <li className="flex flex-wrap items-center gap-x-3.5 gap-y-2 px-4 py-3 sm:px-5">
            <span className="flex size-8 shrink-0 items-center justify-center rounded-[9px] border bg-card text-muted-foreground">
                <Archive aria-hidden="true" className="size-4" />
            </span>
            <div className="flex min-w-0 flex-1 basis-56 flex-col gap-0.5">
                <span className="truncate type-body font-medium">
                    {removed.name}
                    <span className="font-normal text-muted-foreground">
                        {' '}
                        removed <TimeAgo value={removed.removedAt} />
                    </span>
                </span>
                <span className="truncate type-small text-muted-foreground">
                    {plural(removed.runs, 'backup')} stay in{' '}
                    <span className="type-mono-small">{location(removed)}</span>
                    . Add it again to pick them up.
                </span>
            </div>
            {removed.repositoryPasswordUrl !== null && (
                <>
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => setRevealing(true)}
                    >
                        Repository password
                    </Button>
                    <RepositoryPasswordPanel
                        storage={removed}
                        url={removed.repositoryPasswordUrl}
                        open={revealing}
                        onOpenChange={setRevealing}
                    />
                </>
            )}
            {onReconnect && (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => onReconnect(removed)}
                >
                    Add again
                </Button>
            )}
        </li>
    );
}

/**
 * Where backups go (docs/14 §3): the team's storage as cards, then storage
 * that was removed, kept for its history.
 */
export function StorageSection({
    storage,
    onAdd,
    onReconnect,
}: {
    storage: BackupStorage;
    onAdd: () => void;
    onReconnect: (removed: RemovedDestination) => void;
}) {
    const canAdd = storage.add.url !== null;

    if (storage.destinations.length === 0 && storage.removed.length === 0) {
        return null;
    }

    return (
        <Section
            id="storage"
            data-setting="storage"
            title="Where backups go"
            description="Backups are encrypted on your servers before they’re uploaded."
            actions={
                canAdd &&
                storage.destinations.length > 0 && (
                    <Button variant="outline" size="sm" onClick={onAdd}>
                        <Plus aria-hidden="true" />
                        Add storage
                    </Button>
                )
            }
        >
            <div className="flex flex-col gap-3">
                {storage.destinations.map((destination) => (
                    <StorageCard
                        key={destination.id}
                        destination={destination}
                    />
                ))}
                {storage.destinations.length === 0 && (
                    <div className="flex flex-col items-start gap-3 rounded-xl border border-dashed p-5">
                        <p className="type-body">
                            No storage connected. New backups wait until you add
                            some.
                        </p>
                        {canAdd ? (
                            <Button size="sm" onClick={onAdd}>
                                Connect storage
                            </Button>
                        ) : (
                            storage.add.disabled !== null && (
                                <ActionReason reason={storage.add.disabled} />
                            )
                        )}
                    </div>
                )}
                {storage.removed.length > 0 && (
                    <ul
                        aria-label="Removed storage"
                        className="divide-y overflow-hidden rounded-xl border bg-card"
                    >
                        {storage.removed.map((removed) => (
                            <RemovedRow
                                key={removed.id}
                                removed={removed}
                                onReconnect={canAdd ? onReconnect : undefined}
                            />
                        ))}
                    </ul>
                )}
            </div>
        </Section>
    );
}
