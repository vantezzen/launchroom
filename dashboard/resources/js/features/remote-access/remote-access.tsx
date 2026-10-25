import { useQueryClient } from '@tanstack/react-query';
import {
    Check,
    Copy,
    Database,
    Download,
    Eye,
    EyeOff,
    FolderOpen,
    KeyRound,
    LoaderCircle,
    Plus,
    ShieldCheck,
    SquareTerminal,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { ComponentType, ReactNode } from 'react';
import { TimeRangeSelect } from '@/components/charts/time-range-select';
import { Duration } from '@/components/elapsed';
import { Callout } from '@/components/patterns/callout';
import { MetaLine } from '@/components/patterns/meta-line';
import { Status } from '@/components/status';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { useClipboard } from '@/hooks/use-clipboard';
import { useNow } from '@/hooks/use-now';
import { cn } from '@/lib/utils';
import {
    endAccess,
    rememberKey,
    rememberedKey,
    startAccess,
    usePanel,
} from './api';
import type { Access, AccessKind, Panel, ServiceRef } from './api';
import {
    CERT_FILE,
    countdown,
    durationLabel,
    groupHex,
    snippets,
} from './connection';

type KindCopy = {
    button: string;
    title: string;
    icon: ComponentType<{ className?: string }>;
    /** What the start form explains next to its button. */
    explain: string;
};

const copy: Record<AccessKind, KindCopy> = {
    ssh: {
        button: 'Connect via SSH',
        title: 'Connect via SSH',
        icon: SquareTerminal,
        explain:
            'Runs inside the container as its own user, not on the server. Port forwarding is off.',
    },
    volume: {
        button: 'Connect with SFTP',
        title: 'Connect with SFTP',
        icon: FolderOpen,
        explain:
            'SFTP only, into this volume. No shell and no other files of the server or the service.',
    },
    database: {
        button: 'Temporary access',
        title: 'Temporary access',
        icon: Database,
        explain:
            'A user that exists only while this access runs. It can’t change roles or the schema.',
    },
};

/**
 * "Connect via SSH" (Console tab), "Connect with SFTP" (a volume) and
 * "Temporary access" (Data tab): a button that opens the access dialog
 * (D61, D67, docs/09 R39).
 */
export function RemoteAccessButton({
    serviceRef,
    kind,
    volume,
    variant = 'outline',
    size,
    className,
}: {
    serviceRef: ServiceRef;
    kind: AccessKind;
    /** The volume of a volume access (`vol_…`). */
    volume?: string;
    variant?: 'outline' | 'ghost';
    size?: 'sm' | 'default';
    className?: string;
}) {
    const [open, setOpen] = useState(false);
    const query = usePanel(serviceRef, kind, true, volume);
    const live = query.data?.accesses.find(
        (a) => a.isMine && a.status !== 'ended',
    );
    const Icon = kind === 'volume' ? FolderOpen : KeyRound;

    // Hidden where it can't work (an engine without temporary access, a
    // server that doesn't announce it) unless one is still open. A volume's
    // button stays: its dialog says why (not created yet, server too old).
    if (
        query.isPending ||
        !query.data ||
        (kind !== 'volume' &&
            !query.data.available &&
            query.data.accesses.every((a) => a.status === 'ended'))
    ) {
        return null;
    }

    return (
        <>
            <Button
                variant={variant}
                size={size}
                className={className}
                onClick={() => setOpen(true)}
            >
                <Icon aria-hidden="true" />
                {copy[kind].button}
                {live && (
                    <span className="flex items-center gap-1 type-small text-muted-foreground">
                        <span
                            aria-hidden="true"
                            className="size-1.5 rounded-full bg-status-ok"
                        />
                        Open
                    </span>
                )}
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[calc(100dvh-2rem)] gap-0 overflow-y-auto p-0 sm:max-w-[640px]">
                    <AccessDialog
                        serviceRef={serviceRef}
                        kind={kind}
                        panel={query.data}
                        refresh={() => void query.refetch()}
                    />
                </DialogContent>
            </Dialog>
        </>
    );
}

function AccessDialog({
    serviceRef,
    kind,
    panel,
    refresh,
}: {
    serviceRef: ServiceRef;
    kind: AccessKind;
    panel: Panel;
    refresh: () => void;
}) {
    const mine = panel.accesses.find((a) => a.isMine && a.status !== 'ended');
    const others = panel.accesses.filter(
        (a) => !a.isMine && a.status !== 'ended',
    );
    // The access this dialog showed last: once it ends, its summary stays
    // until the user starts a new one.
    const [shown, setShown] = useState<string | null>(null);
    const ended = shown
        ? panel.accesses.find((a) => a.id === shown && a.status === 'ended')
        : undefined;

    useEffect(() => {
        if (mine) {
            setShown(mine.id);
        }
    }, [mine]);

    return (
        <>
            <Header kind={kind} panel={panel} access={mine} />
            <div className="flex flex-col gap-5 px-4 pt-1 pb-6 sm:px-6">
                {mine ? (
                    <LiveAccess
                        access={mine}
                        kind={kind}
                        panel={panel}
                        refresh={refresh}
                    />
                ) : ended ? (
                    <EndedAccess
                        access={ended}
                        kind={kind}
                        onAgain={() => setShown(null)}
                        available={panel.available}
                    />
                ) : !panel.available ? (
                    <Unavailable reason={panel.unavailableReason} />
                ) : (
                    <StartForm
                        serviceRef={serviceRef}
                        kind={kind}
                        panel={panel}
                        onStarted={refresh}
                    />
                )}
                {others.length > 0 && (
                    <div className="flex flex-col gap-2">
                        <p className="type-label">Open for others</p>
                        <div className="divide-y overflow-hidden rounded-xl border bg-card">
                            {others.map((a) => (
                                <OtherAccess
                                    key={a.id}
                                    access={a}
                                    refresh={refresh}
                                />
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}

// ---- header ----

/** What the access opens: icon tile, title, the target in one quiet line. */
function Header({
    kind,
    panel,
    access,
}: {
    kind: AccessKind;
    panel: Panel;
    access?: Access;
}) {
    const Icon = copy[kind].icon;

    return (
        <div className="flex items-start gap-4 px-4 pt-6 pr-12 pb-5 sm:px-6">
            <span
                aria-hidden="true"
                className="flex size-11 shrink-0 items-center justify-center rounded-xl border bg-recessed text-foreground"
            >
                <Icon className="size-5" />
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <DialogTitle className="type-heading">
                    {copy[kind].title}
                </DialogTitle>
                <DialogDescription asChild>
                    <div className="type-small text-muted-foreground">
                        <MetaLine>
                            {panel.serviceName}
                            {panel.environmentName}
                            {kind === 'volume' && panel.mountPath && (
                                <code className="type-mono-small text-foreground">
                                    {panel.mountPath}
                                </code>
                            )}
                            {kind === 'volume' && panel.volumeServer
                                ? `on ${panel.volumeServer}`
                                : null}
                            {kind === 'database' ? panel.engineLabel : null}
                            {kind === 'ssh' && access
                                ? `${access.replicaLabel}${access.serverName ? ` on ${access.serverName}` : ''}`
                                : null}
                        </MetaLine>
                    </div>
                </DialogDescription>
            </div>
        </div>
    );
}

// ---- states without an access ----

function Unavailable({ reason }: { reason: string | null }) {
    return (
        <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed bg-recessed px-6 py-10 text-center">
            <span
                aria-hidden="true"
                className="flex size-10 items-center justify-center rounded-full border bg-card text-muted-foreground"
            >
                <KeyRound className="size-4" />
            </span>
            <div className="flex max-w-sm flex-col gap-1">
                <p className="type-label">Not available right now</p>
                {reason && (
                    <p className="type-small text-muted-foreground">{reason}</p>
                )}
            </div>
        </div>
    );
}

function EndedAccess({
    access,
    kind,
    available,
    onAgain,
}: {
    access: Access;
    kind: AccessKind;
    available: boolean;
    onAgain: () => void;
}) {
    const seconds =
        access.endedAt === null
            ? null
            : (Date.parse(access.endedAt) - Date.parse(access.createdAt)) /
              1000;

    return (
        <div className="flex flex-col items-center gap-4 rounded-xl border bg-card px-6 py-9 text-center">
            <span
                aria-hidden="true"
                className="flex size-10 items-center justify-center rounded-full border bg-recessed text-muted-foreground"
            >
                <ShieldCheck className="size-4" />
            </span>
            <div className="flex max-w-sm flex-col items-center gap-1.5">
                <Status
                    state="idle"
                    label={
                        access.endedByOther
                            ? 'Ended by an owner or admin'
                            : 'Access ended'
                    }
                />
                <p className="type-small text-muted-foreground">
                    {access.endReason && access.endReason !== 'Ended.'
                        ? `${access.endReason} `
                        : ''}
                    {kind === 'database'
                        ? 'The temporary user is gone.'
                        : 'The port is closed and the credentials no longer work.'}
                </p>
                <MetaLine className="type-small text-muted-foreground">
                    {seconds !== null && (
                        <span>
                            Open for <Duration seconds={seconds} />
                        </span>
                    )}
                    {`${access.connections} ${access.connections === 1 ? 'connection' : 'connections'}`}
                </MetaLine>
            </div>
            {available && (
                <Button variant="outline" onClick={onAgain}>
                    Start a new access
                </Button>
            )}
        </div>
    );
}

// ---- starting ----

function StartForm({
    serviceRef,
    kind,
    panel,
    onStarted,
}: {
    serviceRef: ServiceRef;
    kind: AccessKind;
    panel: Panel;
    onStarted: () => void;
}) {
    const queryClient = useQueryClient();
    const usesSsh = kind !== 'database';
    const [allow, setAllow] = useState<string[]>(
        panel.requesterPrefix ? [panel.requesterPrefix] : [],
    );
    const [draft, setDraft] = useState('');
    const remembered = useMemo(() => rememberedKey(), []);
    const [method, setMethod] = useState<'key' | 'password'>(
        usesSsh && remembered !== '' ? 'key' : 'password',
    );
    const [key, setKey] = useState(remembered);
    const [duration, setDuration] = useState(String(panel.defaultDuration));
    const [writes, setWrites] = useState(false);
    const [workload, setWorkload] = useState<string | null>(
        panel.replicas[0]?.workload ?? null,
    );
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const addDraft = () => {
        const value = draft.trim();

        if (value !== '' && !allow.includes(value)) {
            setAllow([...allow, value]);
        }

        setDraft('');
    };

    const submit = async () => {
        setBusy(true);
        setError(null);

        try {
            const keys =
                usesSsh && method === 'key'
                    ? key
                          .split('\n')
                          .map((l) => l.trim())
                          .filter((l) => l !== '')
                    : [];
            await startAccess(serviceRef, kind, {
                allowFrom: draft.trim() ? [...allow, draft.trim()] : allow,
                publicKeys: keys,
                durationMinutes: Number(duration),
                allowWrites: kind !== 'ssh' && writes,
                workload: kind === 'ssh' ? workload : null,
                volume: kind === 'volume' ? panel.volumeId : null,
            });

            if (keys.length > 0) {
                rememberKey(keys.join('\n'));
            }

            await queryClient.invalidateQueries({
                queryKey: ['remote-access', kind],
            });
            onStarted();
        } catch (failure) {
            setError(
                failure instanceof Error
                    ? failure.message
                    : 'The access couldn’t be started.',
            );
        } finally {
            setBusy(false);
        }
    };

    return (
        <form
            className="flex flex-col gap-5"
            onSubmit={(e) => {
                e.preventDefault();
                void submit();
            }}
        >
            <div className="divide-y overflow-hidden rounded-xl border bg-card">
                {kind === 'ssh' && panel.replicas.length > 1 && (
                    <FormRow label="Replica">
                        <Select
                            value={workload ?? undefined}
                            onValueChange={setWorkload}
                        >
                            <SelectTrigger
                                aria-label="Replica"
                                className="w-full sm:w-64"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {panel.replicas.map((r) => (
                                    <SelectItem
                                        key={r.workload}
                                        value={r.workload}
                                    >
                                        {r.label} on {r.serverName}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </FormRow>
                )}

                {usesSsh && (
                    <FormRow label="Sign in with">
                        <div
                            role="radiogroup"
                            aria-label="Sign in with"
                            className="grid grid-cols-1 gap-2 sm:grid-cols-2"
                        >
                            {(['key', 'password'] as const).map((m) => (
                                <button
                                    key={m}
                                    type="button"
                                    role="radio"
                                    aria-checked={method === m}
                                    onClick={() => setMethod(m)}
                                    className={cn(
                                        'flex items-start gap-2.5 rounded-lg border px-3 py-2.5 text-left transition-colors duration-fast',
                                        method === m
                                            ? 'border-foreground bg-card'
                                            : 'hover:border-border-strong hover:bg-hover',
                                    )}
                                >
                                    <span
                                        aria-hidden="true"
                                        className={cn(
                                            'mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full border',
                                            method === m &&
                                                'border-foreground bg-foreground text-background',
                                        )}
                                    >
                                        {method === m && (
                                            <Check
                                                className="size-2.5"
                                                strokeWidth={3}
                                            />
                                        )}
                                    </span>
                                    <span className="flex flex-col gap-0.5">
                                        <span className="type-label">
                                            {m === 'key'
                                                ? 'Public key'
                                                : 'Password'}
                                        </span>
                                        <span className="type-small text-muted-foreground">
                                            {m === 'key'
                                                ? 'No password is created.'
                                                : 'Generated, shown once it starts.'}
                                        </span>
                                    </span>
                                </button>
                            ))}
                        </div>
                        {method === 'key' && (
                            <Textarea
                                value={key}
                                onChange={(e) => setKey(e.target.value)}
                                placeholder="ssh-ed25519 AAAA… you@laptop"
                                className="min-h-20 type-mono-small"
                                aria-label="Public key"
                            />
                        )}
                    </FormRow>
                )}

                <FormRow
                    label="Who can connect"
                    hint="Only these addresses reach the port. Yours is filled in."
                >
                    {allow.length > 0 && (
                        <div className="flex flex-wrap gap-1.5">
                            {allow.map((p) => (
                                <span
                                    key={p}
                                    className="inline-flex h-7 items-center gap-1 rounded-md border bg-recessed pr-1 pl-2.5 type-mono-small"
                                >
                                    {p}
                                    <button
                                        type="button"
                                        className="rounded p-0.5 text-muted-foreground hover:bg-hover hover:text-foreground"
                                        aria-label={`Remove ${p}`}
                                        onClick={() =>
                                            setAllow(
                                                allow.filter((x) => x !== p),
                                            )
                                        }
                                    >
                                        <X
                                            aria-hidden="true"
                                            className="size-3"
                                        />
                                    </button>
                                </span>
                            ))}
                        </div>
                    )}
                    <div className="flex gap-2">
                        <Input
                            value={draft}
                            onChange={(e) => setDraft(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    addDraft();
                                }
                            }}
                            placeholder="203.0.113.0/24"
                            className="type-mono"
                            aria-label="Add an address"
                        />
                        <Button
                            type="button"
                            variant="outline"
                            onClick={addDraft}
                            disabled={draft.trim() === ''}
                        >
                            <Plus aria-hidden="true" />
                            Add
                        </Button>
                    </div>
                </FormRow>

                {(kind === 'database' ||
                    (kind === 'volume' && panel.writable)) && (
                    <FormRow
                        label={
                            kind === 'volume' ? 'Allow changes' : 'Allow writes'
                        }
                        hint={
                            kind === 'volume'
                                ? writes
                                    ? 'You can upload, change and delete files.'
                                    : 'Read-only. Turn on to upload or change files.'
                                : writes
                                  ? 'The user can insert, update and delete rows.'
                                  : 'Read-only. Turn on to change rows.'
                        }
                        inline
                        control={
                            <Switch
                                checked={writes}
                                onCheckedChange={setWrites}
                                aria-label={
                                    kind === 'volume'
                                        ? 'Allow changes'
                                        : 'Allow writes'
                                }
                            />
                        }
                    >
                        {kind === 'volume' && writes && (
                            <Callout
                                tone="warning"
                                title="The service keeps running"
                            >
                                It can write to the same files while you change
                                them. For larger changes, stop the service first
                                or take a backup.
                            </Callout>
                        )}
                    </FormRow>
                )}

                {kind === 'volume' && !panel.writable && (
                    <div className="px-5 py-4">
                        <Callout title="Opens read-only">
                            A database changes these files while it runs, so a
                            copy taken here may not restore. Use Backups for a
                            copy and Temporary access to change data.
                        </Callout>
                    </div>
                )}

                <FormRow
                    label="Ends after"
                    hint={`Or after ${panel.idleMinutes} minutes without use.`}
                    control={
                        <TimeRangeSelect
                            value={duration}
                            onChange={setDuration}
                            label="Ends after"
                            options={panel.durations.map((d) => ({
                                value: String(d),
                                label: d < 60 ? `${d}m` : `${d / 60}h`,
                            }))}
                        />
                    }
                />
            </div>

            {error && (
                <p role="alert" className="type-small text-status-error">
                    {error}
                </p>
            )}

            <div className="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p className="max-w-sm type-small text-muted-foreground">
                    {copy[kind].explain}
                </p>
                <Button
                    type="submit"
                    disabled={
                        busy ||
                        (allow.length === 0 && draft.trim() === '') ||
                        (usesSsh && method === 'key' && key.trim() === '')
                    }
                    className="shrink-0"
                >
                    {busy && (
                        <LoaderCircle
                            aria-hidden="true"
                            className="motion-safe:animate-spin"
                        />
                    )}
                    Start access for {durationLabel(Number(duration))}
                </Button>
            </div>
        </form>
    );
}

/** A form row inside the start card: label and hint, then the control. */
function FormRow({
    label,
    hint,
    control,
    inline = false,
    children,
}: {
    label: string;
    hint?: string;
    /** A compact control beside the label (switch, segmented). */
    control?: ReactNode;
    /** Keep the control beside the label on phones too (a switch). */
    inline?: boolean;
    children?: ReactNode;
}) {
    return (
        <div className="flex flex-col gap-3 px-5 py-4">
            <div
                className={cn(
                    'flex items-center justify-between gap-3',
                    !inline && 'flex-wrap',
                )}
            >
                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <span className="type-label">{label}</span>
                    {hint && (
                        <span className="type-small text-muted-foreground">
                            {hint}
                        </span>
                    )}
                </div>
                {control}
            </div>
            {children}
        </div>
    );
}

// ---- running ----

function LiveAccess({
    access,
    kind,
    panel,
    refresh,
}: {
    access: Access;
    kind: AccessKind;
    panel: Panel;
    refresh: () => void;
}) {
    const now = useNow();
    const [ending, setEnding] = useState(false);
    const ready = access.port !== null && access.host !== null;
    const total =
        (Date.parse(access.expiresAt) - Date.parse(access.createdAt)) / 1000;
    const left = (Date.parse(access.expiresAt) - now) / 1000;
    const lastUse = Date.parse(access.lastActivityAt ?? access.createdAt);
    const idleLeft = access.idleSeconds - (now - lastUse) / 1000;
    const host = access.host ?? '';
    const share = total > 0 ? Math.max(0, Math.min(1, left / total)) : 0;

    const end = async () => {
        setEnding(true);

        try {
            await endAccess(access.id);
        } finally {
            refresh();
            window.setTimeout(refresh, 3000);
        }
    };

    const state = ending
        ? { label: 'Ending…', tone: 'progress' as const }
        : !ready
          ? { label: 'Starting the endpoint', tone: 'progress' as const }
          : access.activeConnections > 0
            ? {
                  label:
                      access.activeConnections === 1
                          ? 'One client connected'
                          : `${access.activeConnections} clients connected`,
                  tone: 'active' as const,
              }
            : { label: 'Waiting for your client', tone: 'staged' as const };

    return (
        <>
            <section
                aria-label="Session"
                className="overflow-hidden rounded-xl border bg-card"
            >
                <div className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                    <span role="status" aria-live="polite">
                        <Status state={state.tone} label={state.label} />
                    </span>
                    <Button
                        variant="destructive"
                        size="sm"
                        disabled={ending}
                        onClick={() => void end()}
                    >
                        End now
                    </Button>
                </div>
                <div className="grid grid-cols-2 border-t">
                    <div className="flex flex-col gap-0.5 px-4 py-3">
                        <span className="type-small text-muted-foreground">
                            Ends in
                        </span>
                        <span className="type-stat tabular-nums">
                            {countdown(left)}
                        </span>
                    </div>
                    <div className="flex flex-col gap-0.5 border-l px-4 py-3">
                        <span className="type-small text-muted-foreground">
                            {access.activeConnections > 0
                                ? 'Closes when idle for'
                                : 'Closes unused in'}
                        </span>
                        <span className="type-stat text-muted-foreground tabular-nums">
                            {access.activeConnections > 0
                                ? countdown(access.idleSeconds)
                                : countdown(Math.min(idleLeft, left))}
                        </span>
                    </div>
                </div>
                <div
                    role="progressbar"
                    aria-label="Time left"
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-valuenow={Math.round(share * 100)}
                    className="h-0.5 bg-hover"
                >
                    <div
                        className="h-full bg-signal transition-[width] duration-base ease-enter"
                        style={{ width: `${share * 100}%` }}
                    />
                </div>
            </section>

            {ready ? (
                <>
                    <Credentials
                        access={access}
                        host={host}
                        mountPath={access.mountPath ?? panel.mountPath}
                    />
                    <Snippets
                        access={access}
                        host={host}
                        serviceName={panel.serviceName}
                    />
                    <Verification access={access} kind={kind} />
                    {(access.behindNat ||
                        access.host === access.privateAddresses[0]) && (
                        <Callout
                            tone="warning"
                            title="This server may not be reachable from the internet"
                        >
                            {access.privateAddresses.length > 0
                                ? `It looks like it sits behind a router. From the same network, connect to ${access.privateAddresses[0]}. From elsewhere, forward port ${access.port} on the router or use your VPN.`
                                : `It looks like it sits behind a router. Forward port ${access.port} on the router, or connect through your VPN.`}
                        </Callout>
                    )}
                </>
            ) : (
                <div className="flex flex-col items-center gap-2 rounded-xl bg-recessed px-6 py-8 text-center">
                    <p className="flex items-center gap-2 type-label">
                        <LoaderCircle
                            aria-hidden="true"
                            className="size-4 text-status-progress motion-safe:animate-spin"
                        />
                        Opening a port on {access.serverName ?? 'the server'}
                    </p>
                    <p className="type-small text-muted-foreground">
                        Usually a few seconds. The details appear here.
                    </p>
                </div>
            )}

            <div className="flex flex-col gap-2 border-t pt-4">
                <div className="flex flex-wrap items-center gap-1.5">
                    <span className="mr-1 type-small text-muted-foreground">
                        Open to
                    </span>
                    {access.allowFrom.map((p) => (
                        <span
                            key={p}
                            className="rounded-md border bg-recessed px-2 py-0.5 type-mono-small"
                        >
                            {p}
                        </span>
                    ))}
                    {access.allowWrites && kind !== 'ssh' && (
                        <span className="rounded-md border border-status-progress/40 px-2 py-0.5 type-small text-status-progress">
                            {kind === 'volume'
                                ? 'Changes allowed'
                                : 'Writes allowed'}
                        </span>
                    )}
                </div>
                <p className="type-small text-muted-foreground">
                    If a connection times out, the server’s firewall may need
                    ports 40100–40199 open.
                </p>
            </div>
        </>
    );
}

/**
 * Host, port, user and password as rows with one-click copy; for a volume
 * also what "/" is and who files belong to.
 */
function Credentials({
    access,
    host,
    mountPath,
}: {
    access: Access;
    host: string;
    mountPath: string | null;
}) {
    const rows: { label: string; value: string; secret?: boolean }[] = [
        { label: 'Host', value: host },
        { label: 'Port', value: String(access.port) },
        { label: 'User', value: access.username },
        ...(access.password
            ? [{ label: 'Password', value: access.password, secret: true }]
            : []),
        ...(access.database
            ? [{ label: 'Database', value: access.database }]
            : []),
    ];

    return (
        <dl className="divide-y overflow-hidden rounded-xl border bg-card">
            {rows.map((r) => (
                <div
                    key={r.label}
                    className="flex min-w-0 items-center gap-3 py-1.5 pr-1.5 pl-4"
                >
                    <dt className="w-20 shrink-0 type-small text-muted-foreground">
                        {r.label}
                    </dt>
                    <dd className="min-w-0 flex-1">
                        <CopyValue
                            value={r.value}
                            label={r.label}
                            secret={r.secret}
                        />
                    </dd>
                </div>
            ))}
            {access.kind === 'volume' && (
                <Fact label="Folder">
                    <code className="type-mono-small text-foreground">/</code>{' '}
                    is{' '}
                    <code className="type-mono-small text-foreground">
                        {mountPath}
                    </code>{' '}
                    in the service{access.allowWrites ? '' : ', read-only'}
                </Fact>
            )}
            {access.kind === 'volume' && access.fileOwner && (
                <Fact label="Saved as">
                    <code className="type-mono-small text-foreground">
                        {access.fileOwner}
                    </code>
                    , the owner of the volume’s top folder
                </Fact>
            )}
            {access.authMethod === 'key' && access.keyFingerprints[0] && (
                <div className="flex min-w-0 items-center gap-3 py-2.5 pr-4 pl-4">
                    <dt className="w-20 shrink-0 type-small text-muted-foreground">
                        Key
                    </dt>
                    <dd className="min-w-0 flex-1 truncate type-mono-small text-muted-foreground">
                        {access.keyFingerprints[0]}
                    </dd>
                </div>
            )}
        </dl>
    );
}

/** A read-only row of the credentials card. */
function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex min-w-0 items-center gap-3 py-2.5 pr-4 pl-4">
            <dt className="w-20 shrink-0 type-small text-muted-foreground">
                {label}
            </dt>
            <dd className="min-w-0 flex-1 type-small text-muted-foreground">
                {children}
            </dd>
        </div>
    );
}

/** Ready-made commands per client, one tab each. */
function Snippets({
    access,
    host,
    serviceName,
}: {
    access: Access;
    host: string;
    serviceName: string;
}) {
    const list = snippets(access, host, serviceName);

    return (
        <section
            aria-label="Connect from your computer"
            className="flex flex-col gap-2.5"
        >
            <Tabs defaultValue={list[0]?.id} className="gap-2.5">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="type-label">
                        Connect from your computer
                    </span>
                    <TabsList className="max-w-full overflow-x-auto">
                        {list.map((s) => (
                            <TabsTrigger
                                key={s.id}
                                value={s.id}
                                className="font-mono text-[12px]"
                            >
                                {s.label}
                            </TabsTrigger>
                        ))}
                    </TabsList>
                </div>
                {list.map((s) => (
                    <TabsContent
                        key={s.id}
                        value={s.id}
                        className="flex flex-col gap-1.5"
                    >
                        <CodeBlock
                            value={s.value}
                            label={s.label}
                            secret={s.secret}
                        />
                        <p className="type-small text-muted-foreground">
                            {s.hint}
                        </p>
                    </TabsContent>
                ))}
            </Tabs>
        </section>
    );
}

function Verification({ access, kind }: { access: Access; kind: AccessKind }) {
    if (kind !== 'database') {
        return (
            <details className="group rounded-xl border bg-card">
                <summary className="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 type-label [&::-webkit-details-marker]:hidden">
                    <span className="flex items-center gap-2">
                        <ShieldCheck
                            aria-hidden="true"
                            className="size-4 text-muted-foreground"
                        />
                        Verify the host key
                    </span>
                    <span className="type-small font-normal text-muted-foreground group-open:hidden">
                        Show
                    </span>
                </summary>
                <div className="flex flex-col gap-3 border-t px-4 py-3.5">
                    <p className="type-small text-muted-foreground">
                        Your client shows this fingerprint on the first
                        connection. Compare it, or add the line to
                        ~/.ssh/known_hosts first.
                    </p>
                    {access.hostKeyFingerprint && (
                        <LabeledCode
                            label="Fingerprint"
                            value={access.hostKeyFingerprint}
                        />
                    )}
                    {access.knownHostsLine && (
                        <LabeledCode
                            label="known_hosts line"
                            value={access.knownHostsLine}
                        />
                    )}
                </div>
            </details>
        );
    }

    return (
        <details className="group rounded-xl border bg-card">
            <summary className="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 type-label [&::-webkit-details-marker]:hidden">
                <span className="flex items-center gap-2">
                    <ShieldCheck
                        aria-hidden="true"
                        className="size-4 text-muted-foreground"
                    />
                    Verify the certificate
                </span>
                <span className="type-small font-normal text-muted-foreground group-open:hidden">
                    Show
                </span>
            </summary>
            <div className="flex flex-col gap-3 border-t px-4 py-3.5">
                <p className="type-small text-muted-foreground">
                    Connections are encrypted with a certificate made for this
                    access. Download it to verify the server, or compare the
                    SHA-256 fingerprint.
                </p>
                {access.certificateSha256 && (
                    <LabeledCode
                        label="Certificate SHA-256"
                        value={groupHex(access.certificateSha256)}
                    />
                )}
                {access.certificatePem && (
                    <Button
                        variant="outline"
                        size="sm"
                        className="self-start"
                        asChild
                    >
                        <a
                            href={`data:application/x-pem-file;charset=utf-8,${encodeURIComponent(access.certificatePem)}`}
                            download={CERT_FILE}
                        >
                            <Download aria-hidden="true" />
                            Download certificate
                        </a>
                    </Button>
                )}
            </div>
        </details>
    );
}

function OtherAccess({
    access,
    refresh,
}: {
    access: Access;
    refresh: () => void;
}) {
    const [ending, setEnding] = useState(false);

    return (
        <div className="flex items-center justify-between gap-3 px-4 py-2.5">
            <div className="flex min-w-0 flex-col gap-0.5">
                <span className="truncate type-body">
                    {access.userName}
                    {access.kind === 'ssh' &&
                        ` in ${access.replicaLabel.toLowerCase()}${access.serverName ? ` on ${access.serverName}` : ''}`}
                </span>
                <MetaLine className="type-small text-muted-foreground">
                    {`Port ${access.port ?? '…'}`}
                    {`Open to ${access.allowFrom.join(', ')}`}
                </MetaLine>
            </div>
            {access.canEnd && (
                <Button
                    variant="ghost"
                    size="sm"
                    disabled={ending}
                    onClick={() => {
                        setEnding(true);
                        void endAccess(access.id).finally(refresh);
                    }}
                >
                    End
                </Button>
            )}
        </div>
    );
}

// ---- values ----

function mask(value: string): string {
    return value
        .replace(/:[^:@/]+@/, ':••••••••@')
        .replace(/^[A-Za-z0-9]{24,}$/, '••••••••••••••••');
}

function CopyButton({ value, label }: { value: string; label: string }) {
    const [copied, doCopy] = useClipboard();

    return (
        <Button
            type="button"
            variant="ghost"
            size="icon"
            className="size-8 shrink-0"
            aria-label={copied === value ? 'Copied' : `Copy ${label}`}
            onClick={() => void doCopy(value)}
        >
            {copied === value ? (
                <Check className="size-4 text-status-ok" />
            ) : (
                <Copy className="size-4" />
            )}
        </Button>
    );
}

function RevealButton({
    shown,
    label,
    onToggle,
}: {
    shown: boolean;
    label: string;
    onToggle: () => void;
}) {
    return (
        <Button
            type="button"
            variant="ghost"
            size="icon"
            className="size-8 shrink-0"
            aria-label={shown ? `Hide ${label}` : `Show ${label}`}
            onClick={onToggle}
        >
            {shown ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
        </Button>
    );
}

/** A mono value in a row with copy; secrets stay masked until revealed. */
function CopyValue({
    value,
    label,
    secret,
}: {
    value: string;
    label: string;
    secret?: boolean;
}) {
    const [shown, setShown] = useState(!secret);

    return (
        <div className="flex min-w-0 items-center gap-0.5">
            <code className="min-w-0 flex-1 truncate type-mono">
                {shown ? value : mask(value)}
            </code>
            {secret && (
                <RevealButton
                    shown={shown}
                    label={label}
                    onToggle={() => setShown(!shown)}
                />
            )}
            <CopyButton value={value} label={label} />
        </div>
    );
}

/** A recessed code block with copy (snippets, config blocks). */
function CodeBlock({
    value,
    label,
    secret,
}: {
    value: string;
    label: string;
    secret?: boolean;
}) {
    const [shown, setShown] = useState(!secret);

    return (
        <div className="flex min-w-0 items-start gap-1 rounded-lg border bg-recessed py-1.5 pr-1.5 pl-4">
            <pre className="min-w-0 flex-1 overflow-x-auto py-1.5 type-mono-small [overflow-wrap:anywhere] whitespace-pre-wrap">
                {shown ? value : mask(value)}
            </pre>
            {secret && (
                <RevealButton
                    shown={shown}
                    label={label}
                    onToggle={() => setShown(!shown)}
                />
            )}
            <CopyButton value={value} label={label} />
        </div>
    );
}

function LabeledCode({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex min-w-0 flex-col gap-1">
            <span className="type-small text-muted-foreground">{label}</span>
            <CodeBlock value={value} label={label} />
        </div>
    );
}
