import { router } from '@inertiajs/react';
import { useQuery } from '@tanstack/react-query';
import { TriangleAlert, Undo2 } from 'lucide-react';
import { useState, useSyncExternalStore } from 'react';
import { ConfirmPopover } from '@/components/patterns/confirm-popover';
import { SidePanel } from '@/components/patterns/sheet';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { StagedConfigNote } from '@/features/config-as-code/staged-config-note';
import { requestJson } from '@/features/projects/http';
import { cn } from '@/lib/utils';
import { deploy, discard, summary } from '@/routes/staged-changes';
import { StageOption } from './deploy-dialog';
import { displayValue } from './effect-badge';
import type { StagedChanges } from './use-staged-changes';
import { effectLabel, effectSummary } from './use-staged-changes';

/* Open state, shared by the top-bar pill, the panel StagedBar and `/changes`. */
let sheetOpen = false;
const listeners = new Set<() => void>();

function emit(open: boolean): void {
    sheetOpen = open;
    listeners.forEach((listener) => listener());
}

export function openStagedChanges(): void {
    emit(true);
}

export function useStagedSheetOpen(): [boolean, (open: boolean) => void] {
    const open = useSyncExternalStore(
        (listener) => {
            listeners.add(listener);

            return () => listeners.delete(listener);
        },
        () => sheetOpen,
        () => false,
    );

    return [open, emit];
}

type Item = App.Data.Specs.ChangeItem;

const gutters: Record<
    App.Enums.ChangeKind,
    { sign: string; label: string; className: string; sign_: string }
> = {
    added: {
        sign: '+',
        label: 'Added',
        className: 'bg-status-ok-soft/60',
        sign_: 'text-status-ok',
    },
    removed: {
        sign: '−',
        label: 'Removed',
        className: 'bg-status-error-soft/60',
        sign_: 'text-status-error',
    },
    changed: {
        sign: '~',
        label: 'Changed',
        className: 'bg-signal-soft/60',
        sign_: 'text-signal',
    },
};

/** Deploys everything staged in the environment (the one Deploy everywhere). */
export function deployStaged(
    project: string,
    environment: string,
    options: {
        message?: string | null;
        stage?: boolean;
        onFinish?: () => void;
        onSuccess?: () => void;
        onError?: (message: string) => void;
    } = {},
): void {
    router.post(
        deploy({ project, environment }).url,
        {
            message: options.message?.trim() ? options.message.trim() : null,
            stage: options.stage ?? false,
            stay: true,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => options.onSuccess?.(),
            onError: (errors) =>
                options.onError?.(
                    Object.values(errors)[0] ?? 'The deploy failed.',
                ),
            onFinish: () => options.onFinish?.(),
        },
    );
}

export function discardStaged(
    project: string,
    environment: string,
    paths: string[] | null,
): Promise<void> {
    return new Promise((resolve) =>
        router.post(
            discard({ project, environment }).url,
            paths === null ? {} : { paths },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => resolve(),
            },
        ),
    );
}

