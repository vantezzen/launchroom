import { Link, useForm } from '@inertiajs/react';
import { Database, Plus, Tag, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { OptionCards } from '@/components/patterns/option-cards';
import { Stepper } from '@/components/patterns/stepper';
import { StatusIcon } from '@/components/status';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { PlacementIssueBanner } from '@/features/scheduling/placement-issue-banner';
import { formatMb } from '@/features/servers/format';
import {
    SettingsBody,
    SettingsCard,
    SettingsRow,
} from '@/features/settings/settings-card';
import { api } from '@/features/variables/api';
import { cn } from '@/lib/utils';
import services from '@/routes/projects/services';
import {
    ReplicaUnits,
    ResourceSlider,
    ServerPlacementCard,
    StrategyPicture,
} from './scaling-visuals';
import type { ServerRule, ServerTile } from './scaling-visuals';
import { SaveRow, SettingsPage, useSettingsPage } from './section';
import type { SettingsContext } from './settings-context';

const CPU_STEPS = [
    0.1, 0.25, 0.5, 0.75, 1, 1.5, 2, 3, 4, 6, 8, 12, 16, 24, 32, 48, 64,
];
const MEMORY_STEPS = [
    128, 256, 384, 512, 768, 1024, 1536, 2048, 3072, 4096, 6144, 8192, 12288,
    16384, 24576, 32768, 49152, 65536,
];

/** Steps up to the biggest server (at least `floor`), plus the current value. */
function stepsFor(
    all: number[],
    biggest: number | null,
    current: number,
    floor: number,
): number[] {
    const limit = Math.max(biggest ?? all[all.length - 1], floor);
    const steps = all.filter((step) => step <= limit + 1e-9);

    if (!steps.some((step) => Math.abs(step - current) < 1e-9)) {
        steps.push(current);
    }

    return steps.sort((a, b) => a - b);
}

function formatCores(value: number): string {
    const rounded = Number(value.toFixed(2));

    return `${rounded} ${rounded === 1 ? 'core' : 'cores'}`;
}

type Draft = {
    replicas: number;
    cpus: number;
    memoryMb: number;
    strategy: App.Enums.PlacementStrategy;
    labels: Record<string, string>;
    rules: Record<string, ServerRule>;
};

/**
 * The scaling payload. Empty lists are left out (they default to empty):
 * the validator treats an empty array as missing.
 */
function payload(draft: Draft) {
    const allowServers = Object.keys(draft.rules).filter(
        (id) => draft.rules[id] === 'only',
    );
    const denyServers = Object.keys(draft.rules).filter(
        (id) => draft.rules[id] === 'never',
    );

    return {
        replicas: draft.replicas,
        cpus: draft.cpus,
        memoryMb: draft.memoryMb,
        strategy: draft.strategy,
        ...(Object.keys(draft.labels).length > 0
            ? { placementLabels: draft.labels }
            : {}),
        ...(allowServers.length > 0 ? { allowServers } : {}),
        ...(denyServers.length > 0 ? { denyServers } : {}),
    };
}

/**
 * What the scheduler would do with the draft, live (250 ms after the last
 * change): eligibility and replicas per server, or why it doesn't fit.
 * The saved settings use the page's own data, no request.
 */
function useScalingPreview(
    context: SettingsContext,
    draft: Draft,
    dirty: boolean,
) {
    const [preview, setPreview] =
        useState<App.Data.Canvas.ScalingPreviewData | null>(null);
    const [loading, setLoading] = useState(false);
    const body = JSON.stringify({ scaling: payload(draft) });
    const { active } = useSettingsPage();
    const url = services.scalingPreview(context.route).url;

    useEffect(() => {
        if (!dirty || !active) {
            setPreview(null);

            return;
        }

        const controller = new AbortController();
        setLoading(true);
        const timer = window.setTimeout(() => {
            api<App.Data.Canvas.ScalingPreviewData>(
                'post',
                url,
                JSON.parse(body),
                controller.signal,
            )
                .then((result) => setPreview(result))
                .catch(() => undefined)
                .finally(() => setLoading(false));
        }, 250);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [body, dirty, active, url]);

    return { preview, loading };
}

function LabelChips({
    labels,
    onChange,
    suggestions,
    disabled,
}: {
    labels: Record<string, string>;
    onChange: (labels: Record<string, string>) => void;
    suggestions: [string, string][];
    disabled: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [key, setKey] = useState('');
    const [value, setValue] = useState('');
    const entries = Object.entries(labels);
    const add = (k: string, v: string) => {
        if (k.trim() === '') {
            return;
        }

        onChange({ ...labels, [k.trim()]: v.trim() });
        setKey('');
        setValue('');
        setOpen(false);
    };
    const offered = suggestions.filter(([k, v]) => labels[k] !== v);

    return (
        <div className="flex flex-wrap items-center gap-2">
            {entries.length === 0 && (
                <span className="type-small text-muted-foreground">
                    Any server
                </span>
            )}
            {entries.map(([k, v]) => (
                <span
                    key={k}
                    className="inline-flex h-7 items-center gap-1 rounded-md border bg-card pr-1 pl-2 type-mono-small"
                >
                    <Tag
                        aria-hidden="true"
                        className="size-3 text-muted-foreground"
                    />
                    {k}={v}
                    {!disabled && (
                        <button
                            type="button"
                            aria-label={`Remove ${k}=${v}`}
                            onClick={() => {
                                const next = { ...labels };
                                delete next[k];
                                onChange(next);
                            }}
                            className="flex size-5 items-center justify-center rounded text-muted-foreground hover:bg-hover hover:text-foreground"
                        >
                            <X className="size-3" />
                        </button>
                    )}
                </span>
            ))}
            {!disabled && (
                <Popover open={open} onOpenChange={setOpen}>
                    <PopoverTrigger asChild>
                        <Button type="button" variant="outline" size="sm">
                            <Plus /> Require a label
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent
                        align="start"
                        className="flex w-80 flex-col gap-3"
                    >
                        <span className="type-label">
                            Only servers labelled
                        </span>
                        {offered.length > 0 && (
                            <div className="flex flex-wrap gap-1.5">
                                {offered.map(([k, v]) => (
                                    <button
                                        key={`${k}=${v}`}
                                        type="button"
                                        onClick={() => add(k, v)}
                                        className="inline-flex h-7 items-center rounded-md border px-2 type-mono-small hover:border-border-strong hover:bg-hover"
                                    >
                                        {k}={v}
                                    </button>
                                ))}
                            </div>
                        )}
                        <div className="flex items-center gap-1.5">
                            <Input
                                aria-label="Label name"
                                value={key}
                                placeholder="zone"
                                onChange={(event) => setKey(event.target.value)}
                                className="h-8 font-mono text-[13px]"
                            />
                            <span
                                className="text-muted-foreground"
                                aria-hidden="true"
                            >
                                =
                            </span>
                            <Input
                                aria-label="Label value"
                                value={value}
                                placeholder="hel"
                                onChange={(event) =>
                                    setValue(event.target.value)
                                }
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter') {
                                        event.preventDefault();
                                        add(key, value);
                                    }
                                }}
                                className="h-8 font-mono text-[13px]"
                            />
                        </div>
                        <Button
                            type="button"
                            size="sm"
                            className="self-end"
                            disabled={key.trim() === ''}
                            onClick={() => add(key, value)}
                        >
                            Add
                        </Button>
                    </PopoverContent>
                </Popover>
            )}
        </div>
    );
}

