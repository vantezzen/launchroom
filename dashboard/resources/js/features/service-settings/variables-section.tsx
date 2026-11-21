import { useQuery, useQueryClient } from '@tanstack/react-query';
import {
    ChevronRight,
    FileCode2,
    Hammer,
    KeyRound,
    Layers,
    Link2,
    Lock,
    MoreHorizontal,
    Plug,
    Plus,
    Search,
    Wrench,
    X,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
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
import { SettingsPage, useSettingsPage } from './section';
import {
    stagedChangesKey,
    useStagedChanges,
} from '@/features/staged-changes/use-staged-changes';
import { cn } from '@/lib/utils';
import { plural } from '@/lib/format';
import { destroy, index } from '@/routes/variables';
import { usage } from '@/routes/variables/shared';
import { requestJson } from '@/lib/http';
import { looksLikeEnv } from '@/features/variables/env-paste';
import { ImportDialog } from '@/features/variables/import-dialog';
import { RawEditor } from '@/features/variables/raw-editor';
import { MASK, RevealValue } from '@/features/variables/reveal-value';
import { VariableDialog } from '@/features/variables/variable-dialog';
import {
    applyFix,
    brokenSummary,
    fixFor,
} from '@/features/variables/reference-fix';
import { SettingsCard } from '@/components/settings/settings-card';
import { grammar } from '@/types/grammar.generated';

type VariablesSectionProps = {
    project: string;
    environment: string;
    service: string;
};

type Data = App.Data.Variables.ServiceVariablesData;
type Row = App.Data.Variables.VariableRowData;

function serviceVariablesKey(environment: string, service: string) {
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

/** The build cue (direction §9.5): only build-time variables carry it. */
function BuildHint({
    exposure,
    browserInlined = false,
}: {
    exposure: App.Enums.VariableExposure;
    browserInlined?: boolean;
}) {
    if (exposure === 'runtime') {
        return null;
    }

    return (
        <Hint
            label={
                browserInlined
                    ? 'Baked into the browser bundle at build time and visible to anyone. Changing it requires a rebuild.'
                    : exposure === 'build'
                      ? 'Only available while building. Changing it requires a rebuild.'
                      : 'Available while building and at runtime. Changing it requires a rebuild.'
            }
        >
            <Hammer aria-hidden="true" />
        </Hint>
    );
}

/** Quiet icon cues with tooltips (direction §9.5): secret, reference, build, connection. */
function Badges({ row }: { row: Row }) {
    return (
        <span className="flex shrink-0 items-center gap-0.5">
            {row.managedByFile && (
                <Hint
                    label={
                        row.type === 'secret'
                            ? 'launchroom.json requires this secret. Its value stays here.'
                            : 'Set in launchroom.json. Edit it there, because the next push overwrites changes made here.'
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
            <BuildHint
                exposure={row.exposure}
                browserInlined={row.browserInlined}
            />
            {row.connection === null && row.inheritedFrom !== null && (
                <Hint
                    label={`Set on ${row.inheritedFrom}. This service runs ${row.inheritedFrom}'s code, so it gets the same value. Change it on ${row.inheritedFrom}, or override it here.`}
                >
                    <Layers aria-hidden="true" />
                </Hint>
            )}
            {row.connection !== null && (
                <Hint
                    label={
                        row.inheritedFrom !== null
                            ? `Comes from the connection of ${row.inheritedFrom} to ${row.connection}, because this runs ${row.inheritedFrom}'s code. Change it on ${row.inheritedFrom}.`
                            : `Added by the connection to ${row.connection}. Remove the connection on the canvas to remove it.`
                    }
                >
                    <Plug aria-hidden="true" />
                </Hint>
            )}
        </span>
    );
}

/**
 * Service settings → Variables (docs/06 §3.4, docs/08 §16): the variables
 * of this service in this environment with type, exposure, reference and
 * connection badges, inline resolved previews (secrets masked) and broken
 * references in red. Add, edit, delete, import and raw edits are staged.
 * Paste a `.env` anywhere on the page to import it (only while the page is
 * the open one: settings pages stay mounted once visited).
 */
export function VariablesSection({
    project,
    environment,
    service,
}: VariablesSectionProps) {
    const queryClient = useQueryClient();
    const { active } = useSettingsPage();
    const urls = { project, environment, service };
    const key = serviceVariablesKey(environment, service);
    const { data, isPending, isError, error } = useQuery({
        queryKey: key,
        queryFn: ({ signal }) =>
            requestJson<Data>('get', index(urls).url, undefined, signal),
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
    const [search, setSearch] = useState('');
    const [fixing, setFixing] = useState<string | null>(null);

    useEffect(() => {
        if (fixing === null) {
            return;
        }

        const timer = window.setTimeout(() => setFixing(null), 2400);

        return () => window.clearTimeout(timer);
    }, [fixing]);

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
        dialog !== null ||
        importing !== null ||
        deleting !== null ||
        rawMode ||
        !active;

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
                add === 'variable' || !grammar.envName.test(add)
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

    const usedBy =
        data && data.usedBy.length > 0
            ? ` Also used by ${data.usedBy.join(', ')}.`
            : '';
    const description = canWrite
        ? `Changes apply with the next deploy.${usedBy} Paste a .env anywhere on this page to import it.`
        : `Changes apply with the next deploy.${usedBy}`;

    if (isPending) {
        return (
            <SettingsPage title="Variables" description={description}>
                <div
                    className="flex flex-col gap-3"
                    aria-busy="true"
                    aria-label="Loading variables"
                >
                    <Skeleton className="h-10 w-full" />
                    <Skeleton className="h-10 w-full" />
                    <Skeleton className="h-10 w-full" />
                </div>
            </SettingsPage>
        );
    }

    if (isError) {
        return (
            <SettingsPage title="Variables">
                <p className="type-body text-status-error">
                    Could not load variables: {error.message}
                </p>
            </SettingsPage>
        );
    }

    const environmentName =
        data.environments.find((item) => item.id === environment)?.name ??
        'this environment';

    const remove = async (row: Row) => {
        try {
            await requestJson(
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
            await requestJson(
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

    const brokenText = brokenSummary(data.variables);
    const firstBroken = data.variables.find(
        (row) => row.errors.length > 0 && row.canEdit,
    );

    // Fix: brings the first broken row into view (search cleared so it's
    // there), marks it for a moment and focuses its first suggestion.
    const showFix = (row: Row) => {
        setSearch('');
        setFixing(row.id);
        window.requestAnimationFrame(() => {
            const element = document.getElementById(`variable-${row.id}`);
            const reduce = window.matchMedia(
                '(prefers-reduced-motion: reduce)',
            ).matches;
            element?.scrollIntoView({
                behavior: reduce ? 'auto' : 'smooth',
                block: 'center',
            });
            element
                ?.querySelector<HTMLElement>('[data-fix-action]')
                ?.focus({ preventScroll: true });
        });
    };

    const applySuggestion = (
        row: Row,
        expression: string,
        suggestion: string,
    ) =>
        setDialog({
            variable: row,
            preset: {
                key: row.key,
                value: applyFix(row.value ?? '', expression, suggestion),
            },
        });
    const usedShared = data.shared.filter((row) => row.used).length;
    // The Environments column only when some row reaches past this one.
    const showTargets = data.variables.some(
        (row) =>
            row.inheritedFrom !== null ||
            (row.connection === null &&
                otherTargets(row, environment).length > 0),
    );
    const query = search.trim().toLowerCase();
    const rows =
        query === ''
            ? data.variables
            : data.variables.filter((row) =>
                  [
                      row.key,
                      row.type === 'secret' ? '' : row.value,
                      row.preview ?? '',
                  ]
                      .join(' ')
                      .toLowerCase()
                      .includes(query),
              );

    return (
        <SettingsPage
            title="Variables"
            description={description}
            actions={
                canWrite &&
                !rawMode && (
                    <>
                        <Button
                            variant="ghost"
                            size="sm"
                            data-setting="raw-env"
                            onClick={() => setRawMode(true)}
                        >
                            Raw .env
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            data-setting="import-env"
                            onClick={() => setImporting('')}
                        >
                            Import .env
                        </Button>
                        <Button
                            size="sm"
                            data-setting="add-variable"
                            onClick={() => setDialog({ variable: null })}
                        >
                            <Plus aria-hidden="true" />
                            Add variable
                        </Button>
                    </>
                )
            }
        >
            <div className="@container flex flex-col gap-8">
                <section
                    aria-label={`Variables in ${environmentName}`}
                    className="flex flex-col gap-3"
                    data-setting="variables-list"
                >
                    {brokenText !== null && (
                        <div
                            role="alert"
                            className="flex flex-wrap items-center gap-x-3 gap-y-2"
                        >
                            <p className="flex min-w-0 items-start gap-1.5 type-body text-status-error">
                                <X
                                    aria-hidden="true"
                                    className="mt-0.5 size-4 shrink-0"
                                />
                                {brokenText}
                            </p>
                            {firstBroken && !rawMode && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => showFix(firstBroken)}
                                >
                                    <Wrench aria-hidden="true" />
                                    Fix
                                </Button>
                            )}
                        </div>
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
                            No variables in {environmentName} yet.
                        </EmptyState>
                    ) : (
                        <>
                            <div className="flex min-h-8 flex-wrap items-center justify-between gap-2">
                                <span className="type-label text-muted-foreground tabular-nums">
                                    {query === ''
                                        ? plural(
                                              data.variables.length,
                                              'variable',
                                          )
                                        : `${rows.length} of ${plural(data.variables.length, 'variable')}`}
                                </span>
                                {data.variables.length > SEARCH_FROM && (
                                    <div className="relative w-full max-w-64">
                                        <Search
                                            aria-hidden="true"
                                            className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground"
                                        />
                                        <Input
                                            type="search"
                                            value={search}
                                            onChange={(event) =>
                                                setSearch(event.target.value)
                                            }
                                            placeholder="Search variables"
                                            aria-label="Search variables"
                                            className="h-8 pl-8"
                                        />
                                    </div>
                                )}
                            </div>
                            <div className="overflow-hidden rounded-xl border bg-card shadow-card">
                                <table className="w-full table-fixed type-body">
                                    <thead>
                                        <tr className="border-b bg-recessed text-left type-label text-muted-foreground">
                                            <th className="w-[40%] py-2.5 pr-3 pl-5 font-medium">
                                                Key
                                            </th>
                                            <th className="py-2.5 pr-3 font-medium">
                                                Value
                                            </th>
                                            {showTargets && (
                                                <th className="hidden w-32 py-2.5 pr-3 font-medium @xl:table-cell">
                                                    Environments
                                                </th>
                                            )}
                                            <th className="w-12 py-2.5 pr-3">
                                                <span className="sr-only">
                                                    Actions
                                                </span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {rows.map((row) => (
                                            <VariableRow
                                                key={row.id}
                                                row={row}
                                                showTargets={showTargets}
                                                staged={stagedKinds.get(row.id)}
                                                highlighted={fixing === row.id}
                                                onSuggestion={(
                                                    expression,
                                                    suggestion,
                                                ) =>
                                                    applySuggestion(
                                                        row,
                                                        expression,
                                                        suggestion,
                                                    )
                                                }
                                                project={project}
                                                environment={environment}
                                                onEdit={() =>
                                                    setDialog({ variable: row })
                                                }
                                                onOverride={
                                                    canWrite
                                                        ? () =>
                                                              setDialog({
                                                                  variable:
                                                                      null,
                                                                  preset: {
                                                                      key: row.key,
                                                                      value:
                                                                          row.type ===
                                                                          'secret'
                                                                              ? ''
                                                                              : (row.value ??
                                                                                ''),
                                                                      type: row.type,
                                                                  },
                                                              })
                                                        : undefined
                                                }
                                                onRemove={() =>
                                                    setDeleting(row)
                                                }
                                            />
                                        ))}
                                    </tbody>
                                </table>
                                {rows.length === 0 && (
                                    <p className="px-5 py-6 text-center type-body text-muted-foreground">
                                        No variable matches “{search.trim()}”.
                                    </p>
                                )}
                            </div>
                        </>
                    )}
                </section>

                <SettingsCard
                    title="Shared variables"
                    description={
                        <>
                            Project-wide values for {environmentName}. Use one
                            here to inject it under its own key, or reference it
                            as{' '}
                            <code className="type-mono-small">
                                {'${{shared.KEY}}'}
                            </code>
                            .
                        </>
                    }
                    actions={
                        data.shared.length > 0 && (
                            <span className="type-small text-muted-foreground tabular-nums">
                                {usedShared} of {data.shared.length} used
                            </span>
                        )
                    }
                    setting="shared-variables"
                >
                    {data.shared.length === 0 ? (
                        <p className="px-5 py-4 type-body text-muted-foreground">
                            No shared variables apply to {environmentName}.
                        </p>
                    ) : (
                        data.shared.map((row) => (
                            <label
                                key={row.id}
                                className="flex items-start gap-3 px-5 py-3"
                            >
                                <Checkbox
                                    className="mt-0.5"
                                    checked={row.used}
                                    disabled={!canWrite}
                                    aria-label={`Use ${row.key} in this service`}
                                    onCheckedChange={(checked) =>
                                        void setUsage(row.id, checked === true)
                                    }
                                />
                                <span className="flex min-w-0 flex-1 flex-col gap-0.5">
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
                                        <BuildHint exposure={row.exposure} />
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
                                </span>
                            </label>
                        ))
                    )}
                </SettingsCard>

                <Collapsible
                    className="overflow-hidden rounded-xl border bg-card shadow-card"
                    data-setting="system-variables"
                >
                    <CollapsibleTrigger className="group flex w-full items-center gap-3 px-5 py-4 text-left transition-colors duration-fast hover:bg-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-signal">
                        <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                            <span className="type-body font-medium">
                                System variables
                            </span>
                            <span className="type-small text-muted-foreground">
                                {data.system.length} set by launchroom on every
                                deployment, read-only
                            </span>
                        </span>
                        <ChevronRight
                            aria-hidden="true"
                            className="size-4 shrink-0 text-muted-foreground transition-transform group-data-[state=open]:rotate-90"
                        />
                    </CollapsibleTrigger>
                    <CollapsibleContent className="border-t">
                        <p className="max-w-[65ch] px-5 pt-4 pb-2 type-small text-muted-foreground">
                            Your variables with the same name take precedence.
                            To use one while building, add it as a variable with
                            Build and runtime availability.
                        </p>
                        <dl className="divide-y px-5 pb-2">
                            {data.system.map((row) => (
                                <div
                                    key={row.key}
                                    className="grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)] gap-3 py-2"
                                >
                                    <dt
                                        className={cn(
                                            'truncate type-mono-small',
                                            (row.overridden ||
                                                row.removedNextDeploy) &&
                                                'text-muted-foreground line-through',
                                        )}
                                    >
                                        {row.key}
                                    </dt>
                                    <dd className="flex min-w-0 flex-col gap-0.5">
                                        <span className="type-mono-small [overflow-wrap:anywhere] text-muted-foreground">
                                            {row.value}
                                        </span>
                                        {row.overridden ? (
                                            <span className="type-small text-muted-foreground">
                                                Replaced by your variable
                                            </span>
                                        ) : (
                                            row.note && (
                                                <span className="type-small text-muted-foreground">
                                                    {row.note}
                                                </span>
                                            )
                                        )}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </CollapsibleContent>
                </Collapsible>
            </div>

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
        </SettingsPage>
    );
}

/**
 * A broken reference under its value, then what to do about it: the valid
 * references it most likely meant as one-click chips (each opens the
 * editor with the value rewritten), or Edit when there is nothing close.
 */
export function ReferenceError({
    message,
    fix,
    canRewrite,
    onSuggestion,
    onEdit,
}: {
    message: string;
    fix: App.Data.Variables.ReferenceFixData | null;
    canRewrite: boolean;
    onSuggestion: (expression: string, suggestion: string) => void;
    onEdit: () => void;
}) {
    const suggestions = canRewrite ? (fix?.suggestions ?? []) : [];

    return (
        <span className="flex flex-col gap-1.5">
            <span className="flex items-start gap-1 type-small text-status-error">
                <X aria-hidden="true" className="mt-0.5 size-3.5 shrink-0" />
                <span className="[overflow-wrap:anywhere]">{message}</span>
            </span>
            {fix !== null &&
                (suggestions.length > 0 ? (
                    <span className="flex flex-wrap items-center gap-1.5 pl-4.5">
                        <span className="type-small text-muted-foreground">
                            Did you mean
                        </span>
                        {suggestions.map((suggestion, index) => (
                            <button
                                key={suggestion}
                                type="button"
                                data-fix-action={index === 0 ? '' : undefined}
                                onClick={() =>
                                    onSuggestion(fix.expression, suggestion)
                                }
                                className="inline-flex h-6 items-center rounded-md border bg-card px-1.5 type-mono-small transition-colors duration-fast hover:border-border-strong hover:bg-hover focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                {suggestion}
                            </button>
                        ))}
                    </span>
                ) : (
                    <span className="pl-4.5">
                        <button
                            type="button"
                            data-fix-action=""
                            onClick={onEdit}
                            className="type-small text-signal underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            Edit the value
                        </button>
                    </span>
                ))}
        </span>
    );
}

/** Above this many variables the list gets a search field. */
const SEARCH_FROM = 8;

/** Where else a variable applies besides the open environment ("Also staging"). */
function otherTargets(row: Row, environment: string): Row['targets'] {
    return row.targets.filter((target) => target.environmentId !== environment);
}

/** One variable: key with its cues, value (secrets masked), other environments, ⋯. */
function VariableRow({
    row,
    showTargets,
    staged,
    highlighted,
    project,
    environment,
    onEdit,
    onSuggestion,
    onOverride,
    onRemove,
}: {
    row: Row;
    showTargets: boolean;
    staged: App.Enums.ChangeKind | undefined;
    /** Fix brought this row into view: a brief signal ring. */
    highlighted: boolean;
    /** Edit the row with a suggested reference in place of a broken one. */
    onSuggestion: (expression: string, suggestion: string) => void;
    project: string;
    environment: string;
    onEdit: () => void;
    /** The app's variables on a worker or cron: add this service's own. */
    onOverride?: () => void;
    onRemove: () => void;
}) {
    const canOverride =
        onOverride !== undefined &&
        row.inheritedFrom !== null &&
        row.connection === null &&
        !row.overridden;

    return (
        <tr
            id={`variable-${row.id}`}
            className={cn(
                'scroll-mt-24 align-top transition-shadow duration-base',
                highlighted && 'shadow-[inset_0_0_0_2px_var(--signal)]',
                row.overridden && 'text-muted-foreground',
                staged !== undefined &&
                    'bg-signal-soft/40 [&>td:first-child]:shadow-[inset_2px_0_0_var(--signal)]',
            )}
        >
            <td className="py-3 pr-3 pl-5">
                <div className="flex min-w-0 flex-col gap-1">
                    <span className="flex min-w-0 items-center gap-1.5">
                        <span className="truncate type-mono" title={row.key}>
                            {row.key}
                        </span>
                        <Badges row={row} />
                    </span>
                    {staged !== undefined && (
                        <span className="type-small text-signal">
                            {staged === 'added' ? 'New' : 'Changed'}
                            <span className="sr-only"> (staged)</span>
                        </span>
                    )}
                    {row.overridden && (
                        <span className="type-small">
                            {row.inheritedFrom !== null
                                ? 'Replaced by a variable with the same key'
                                : 'Overridden by a variable of this service'}
                        </span>
                    )}
                </div>
            </td>
            <td className="min-w-0 py-3 pr-3">
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
                    {row.preview !== null && row.errors.length === 0 && (
                        <span className="type-mono-small [overflow-wrap:anywhere] text-muted-foreground">
                            <span aria-hidden="true">→ </span>
                            <span className="sr-only">Resolves to </span>
                            {row.preview}
                        </span>
                    )}
                    {row.errors.map((message) => (
                        <ReferenceError
                            key={message}
                            message={message}
                            fix={row.canEdit ? fixFor(row, message) : null}
                            // Secrets are write-only: their value can't be
                            // rewritten here, so they get Edit instead.
                            canRewrite={row.value !== null}
                            onSuggestion={onSuggestion}
                            onEdit={onEdit}
                        />
                    ))}
                </div>
            </td>
            {showTargets && (
                <td className="hidden py-3 pr-3 @xl:table-cell">
                    <span className="flex flex-wrap items-center gap-1">
                        {row.inheritedFrom !== null ? (
                            <span className="type-small text-muted-foreground">
                                From {row.inheritedFrom}
                            </span>
                        ) : (
                            row.connection === null &&
                            otherTargets(row, environment).length > 0 && (
                                <>
                                    <span className="type-small text-muted-foreground">
                                        Also
                                    </span>
                                    {otherTargets(row, environment).map(
                                        (target) => (
                                            <Badge
                                                key={`${target.environmentId}-${target.label}`}
                                                variant="secondary"
                                                className="font-normal"
                                            >
                                                {target.label}
                                            </Badge>
                                        ),
                                    )}
                                </>
                            )
                        )}
                    </span>
                </td>
            )}
            <td className="py-2 pr-3 text-right">
                {canOverride && (
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
                            <DropdownMenuItem onSelect={onOverride}>
                                Override
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
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
                            <DropdownMenuItem onSelect={onEdit}>
                                Edit
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                variant="destructive"
                                onSelect={onRemove}
                            >
                                Remove
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </td>
        </tr>
    );
}