export function ChangeRow({
    item,
    prefix,
    onDiscard,
    managedBy,
}: {
    item: Item;
    prefix: string | null;
    onDiscard?: () => void;
    /** launchroom.json sets this item (docs/18 §3.5). */
    managedBy?: string | null;
}) {
    const gutter = gutters[item.kind];
    const label =
        prefix && item.label.startsWith(prefix)
            ? item.label.slice(prefix.length)
            : item.label;

    return (
        <li
            className={cn(
                'group flex items-start gap-2 rounded-md py-1.5 pr-1.5',
                gutter.className,
            )}
        >
            <span
                aria-hidden="true"
                className={cn(
                    'w-6 shrink-0 text-center type-mono',
                    gutter.sign_,
                )}
            >
                {gutter.sign}
            </span>
            <span className="sr-only">{gutter.label}:</span>
            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <span className="flex flex-wrap items-center gap-x-2 type-body">
                    {label}
                    {managedBy && (
                        <span
                            title={`Set by ${managedBy}. The next push overwrites it.`}
                            className="rounded-md bg-hover px-1.5 py-px type-mono-small text-muted-foreground"
                        >
                            {managedBy}
                        </span>
                    )}
                </span>
                {item.kind === 'changed' &&
                    (item.before !== null || item.after !== null) && (
                        <span className="flex flex-wrap items-baseline gap-x-1.5 type-mono-small text-muted-foreground">
                            <span className="break-all line-through decoration-muted-foreground/60">
                                {displayValue(item.before)}
                            </span>
                            <span aria-hidden="true">→</span>
                            <span className="sr-only">changed to</span>
                            <span className="break-all text-foreground">
                                {displayValue(item.after)}
                            </span>
                        </span>
                    )}
            </div>
            {onDiscard && (
                <Button
                    variant="ghost"
                    size="icon-sm"
                    aria-label={`Discard: ${label}`}
                    title="Discard"
                    className="opacity-100 group-hover:opacity-100 focus-visible:opacity-100 md:opacity-0"
                    onClick={onDiscard}
                >
                    <Undo2 aria-hidden="true" />
                </Button>
            )}
        </li>
    );
}

/** Callout for broken references (design-system §6 callouts: no red frame). */
export function Blockers({ blockers }: { blockers: string[] }) {
    return (
        <div
            role="alert"
            className="flex flex-col gap-2 rounded-xl border bg-recessed px-4 py-3"
        >
            <p className="flex items-center gap-1.5 type-label">
                <TriangleAlert
                    aria-hidden="true"
                    className="size-4 text-status-error"
                />
                Broken references block this deploy.
            </p>
            <ul className="flex flex-col gap-0.5">
                {blockers.map((blocker) => (
                    <li
                        key={blocker}
                        className="type-mono-small break-all text-muted-foreground"
                    >
                        {blocker}
                    </li>
                ))}
            </ul>
            <p className="type-small text-muted-foreground">
                Fix them in the service’s settings under Variables, then deploy.
            </p>
        </div>
    );
}

/**
 * "Review & deploy" (direction §9.9, flows §5): the staged changes as a
 * sheet over the page, grouped by service with +/~/− gutters and one effect
 * per service, per-item discard, an optional message and Deploy. Deploying
 * keeps you where you are, so the canvas shows the nodes going live.
 */