/**
 * Scaling (docs/02 §2, docs/08 §16): the servers as devices with the
 * replicas the scheduler would place on each (live while you edit), their
 * CPU and memory against what others use, the size of a replica as
 * sliders against the biggest server, spread or pack as pictures, and the
 * placement rules. Databases (and services with a volume) run one replica
 * pinned to the server that holds the data. Saving stages a restart.
 */
export function ScalingSection({ context }: { context: SettingsContext }) {
    const { settings, node, canUpdate } = context;
    const placement = settings.placement;
    const stateful = placement?.stateful ?? node.kind === 'database';
    const initialRules = useMemo(() => {
        const rules: Record<string, ServerRule> = {};

        settings.scaling.allowServers.forEach((id) => (rules[id] = 'only'));
        settings.scaling.denyServers.forEach((id) => (rules[id] = 'never'));

        return rules;
    }, [settings.scaling.allowServers, settings.scaling.denyServers]);

    const form = useForm<Draft>({
        replicas: settings.scaling.replicas,
        cpus: settings.scaling.cpus,
        memoryMb: settings.scaling.memoryMb,
        strategy: settings.scaling.strategy,
        labels: Array.isArray(settings.scaling.placementLabels)
            ? {}
            : settings.scaling.placementLabels,
        rules: initialRules,
    });
    const draft = form.data;
    const { preview, loading } = useScalingPreview(
        context,
        draft,
        form.isDirty,
    );
    const error = (key: string) =>
        (form.errors as Record<string, string | undefined>)[`scaling.${key}`];

    const pinnedName = placement?.pinnedServer ?? null;
    const servers: ServerTile[] = (placement?.servers ?? []).map((server) => {
        const live = preview?.servers.find((item) => item.id === server.id);

        return {
            ...server,
            labels: Array.isArray(server.labels) ? {} : server.labels,
            // A pinned stateful service can only run where its data is.
            draftEligible:
                stateful && pinnedName !== null && server.name !== pinnedName
                    ? false
                    : live
                      ? live.eligible
                      : server.eligible,
            draftReason:
                stateful && pinnedName !== null && server.name !== pinnedName
                    ? `Its data lives on ${pinnedName}`
                    : live
                      ? live.reason
                      : server.reason,
            draftPlanned: live ? live.planned : server.planned,
        };
    });
    const planError = form.isDirty
        ? preview
            ? preview.error
            : null
        : (placement?.planError ?? null);
    const placed = servers.filter((server) => server.draftPlanned > 0);
    const eligible = servers.filter((server) => server.draftEligible);
    const biggestCpu =
        Math.max(0, ...eligible.map((server) => server.cpus ?? 0)) || null;
    const biggestMemory =
        Math.max(
            0,
            ...eligible.map(
                (server) => (server.memoryMb ?? 0) - server.reservedMemoryMb,
            ),
        ) || null;
    const cpuSteps = stepsFor(CPU_STEPS, biggestCpu, draft.cpus, 1);
    const memorySteps = stepsFor(
        MEMORY_STEPS,
        biggestMemory,
        draft.memoryMb,
        512,
    );
    const suggestions = Array.from(
        new Set(
            servers.flatMap((server) =>
                Object.entries(server.labels).map(([k, v]) => `${k}=${v}`),
            ),
        ),
    ).map((pair) => pair.split('=') as [string, string]);
    const pinnedServer = placement?.pinnedServer ?? null;
    const envKind = placement?.environmentKind;
    const envAllowed =
        envKind === 'production'
            ? servers.filter((server) => server.allowProduction).length
            : envKind === 'preview'
              ? servers.filter((server) => server.allowPreviews).length
              : null;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ scaling: payload(data) }));
        form.patch(services.settings(context.route).url, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => form.setDefaults(),
        });
    };

    const replicaWord = (count: number) =>
        `${count} ${count === 1 ? 'replica' : 'replicas'}`;
    const headline =
        draft.replicas === 0
            ? 'Scaled to zero'
            : planError
              ? `${replicaWord(stateful ? 1 : draft.replicas)} don’t fit`
              : placed.length > 0
                ? `${replicaWord(stateful ? 1 : draft.replicas)} on ${placed.map((server) => server.name).join(', ')}`
                : replicaWord(stateful ? 1 : draft.replicas);

    return (
        <SettingsPage
            title="Scaling"
            description={
                stateful
                    ? 'How much this service may use. It runs as one replica on the server that holds its data.'
                    : 'How many replicas run, how big each one is and where they run, for this environment.'
            }
        >
            {placement?.issue && (
                <PlacementIssueBanner
                    issue={placement.issue}
                    serviceName={node.name}
                    backupsHref={
                        services.section({
                            project: context.route[0],
                            environment: context.route[1],
                            service: context.route[2],
                            section: 'backups',
                        }).url
                    }
                />
            )}

            <form onSubmit={submit} className="flex flex-col gap-8">
                {/* The live picture: what the scheduler would do. */}
                <section
                    aria-labelledby="scaling-preview"
                    data-setting="placement"
                    className="overflow-hidden rounded-xl border bg-card shadow-card"
                >
                    <div className="flex flex-wrap items-center justify-between gap-3 px-5 pt-4 pb-3">
                        <div className="flex min-w-0 flex-col gap-0.5">
                            <span className="type-small text-muted-foreground">
                                {form.isDirty
                                    ? 'After your changes'
                                    : 'Running now'}
                            </span>
                            <h4
                                id="scaling-preview"
                                className="truncate type-heading text-[18px]"
                                aria-live="polite"
                            >
                                {headline}
                            </h4>
                        </div>
                        <span
                            className="flex items-center gap-2 type-small text-muted-foreground"
                            aria-live="polite"
                        >
                            {loading ? (
                                <>
                                    <StatusIcon state="progress" /> Placing…
                                </>
                            ) : planError ? (
                                <>
                                    <StatusIcon state="warning" /> Doesn’t fit
                                </>
                            ) : (
                                <>
                                    <StatusIcon
                                        state={
                                            form.isDirty ? 'staged' : 'active'
                                        }
                                    />
                                    {form.isDirty
                                        ? 'Fits, applies with the next deploy'
                                        : 'Fits'}
                                </>
                            )}
                        </span>
                    </div>
                    {planError && (
                        <p className="mx-5 mb-3 rounded-lg bg-status-progress-soft px-3 py-2 type-small text-status-progress">
                            {planError}
                        </p>
                    )}
                    {servers.length > 0 ? (
                        <ul className="grid gap-3 border-t bg-recessed/60 p-4 @lg/page:grid-cols-2 @2xl/page:grid-cols-3">
                            {servers.map((server) => (
                                <ServerPlacementCard
                                    key={server.id}
                                    server={server}
                                    cpus={draft.cpus}
                                    memoryMb={draft.memoryMb}
                                    memoryOvercommit={
                                        placement?.memoryOvercommit ?? 1
                                    }
                                    cpuOvercommit={
                                        placement?.cpuOvercommit ?? null
                                    }
                                    rule={draft.rules[server.id] ?? 'any'}
                                    onRule={
                                        stateful
                                            ? undefined
                                            : (rule) =>
                                                  form.setData('rules', {
                                                      ...draft.rules,
                                                      [server.id]: rule,
                                                  })
                                    }
                                    staged={form.isDirty}
                                    pinned={
                                        stateful && pinnedServer === server.name
                                    }
                                    disabled={!canUpdate}
                                    logo={node.logo}
                                />
                            ))}
                        </ul>
                    ) : (
                        <p className="border-t px-5 py-4 type-small text-muted-foreground">
                            No server runs services yet. Give a server the
                            runtime role to place replicas.
                        </p>
                    )}
                </section>

                {stateful ? (
                    <div className="flex items-start gap-3 rounded-xl border px-4 py-3.5">
                        <span className="flex size-8 shrink-0 items-center justify-center rounded-lg border bg-recessed text-muted-foreground">
                            <Database aria-hidden="true" className="size-4" />
                        </span>
                        <div className="flex min-w-0 flex-col gap-0.5">
                            <span className="type-body font-medium">
                                {pinnedServer
                                    ? `Stays on ${pinnedServer}, where its data lives`
                                    : 'Placed once, then it stays'}
                            </span>
                            <span className="type-small text-muted-foreground">
                                {node.kind === 'database'
                                    ? 'Two copies can’t share one data directory, so a database runs as one replica. '
                                    : 'A service with a volume runs as one replica next to its data. '}
                                To move it, restore a backup on another server.{' '}
                                <Link
                                    href={
                                        services.section({
                                            project: context.route[0],
                                            environment: context.route[1],
                                            service: context.route[2],
                                            section: 'backups',
                                        }).url
                                    }
                                    only={['panel']}
                                    preserveState
                                    className="text-signal underline-offset-4 hover:underline"
                                >
                                    Backups
                                </Link>
                            </span>
                        </div>
                    </div>
                ) : (
                    <SettingsCard
                        setting="replicas"
                        title="Replicas"
                        description="Replicas run side by side. A deploy replaces them one at a time."
                    >
                        <SettingsRow
                            label="How many"
                            description={
                                draft.replicas === 0
                                    ? 'Stopped. Nothing runs until you scale up.'
                                    : draft.replicas === 1
                                      ? 'With one replica, a deploy or a server restart briefly interrupts it.'
                                      : 'Requests are shared between them.'
                            }
                        >
                            <div className="flex items-center gap-4">
                                <ReplicaUnits
                                    count={draft.replicas}
                                    previous={settings.scaling.replicas}
                                    staged={form.isDirty}
                                    logo={node.logo}
                                    max={8}
                                />
                                <Stepper
                                    label="Replicas"
                                    className="[&>label]:sr-only"
                                    min={0}
                                    max={64}
                                    value={draft.replicas}
                                    disabled={!canUpdate}
                                    onChange={(value) =>
                                        form.setData('replicas', value)
                                    }
                                />
                            </div>
                        </SettingsRow>
                        <InputError
                            message={error('replicas')}
                            className="px-5 pb-3"
                        />
                    </SettingsCard>
                )}

                <SettingsCard
                    setting="replica-size"
                    title="Size of each replica"
                    description="Limits for each replica. The sliders stop at the largest server that can run it."
                >
                    <SettingsBody className="grid gap-8 @xl/page:grid-cols-2">
                        <ResourceSlider
                            id="scaling-cpus"
                            label="CPU"
                            steps={cpuSteps}
                            value={draft.cpus}
                            onChange={(value) => form.setData('cpus', value)}
                            format={formatCores}
                            total={
                                !stateful && draft.replicas > 1
                                    ? `${formatCores(draft.cpus * draft.replicas)} in total`
                                    : undefined
                            }
                            disabled={!canUpdate}
                            warn={
                                biggestCpu !== null && draft.cpus > biggestCpu
                            }
                            hint={
                                error('cpus') ??
                                (biggestCpu !== null && draft.cpus > biggestCpu
                                    ? 'More than any server has.'
                                    : undefined)
                            }
                        />
                        <ResourceSlider
                            id="scaling-memory"
                            label="Memory"
                            steps={memorySteps}
                            value={draft.memoryMb}
                            onChange={(value) =>
                                form.setData('memoryMb', value)
                            }
                            format={(value) => formatMb(value) ?? `${value} MB`}
                            total={
                                !stateful && draft.replicas > 1
                                    ? `${formatMb(draft.memoryMb * draft.replicas)} in total`
                                    : undefined
                            }
                            disabled={!canUpdate}
                            warn={
                                biggestMemory !== null &&
                                draft.memoryMb > biggestMemory
                            }
                            hint={
                                error('memoryMb') ??
                                (biggestMemory !== null &&
                                draft.memoryMb > biggestMemory
                                    ? 'More than any server can give it.'
                                    : 'A replica that goes over the limit is restarted.')
                            }
                        />
                    </SettingsBody>
                </SettingsCard>

                {!stateful && (
                    <section
                        aria-labelledby="scaling-strategy"
                        data-setting="distribution"
                        className="flex flex-col gap-3.5"
                    >
                        <h4 id="scaling-strategy" className="type-heading">
                            Distribution
                        </h4>
                        <OptionCards
                            label="Distribution"
                            value={draft.strategy}
                            onChange={(strategy) =>
                                canUpdate && form.setData('strategy', strategy)
                            }
                            options={[
                                {
                                    value: 'spread',
                                    title: 'Spread',
                                    description:
                                        'Across servers and zones, so the others keep serving if one goes down.',
                                    media: (
                                        <StrategyPicture strategy="spread" />
                                    ),
                                    disabled: !canUpdate,
                                },
                                {
                                    value: 'pack',
                                    title: 'Pack',
                                    description:
                                        'Onto as few servers as fit, keeping the others free.',
                                    media: <StrategyPicture strategy="pack" />,
                                    disabled: !canUpdate,
                                },
                            ]}
                        />
                    </section>
                )}

                <SettingsCard
                    title="Where it may run"
                    description="Rules for single servers are on their cards above."
                >
                    <SettingsRow
                        setting="labels"
                        label="Required labels"
                        description="Only servers that have all of these labels."
                        align="start"
                    >
                        <LabelChips
                            labels={draft.labels}
                            onChange={(labels) =>
                                form.setData('labels', labels)
                            }
                            suggestions={suggestions}
                            disabled={!canUpdate}
                        />
                    </SettingsRow>
                    {envAllowed !== null && (
                        <SettingsRow
                            label={
                                envKind === 'production'
                                    ? 'Production servers'
                                    : 'Preview servers'
                            }
                            description={
                                envKind === 'production'
                                    ? 'Production runs only on servers that allow production.'
                                    : 'Previews run only on servers that allow previews.'
                            }
                        >
                            <span
                                className={cn(
                                    'type-small',
                                    envAllowed === 0
                                        ? 'text-status-progress'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {envAllowed} of {servers.length} allow it
                            </span>
                        </SettingsRow>
                    )}
                    <InputError
                        message={
                            error('placementLabels') ??
                            error('allowServers') ??
                            error('denyServers')
                        }
                        className="px-5 pb-3"
                    />
                </SettingsCard>

                {canUpdate && (
                    <SaveRow
                        dirty={form.isDirty}
                        processing={form.processing}
                        onReset={() => form.reset()}
                        label="Save for next deploy"
                    />
                )}
            </form>
        </SettingsPage>
    );
}
