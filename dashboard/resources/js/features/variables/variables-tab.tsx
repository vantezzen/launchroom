import { useQuery, useQueryClient } from '@tanstack/react-query';
import {
    AppWindow,
    ChevronRight,
    Drill,
    FileCode2,
    Hammer,
    KeyRound,
    Link2,
    Lock,
    MoreHorizontal,
    Plug,
    Plus,
    X,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { EmptyState } from '@/components/patterns/empty-state';
import {
    stagedChangesKey,
    useStagedChanges,
} from '@/features/staged-changes/use-staged-changes';
import { cn } from '@/lib/utils';
import { destroy, index } from '@/routes/variables';
import { usage } from '@/routes/variables/shared';
import { api } from './api';
import { looksLikeEnv } from './env-paste';
import { ImportDialog } from './import-dialog';
import { RawEditor } from './raw-editor';
import { MASK, RevealValue } from './reveal-value';
import { VariableDialog } from './variable-dialog';

export type VariablesTabProps = {
    project: string;
    environment: string;
    service: string;
};

type Data = App.Data.Variables.ServiceVariablesData;
type Row = App.Data.Variables.VariableRowData;

export function serviceVariablesKey(environment: string, service: string) {
    return ['service-variables', environment, service] as const;
}

function Hint({ label, children }: { label: string; children: ReactNode }) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span
                    role="img"
                    tabIndex={0}
                    aria-label={label}
                    className="inline-flex size-5 items-center justify-center rounded-md text-muted-foreground transition-colors duration-fast hover:bg-hover hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none [&_svg]:size-3.5"
                >
                    {children}
                </span>
            </TooltipTrigger>
            <TooltipContent className="max-w-72">{label}</TooltipContent>
        </Tooltip>
    );
}

function ExposureBadge({ exposure }: { exposure: App.Enums.VariableExposure }) {
    return (
        <Badge variant="outline">
            {(exposure === 'build' || exposure === 'both') && (
                <Hint label="Available while building. Changing it requires a rebuild.">
                    <Hammer
                        aria-hidden="true"
                        className="size-3.5 text-muted-foreground"
                    />
                </Hint>
            )}
            {(exposure === 'runtime' || exposure === 'both') && (
                <Hint label="Available at runtime">
                    <AppWindow
                        aria-hidden="true"
                        className="size-3.5 text-muted-foreground"
                    />
                </Hint>
            )}
        </Badge>
    );
}

/** Quiet icon cues with tooltips (direction §9.5): secret, reference, build, connection. */
function Badges({ row }: { row: Row }) {
    return (
        <span className="flex shrink-0 flex-wrap items-center gap-1">
            <ExposureBadge exposure={row.exposure} />
            {row.managedByFile && (
                <Hint
                    label={
                        row.type === 'secret'
                            ? 'launchroom.json requires this secret. Its value stays here.'
                            : 'Set in launchroom.json. Edit it there: the file wins on the next push.'
                    }
                >
                    <FileCode2 aria-hidden="true" />
                </Hint>
            )}
            {row.type === 'secret' && (
                <Hint label="Secret: write-only after saving and masked in logs. Revealing it is audited.">
                    <Lock aria-hidden="true" />
                </Hint>
            )}
            {row.references.length > 0 && (
                <Hint
                    label={`References ${row.references.join(', ')}. Resolved per environment when deploying.`}
                >
                    <Link2 aria-hidden="true" />
                </Hint>
            )}
            {row.exposure !== 'runtime' && (
                <Hint
                    label={
                        row.browserInlined
                            ? 'Baked into the browser bundle at build time and visible to anyone. Changing it requires a rebuild.'
                            : row.exposure === 'build'
                              ? 'Only available while building. Changing it requires a rebuild.'
                              : 'Available while building and at runtime. Changing it requires a rebuild.'
                    }
                >
                    <Hammer aria-hidden="true" />
                </Hint>
            )}
            {row.connection !== null && (
                <Hint
                    label={`Added by the connection to ${row.connection}. Remove the connection on the canvas to remove it.`}
                >
                    <Plug aria-hidden="true" />
                </Hint>
            )}
        </span>
    );
}