export function StagedChangesSheet({
    project,
    environment,
}: {
    project: string;
    environment: string;
}) {
    const [open, setOpen] = useStagedSheetOpen();
    const [message, setMessage] = useState('');
    const [stage, setStage] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const { data } = useQuery({
        queryKey: ['staged-changes', environment, 'blockers'],
        enabled: open,
        staleTime: 0,
        queryFn: ({ signal }) =>
            requestJson<StagedChanges>(
                'get',
                summary({ project, environment }, { query: { blockers: 1 } })
                    .url,
                undefined,
                signal,
            ),
    });

    const canStage =
        data?.environmentKind === 'production' && !data.neverDeployed;
    const canDiscard = Boolean(data?.canDeploy && !data.neverDeployed);
    const effects = data ? effectSummary(data) : '';
    const nothing =
        data !== undefined && data.count === 0 && !data.neverDeployed;

    const title = !data
        ? 'Staged changes'
        : data.neverDeployed
          ? `Deploy ${data.environmentName}`
          : `${data.count} staged ${data.count === 1 ? 'change' : 'changes'}`;

    return (
        <SidePanel
            open={open}
            onOpenChange={setOpen}
            size="md"
            title={title}
            description={
                !data
                    ? undefined
                    : data.neverDeployed
                      ? 'The first deploy starts every service.'
                      : nothing
                        ? `Nothing is staged in ${data.environmentName}.`
                        : effects ||
                          'These changes apply without restarting a service.'
            }
            footer={
                data?.canDeploy && !nothing ? (
                    <div className="flex flex-col gap-3">
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="staged-message" className="sr-only">
                                Message
                            </Label>
                            <Input
                                id="staged-message"
                                value={message}
                                maxLength={500}
                                placeholder="What changed? (optional)"
                                onChange={(event) =>
                                    setMessage(event.target.value)
                                }
                            />
                        </div>
                        {canStage && (
                            <StageOption checked={stage} onChange={setStage} />
                        )}
                        {error && (
                            <p className="type-small text-status-error">
                                {error}
                            </p>
                        )}
                        <div className="flex items-center justify-between gap-2">
                            {canDiscard ? (
                                <ConfirmPopover
                                    align="start"
                                    title={`Discard all ${data.count} changes?`}
                                    consequence={`Everything goes back to how revision ${data.revision ?? 0} left it. New secrets you entered are lost.`}
                                    confirmLabel="Discard all"
                                    tone="destructive"
                                    onConfirm={() =>
                                        discardStaged(
                                            project,
                                            environment,
                                            null,
                                        )
                                    }
                                >
                                    <Button variant="ghost">Discard all</Button>
                                </ConfirmPopover>
                            ) : (
                                <span />
                            )}
                            <Button
                                disabled={
                                    processing || data.blockers.length > 0
                                }
                                title={
                                    data.blockers.length > 0
                                        ? 'Fix the broken references first'
                                        : undefined
                                }
                                onClick={() => {
                                    setProcessing(true);
                                    setError(null);
                                    deployStaged(project, environment, {
                                        message,
                                        stage: canStage && stage,
                                        onSuccess: () => {
                                            setMessage('');
                                            setOpen(false);
                                        },
                                        onError: setError,
                                        onFinish: () => setProcessing(false),
                                    });
                                }}
                            >
                                {processing && <Spinner />}
                                Deploy
                            </Button>
                        </div>
                    </div>
                ) : undefined
            }
        >
            {!data ? (
                <div className="flex items-center gap-2 px-5 py-6 type-body text-muted-foreground">
                    <Spinner /> Loading changes
                </div>
            ) : (
                <>
                    {data.blockers.length > 0 && (
                        <section className="px-5 pt-5">
                            <Blockers blockers={data.blockers} />
                        </section>
                    )}
                    {data.configFile && (
                        <section className="px-5 pt-5">
                            <StagedConfigNote config={data.configFile} />
                        </section>
                    )}
                    {data.groups.map((group) => (
                        <section
                            key={group.serviceId ?? 'environment'}
                            className="flex flex-col gap-2 px-5 py-4"
                        >
                            <div className="flex items-center gap-2">
                                <h3 className="type-label">{group.name}</h3>
                                {group.serviceId && group.effect !== 'none' && (
                                    <span
                                        className={cn(
                                            'inline-flex h-5 items-center rounded-full bg-hover px-2 type-small text-muted-foreground',
                                            group.effect === 'remove' &&
                                                'text-status-error',
                                        )}
                                    >
                                        {effectLabel(group.effect)}
                                    </span>
                                )}
                            </div>
                            {group.items.length === 0 ? (
                                <p className="type-small text-muted-foreground">
                                    Restarts because a variable it references
                                    changed.
                                </p>
                            ) : (
                                <ul className="flex flex-col gap-1">
                                    {group.items.map((item) => (
                                        <ChangeRow
                                            key={item.path}
                                            item={item}
                                            managedBy={
                                                data.configFile?.managedPaths.includes(
                                                    item.path,
                                                )
                                                    ? data.configFile.path
                                                    : null
                                            }
                                            prefix={
                                                group.slug
                                                    ? `${group.slug}: `
                                                    : null
                                            }
                                            onDiscard={
                                                canDiscard
                                                    ? () =>
                                                          void discardStaged(
                                                              project,
                                                              environment,
                                                              [item.path],
                                                          )
                                                    : undefined
                                            }
                                        />
                                    ))}
                                </ul>
                            )}
                        </section>
                    ))}
                </>
            )}
        </SidePanel>
    );
}