/**
 * Service panel → Variables (docs/06 §3.4): the variables of this service in
 * this environment with type, exposure, reference and connection badges,
 * inline resolved previews (secrets masked) and broken references in red.
 * Add, edit, delete, import and raw edits are staged. Paste a `.env`
 * anywhere on the tab to import it.
 */
export function VariablesTab({
    project,
    environment,
    service,
}: VariablesTabProps) {
    const queryClient = useQueryClient();
    const urls = { project, environment, service };
    const key = serviceVariablesKey(environment, service);
    const { data, isPending, isError, error } = useQuery({
        queryKey: key,
        queryFn: ({ signal }) =>
            api<Data>('get', index(urls).url, undefined, signal),
    });

    const staged = useStagedChanges(project, environment).data;
    // Rows changed since the last deploy (flows §8): path …variables.<id>.
    const stagedKinds = new Map<string, App.Enums.ChangeKind>();

    for (const item of staged?.groups.find(
        (group) => group.serviceId === service,
    )?.items ?? []) {
        const match = /\.(?:variables|sharedVariables)\.([^.]+)$/.exec(
            item.path,
        );

        if (match) {
            stagedKinds.set(match[1], item.kind);
        }
    }

    const [dialog, setDialog] = useState<{
        variable: Row | null;
        preset?: {
            key: string;
            value: string;
            buildTime?: boolean;
            type?: App.Enums.VariableType;
        };
    } | null>(null);
    const [importing, setImporting] = useState<string | null>(null);
    const [deleting, setDeleting] = useState<Row | null>(null);
    const [rawMode, setRawMode] = useState(false);

    const changed = (message: string) => {
        toast.success(message);
        void queryClient.invalidateQueries({ queryKey: key });
        void queryClient.invalidateQueries({
            queryKey: stagedChangesKey(environment),
        });
    };

    // "Paste a .env anywhere" (docs/06 §3.4): outside form fields and dialogs.
    const canWrite = data?.canWrite ?? false;
    const busy =
        dialog !== null || importing !== null || deleting !== null || rawMode;

    // ⌘K "Add variable to …" lands here with ?add=variable; a fix link
    // (missing variable on a failed deploy) with ?add=NAME&value=….
    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        const add = params.get('add');

        if (canWrite && add) {
            const value = params.get('value') ?? '';
            const buildTime = params.get('buildTime') === '1';
            const type =
                params.get('type') === 'secret'
                    ? ('secret' as const)
                    : undefined;
            params.delete('add');
            params.delete('value');
            params.delete('buildTime');
            params.delete('type');
            const query = params.toString();
            window.history.replaceState(
                window.history.state,
                '',
                `${window.location.pathname}${query ? `?${query}` : ''}`,
            );
            setDialog(
                add === 'variable' || !/^[A-Za-z_][A-Za-z0-9_]*$/.test(add)
                    ? { variable: null }
                    : {
                          variable:
                              data?.variables.find((row) => row.key === add) ??
                              null,
                          preset: { key: add, value, buildTime, type },
                      },
            );
        }
    }, [canWrite, data]);

    useEffect(() => {
        if (!canWrite || busy) {
            return;
        }

        const onPaste = (event: ClipboardEvent) => {
            const target = event.target as HTMLElement | null;

            if (
                target?.closest(
                    'input, textarea, select, [contenteditable="true"], [role="dialog"]',
                )
            ) {
                return;
            }

            const text = event.clipboardData?.getData('text') ?? '';

            if (looksLikeEnv(text)) {
                event.preventDefault();
                setImporting(text);
            }
        };

        document.addEventListener('paste', onPaste);

        return () => document.removeEventListener('paste', onPaste);
    }, [canWrite, busy]);

    if (isPending) {
        return (
            <div
                className="flex flex-col gap-3"
                aria-busy="true"
                aria-label="Loading variables"
            >
                <Skeleton className="h-8 w-48" />
                <Skeleton className="h-10 w-full" />
                <Skeleton className="h-10 w-full" />
                <Skeleton className="h-10 w-full" />
            </div>
        );
    }

    if (isError) {
        return (
            <p className="type-body text-status-error">
                Could not load variables: {error.message}
            </p>
        );
    }

    const environmentName =
        data.environments.find((item) => item.id === environment)?.name ??
        'this environment';

    const remove = async (row: Row) => {
        try {
            await api(
                'delete',
                destroy({ project, environment, variable: row.id }).url,
            );
            changed(`${row.key} removed. Deploy to apply it.`);
        } catch (failure) {
            toast.error(
                failure instanceof Error
                    ? failure.message
                    : 'Could not remove the variable.',
            );
        }
    };

    const setUsage = async (id: string, used: boolean) => {
        try {
            await api(
                'put',
                usage({ project, environment, service, variable: id }).url,
                { used },
            );
            changed(
                used
                    ? 'Shared variable added to this service. Deploy to apply it.'
                    : 'Shared variable removed from this service. Deploy to apply it.',
            );
        } catch (failure) {
            toast.error(
                failure instanceof Error
                    ? failure.message
                    : 'Could not change the shared variable.',
            );
        }
    };

    const broken = data.variables.filter((row) => row.errors.length > 0).length;

    return (
        <div className="@container flex flex-col gap-8">
            <section className="flex flex-col gap-3">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h3 className="type-heading">
                        Variables{' '}
                        <span className="type-small font-normal text-muted-foreground tabular-nums">
                            {data.variables.length}
                        </span>
                    </h3>
                    {canWrite && !rawMode && (
                        <div className="flex gap-2">
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => setRawMode(true)}
                            >
                                Raw .env
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setImporting('')}
                            >
                                Import .env
                            </Button>
                            <Button
                                size="sm"
                                onClick={() => setDialog({ variable: null })}
                            >
                                <Plus aria-hidden="true" />
                                Add variable
                            </Button>
                        </div>
                    )}
                </div>

                {broken > 0 && (
                    <p
                        role="alert"
                        className="flex items-center gap-1.5 type-body text-status-error"
                    >
                        <X aria-hidden="true" className="size-4" />
                        {broken === 1
                            ? '1 variable has a broken reference.'
                            : `${broken} variables have broken references.`}{' '}
                        Deploys are blocked until it’s fixed.
                    </p>
                )}

                {rawMode ? (
                    <RawEditor
                        urls={urls}
                        placeholder={data.secretPlaceholder}
                        onCancel={() => setRawMode(false)}
                        onSaved={(message) => {
                            setRawMode(false);
                            changed(message);
                        }}
                    />
                ) : data.variables.length === 0 ? (
                    <EmptyState
                        icon={KeyRound}
                        action={
                            canWrite && (
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        setDialog({ variable: null })
                                    }
                                >
                                    Add variable
                                </Button>
                            )
                        }
                    >
                        No variables in {environmentName} yet. Paste a .env
                        anywhere on this tab.
                    </EmptyState>
                ) : (
                    <table className="w-full table-fixed type-body">
                        <thead>
                            <tr className="border-b text-left type-label text-muted-foreground">
                                <th className="w-[38%] py-2 pr-3 pl-2 font-medium">
                                    Key
                                </th>
                                <th className="py-2 pr-3 font-medium">Value</th>
                                <th className="hidden w-28 py-2 pr-3 font-medium @lg:table-cell">
                                    Environments
                                </th>
                                <th className="w-10 py-2">
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {data.variables.map((row) => (
                                <tr
                                    key={row.id}
                                    className={cn(
                                        'align-top',
                                        row.overridden &&
                                            'text-muted-foreground',
                                        stagedKinds.has(row.id) &&
                                            'bg-signal-soft/40 [&>td:first-child]:shadow-[inset_2px_0_0_var(--signal)]',
                                    )}
                                >
                                    <td className="py-2.5 pr-3 pl-2">
                                        <div className="flex min-w-0 flex-col gap-1">
                                            <span className="flex min-w-0 items-center gap-1.5">
                                                <span
                                                    className="truncate type-mono"
                                                    title={row.key}
                                                >
                                                    {row.key}
                                                </span>
                                                <Badges row={row} />
                                            </span>
                                            {stagedKinds.has(row.id) && (
                                                <span className="type-small text-signal">
                                                    {stagedKinds.get(row.id) ===
                                                    'added'
                                                        ? 'New'
                                                        : 'Changed'}
                                                    <span className="sr-only">
                                                        {' '}
                                                        (staged)
                                                    </span>
                                                </span>
                                            )}
                                            {row.overridden && (
                                                <span className="type-small">
                                                    Overridden by a variable of
                                                    this service
                                                </span>
                                            )}
                                        </div>
                                    </td>
                                    <td className="min-w-0 py-2.5 pr-3">
                                        <div className="flex min-w-0 flex-col gap-1">
                                            {row.type === 'secret' ? (
                                                <RevealValue
                                                    project={project}
                                                    environment={environment}
                                                    variable={row.id}
                                                    name={row.key}
                                                    canReveal={row.canReveal}
                                                />
                                            ) : (
                                                <span className="type-mono [overflow-wrap:anywhere] whitespace-pre-wrap">
                                                    {row.value === '' ? (
                                                        <span className="text-muted-foreground">
                                                            Empty
                                                        </span>
                                                    ) : (
                                                        row.value
                                                    )}
                                                </span>
                                            )}
                                            {row.preview !== null &&
                                                row.errors.length === 0 && (
                                                    <span className="type-mono-small [overflow-wrap:anywhere] text-muted-foreground">
                                                        <span aria-hidden="true">
                                                            →{' '}
                                                        </span>
                                                        <span className="sr-only">
                                                            Resolves to{' '}
                                                        </span>
                                                        {row.preview}
                                                    </span>
                                                )}
                                            {row.errors.map((message) => (
                                                <span
                                                    key={message}
                                                    className="flex items-start gap-1 type-small text-status-error"
                                                >
                                                    <X
                                                        aria-hidden="true"
                                                        className="mt-0.5 size-3.5 shrink-0"
                                                    />
                                                    <span className="[overflow-wrap:anywhere]">
                                                        {message}
                                                    </span>
                                                </span>
                                            ))}
                                        </div>
                                    </td>
                                    <td className="hidden py-2.5 pr-3 @lg:table-cell">
                                        <span className="flex flex-wrap gap-1">
                                            {row.connection !== null ? (
                                                <span className="type-small text-muted-foreground">
                                                    Where connected
                                                </span>
                                            ) : (
                                                row.targets.map((target) => (
                                                    <Badge
                                                        key={`${target.environmentId}-${target.label}`}
                                                        variant="secondary"
                                                        className="font-normal"
                                                    >
                                                        {target.label}
                                                    </Badge>
                                                ))
                                            )}
                                        </span>
                                    </td>
                                    <td className="py-2">
                                        {row.canEdit && (
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="size-8"
                                                        aria-label={`Actions for ${row.key}`}
                                                    >
                                                        <MoreHorizontal />
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    <DropdownMenuItem
                                                        onSelect={() =>
                                                            setDialog({
                                                                variable: row,
                                                            })
                                                        }
                                                    >
                                                        Edit
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem
                                                        variant="destructive"
                                                        onSelect={() =>
                                                            setDeleting(row)
                                                        }
                                                    >
                                                        Remove
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </section>

            <Collapsible
                defaultOpen={data.shared.some((row) => row.used)}
                className="flex flex-col gap-3"
            >
                <CollapsibleTrigger className="group flex items-center gap-1.5 self-start type-heading">
                    <ChevronRight
                        aria-hidden="true"
                        className="size-4 transition-transform group-data-[state=open]:rotate-90"
                    />
                    Shared variables
                    <span className="type-small font-normal text-muted-foreground tabular-nums">
                        {data.shared.filter((row) => row.used).length} of{' '}
                        {data.shared.length} used
                    </span>
                </CollapsibleTrigger>
                <CollapsibleContent className="flex flex-col gap-2">
                    <p className="max-w-[65ch] type-small text-muted-foreground">
                        Project-wide values for {environmentName}. Use one here
                        to inject it under its own key, or reference it as{' '}
                        <code className="type-mono-small">
                            {'${{shared.KEY}}'}
                        </code>
                        .
                    </p>
                    {data.shared.length === 0 ? (
                        <p className="type-body text-muted-foreground">
                            No shared variables apply to {environmentName}.
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {data.shared.map((row) => (
                                <li
                                    key={row.id}
                                    className="flex items-start gap-3 py-2"
                                >
                                    <Checkbox
                                        className="mt-0.5"
                                        checked={row.used}
                                        disabled={!canWrite}
                                        aria-label={`Use ${row.key} in this service`}
                                        onCheckedChange={(checked) =>
                                            void setUsage(
                                                row.id,
                                                checked === true,
                                            )
                                        }
                                    />
                                    <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                                        <span className="flex items-center gap-2">
                                            <span className="type-mono">
                                                {row.key}
                                            </span>
                                            {row.type === 'secret' && (
                                                <Lock
                                                    aria-label="Secret"
                                                    className="size-3.5 text-muted-foreground"
                                                />
                                            )}
                                            <ExposureBadge
                                                exposure={row.exposure}
                                            />
                                        </span>
                                        <span className="type-mono-small [overflow-wrap:anywhere] text-muted-foreground">
                                            {row.type === 'secret'
                                                ? MASK
                                                : (row.preview ?? row.value)}
                                        </span>
                                        {row.errors.map((message) => (
                                            <span
                                                key={message}
                                                className="type-small text-status-error"
                                            >
                                                {message}
                                            </span>
                                        ))}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </CollapsibleContent>
            </Collapsible>

            <Collapsible className="flex flex-col gap-3">
                <CollapsibleTrigger className="group flex items-center gap-1.5 self-start type-heading">
                    <ChevronRight
                        aria-hidden="true"
                        className="size-4 transition-transform group-data-[state=open]:rotate-90"
                    />
                    System variables
                    <span className="type-small font-normal text-muted-foreground">
                        {data.system.length}, read-only
                    </span>
                </CollapsibleTrigger>
                <CollapsibleContent className="flex flex-col gap-2">
                    <p className="max-w-[65ch] type-small text-muted-foreground">
                        launchroom sets these at runtime for every deployment.
                        Your variables with the same name take precedence. To
                        use one while building, add it as a variable with Build
                        and runtime availability.
                    </p>
                    <dl className="divide-y">
                        {data.system.map((row) => (
                            <div
                                key={row.key}
                                className="grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)] gap-3 py-1.5"
                            >
                                <dt className="truncate type-mono-small">
                                    {row.key}
                                </dt>
                                <dd className="type-mono-small [overflow-wrap:anywhere] text-muted-foreground">
                                    {row.value}
                                </dd>
                            </div>
                        ))}
                    </dl>
                </CollapsibleContent>
            </Collapsible>

            {dialog && (
                <VariableDialog
                    key={dialog.variable?.id ?? dialog.preset?.key ?? 'new'}
                    open
                    preset={dialog.preset}
                    onOpenChange={(open) => !open && setDialog(null)}
                    data={data}
                    variable={dialog.variable}
                    urls={urls}
                    onSaved={changed}
                    onPasteEnv={(text) => {
                        setDialog(null);
                        setImporting(text);
                    }}
                />
            )}

            {importing !== null && (
                <ImportDialog
                    open
                    onOpenChange={(open) => !open && setImporting(null)}
                    initialContent={importing}
                    urls={urls}
                    environmentName={environmentName}
                    onImported={changed}
                />
            )}

            <Dialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
            >
                <DialogContent>
                    <DialogTitle>Remove {deleting?.key}?</DialogTitle>
                    <DialogDescription>
                        The variable is removed from every environment it
                        targets once you deploy. Until then you can discard the
                        change.
                    </DialogDescription>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="secondary">Keep it</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            onClick={() => {
                                if (deleting) {
                                    void remove(deleting);
                                }

                                setDeleting(null);
                            }}
                        >
                            Remove
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
