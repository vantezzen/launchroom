import { router, useForm } from '@inertiajs/react';
import {
    Download,
    FileCode2,
    Plus,
    RefreshCw,
    Sparkles,
    TriangleAlert,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { ServiceGlyph } from '@/features/canvas/service-glyph';
import { OptionCards } from '@/components/patterns/option-cards';
import { Status } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    SettingsBody,
    SettingsCard,
    SettingsRow,
} from '@/features/settings/settings-card';
import { DockerfileGenerationSheet } from '@/features/dockerfile-generation/generation-sheet';
import { cn } from '@/lib/utils';
import services from '@/routes/projects/services';
import { nextHeadline, PlanJsonDialog } from './detected-plan';
import { OverrideRow } from './override-row';
import { SaveRow, SettingsPage } from './section';
import type { SettingsContext } from './settings-context';

type Builder = 'auto' | 'dockerfile' | 'railpack' | 'static';

const builderOptions: { value: Builder; title: string; description: string }[] =
    [
        {
            value: 'auto',
            title: 'Automatic',
            description:
                'Your Dockerfile if there is one, else a framework preset or Railpack.',
        },
        {
            value: 'railpack',
            title: 'Railpack',
            description:
                'Builds from your code with the detected toolchain, no Dockerfile needed.',
        },
        {
            value: 'dockerfile',
            title: 'Dockerfile',
            description: 'Always builds the Dockerfile in your repository.',
        },
        {
            value: 'static',
            title: 'Static files',
            description:
                'Builds, then serves the output folder as a static site.',
        },
    ];

const PLANNING_POLL_MS = 4000;

/**
 * A railpack.json with the effective build settings (overrides, else the
 * detected values), for committing to the repository (docs/04 §4 "Eject").
 */
export function railpackJson(
    build: App.Data.Services.BuildSettings,
    detected: App.Data.Canvas.DetectedPlanData | null,
    startCommand: string | null,
): string {
    const install = build.installCommand ?? detected?.installCommand ?? null;
    const compile = build.buildCommand ?? detected?.buildCommand ?? null;
    const start = startCommand ?? detected?.startCommand ?? null;
    const steps: Record<string, { commands: string[] }> = {};

    if (install) {
        steps.install = { commands: [install] };
    }

    if (compile) {
        steps.build = { commands: [compile] };
    }

    return `${JSON.stringify(
        {
            $schema: 'https://schema.railpack.com',
            ...(detected?.provider ? { provider: detected.provider } : {}),
            ...(Object.keys(steps).length > 0 ? { steps } : {}),
            ...(start ? { deploy: { startCommand: start } } : {}),
        },
        null,
        2,
    )}\n`;
}

function download(name: string, contents: string) {
    const url = URL.createObjectURL(
        new Blob([contents], { type: 'application/json' }),
    );
    const link = document.createElement('a');
    link.href = url;
    link.download = name;
    link.click();
    URL.revokeObjectURL(url);
}

const builderLabels: Record<string, string> = {
    explicit: 'as configured',
    dockerfile_detected: 'from your Dockerfile',
    preset: 'with a launchroom preset',
    railpack: 'with Railpack',
};

/** Live while the plan task runs; the canvas channel nudges, this is the fallback. */
function usePlanPolling(planning: boolean) {
    useEffect(() => {
        if (!planning) {
            return;
        }

        const interval = window.setInterval(() => {
            if (document.visibilityState === 'visible') {
                router.reload({ only: ['panel'] });
            }
        }, PLANNING_POLL_MS);

        return () => window.clearInterval(interval);
    }, [planning]);
}

/**
 * "Detected: Next.js 16 · npm · Node 22": the plan as an object, with its
 * state (inspecting, detected at a commit, failed) and "Detect again".
 */
function DetectedHero({
    context,
    onGenerate,
}: {
    context: SettingsContext;
    onGenerate: (() => void) | null;
}) {
    const { plan, detected } = context.settings;
    const [rerunning, setRerunning] = useState(false);
    const planning = plan?.status === 'planning';
    usePlanPolling(planning);

    const framework = detected
        ? [detected.framework, detected.frameworkVersion]
              .filter(Boolean)
              .join(' ')
        : '';
    const facts = detected
        ? [
              detected.packageManager,
              framework !== '' && detected.runtime,
              detected.runtimeSource && `from ${detected.runtimeSource}`,
              detected.port !== null && `port ${detected.port}`,
          ].filter((part): part is string => Boolean(part))
        : [];
    const title = detected
        ? framework || detected.runtime || 'Your app'
        : planning
          ? 'Inspecting your repository'
          : 'Not inspected yet';

    return (
        <div className="overflow-hidden rounded-xl border bg-card shadow-card">
            <div className="flex flex-wrap items-center gap-4 p-5">
                <ServiceGlyph logo={context.node.logo} size="lg" />
                <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                    <span className="type-small text-muted-foreground">
                        {detected ? 'Detected' : 'Build plan'}
                    </span>
                    <span className="type-heading text-[18px]">{title}</span>
                    {facts.length > 0 && (
                        <div className="flex flex-wrap gap-1.5">
                            {facts.map((fact) => (
                                <span
                                    key={fact}
                                    className="inline-flex h-6 items-center rounded-md border px-2 type-small text-muted-foreground"
                                >
                                    {fact}
                                </span>
                            ))}
                        </div>
                    )}
                </div>
                {context.canUpdate && !planning && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={rerunning}
                        onClick={() => {
                            setRerunning(true);
                            router.post(
                                services.replan(context.route).url,
                                {},
                                {
                                    preserveScroll: true,
                                    preserveState: true,
                                    onFinish: () => setRerunning(false),
                                },
                            );
                        }}
                    >
                        <RefreshCw
                            className={cn(
                                rerunning && 'motion-safe:animate-spin',
                            )}
                        />
                        Detect again
                    </Button>
                )}
            </div>
            {detected?.next && (
                <div className="border-t px-5 py-3 type-small text-muted-foreground">
                    {nextHeadline(detected.next).join(' · ')}
                </div>
            )}
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 border-t bg-recessed px-5 py-3 type-small text-muted-foreground">
                {plan === null ? (
                    <span>
                        launchroom detects the framework, commands and port when
                        the first build runs.
                    </span>
                ) : planning ? (
                    <Status
                        state="progress"
                        label="Cloning the branch head and detecting commands"
                    />
                ) : plan.status === 'failed' ? (
                    <Status state="failed" label="Detection failed" />
                ) : (
                    <Status state="active" label="Up to date" />
                )}
                {plan?.commitSha && (
                    <span>
                        {plan.branch && (
                            <code className="type-mono-small">
                                {plan.branch}
                            </code>
                        )}{' '}
                        at{' '}
                        <code className="type-mono-small">
                            {plan.commitSha.slice(0, 7)}
                        </code>
                        {plan.finishedAt && (
                            <>
                                {' · '}
                                <TimeAgo value={plan.finishedAt} />
                            </>
                        )}
                    </span>
                )}
                {detected?.builder && (
                    <span className="sm:ml-auto">
                        Built{' '}
                        {detected.builder === 'dockerfile'
                            ? 'from a Dockerfile'
                            : (builderLabels[detected.builderReason ?? ''] ??
                              'with Railpack')}
                    </span>
                )}
                {plan?.status === 'failed' && plan.error && (
                    <p className="w-full max-w-[65ch]">{plan.error}</p>
                )}
            </div>
            {plan?.status === 'failed' &&
                context.settings.dockerfileGeneration?.planFailed && (
                    <div className="flex flex-wrap items-center gap-x-4 gap-y-2 border-t px-5 py-4">
                        <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                            <span className="type-label">
                                launchroom couldn&apos;t detect how to build
                                this app
                            </span>
                            <span className="type-small text-muted-foreground">
                                {onGenerate
                                    ? 'launchroom can suggest a Dockerfile and verify it with a real build.'
                                    : 'launchroom can suggest a Dockerfile for this app. An owner can turn this on in Settings → Dockerfile generation.'}
                            </span>
                        </div>
                        {onGenerate && (
                            <Button
                                type="button"
                                size="sm"
                                onClick={onGenerate}
                            >
                                <FileCode2 />
                                Generate a Dockerfile
                            </Button>
                        )}
                    </div>
                )}
        </div>
    );
}

/**
 * Settings → Build's "Generate a Dockerfile" card (docs/20 §3, §7): on
 * demand, or the stored Dockerfile in use, or why it isn't offered.
 */
function GenerateCard({
    context,
    onOpen,
}: {
    context: SettingsContext;
    onOpen: () => void;
}) {
    const panel = context.settings.dockerfileGeneration;

    if (!panel) {
        return null;
    }

    const stored =
        context.settings.build.builder === 'dockerfile' &&
        context.settings.build.generatedDockerfile !== null;
    const latest = panel.latest;
    const running =
        latest !== null &&
        ['reading', 'drafting', 'building', 'starting'].includes(latest.status);

    return (
        <SettingsCard
            setting="generate-dockerfile"
            title="Generate a Dockerfile"
            description={
                stored
                    ? 'This service builds with a Dockerfile launchroom generated and verified.'
                    : panel.available
                      ? 'launchroom suggests one from your manifest files, then builds and starts it to make sure it works.'
                      : 'launchroom can suggest a Dockerfile, verified by an actual build.'
            }
        >
            <SettingsRow
                media={
                    <span className="flex size-8 items-center justify-center rounded-lg border bg-recessed text-muted-foreground">
                        <FileCode2 aria-hidden="true" className="size-4" />
                    </span>
                }
                label={
                    running
                        ? 'Generating…'
                        : stored
                          ? 'Stored in launchroom'
                          : latest?.status === 'verified'
                            ? 'A verified Dockerfile is ready'
                            : 'Dockerfile'
                }
                description={
                    panel.available
                        ? `Drafted with ${panel.model}. Nothing is written to your repository.`
                        : panel.reason === 'team_off'
                          ? 'Off for this team. An owner can turn it on in Settings → Dockerfile generation.'
                          : panel.reason === 'unsupported'
                            ? 'Only for services built from a git repository.'
                            : 'Not set up yet. An instance owner can turn it on in Settings → Dockerfile generation.'
                }
            >
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={!panel.canStart && !latest}
                    onClick={onOpen}
                >
                    {running || stored || latest?.status === 'verified'
                        ? 'View'
                        : 'Generate'}
                </Button>
            </SettingsRow>
        </SettingsCard>
    );
}

function Warnings({ plan }: { plan: App.Data.Canvas.DetectedPlanData }) {
    const warnings = [
        ...plan.missingVariables.map(
            (key) =>
                `${key} is referenced in your code but not set. Add it under Variables.`,
        ),
        ...plan.warnings,
    ];

    if (warnings.length === 0) {
        return null;
    }

    return (
        <ul className="flex flex-col gap-2 rounded-xl border bg-status-progress-soft/50 px-4 py-3">
            {warnings.map((warning) => (
                <li key={warning} className="flex gap-2.5 type-small">
                    <TriangleAlert
                        aria-hidden="true"
                        className="mt-0.5 size-3.5 shrink-0 text-status-progress"
                    />
                    <span>
                        <span className="sr-only">Warning: </span>
                        {warning}
                    </span>
                </li>
            ))}
        </ul>
    );
}

/**
 * Build (docs/04 §4): what launchroom detected first, then the builder as
 * option cards and every command as Detected + Override. Saving stages a
 * rebuild.
 */
export function BuildSection({ context }: { context: SettingsContext }) {
    const { settings, canUpdate } = context;
    const detected = settings.detected;
    const [jsonOpen, setJsonOpen] = useState(false);
    const [generate, setGenerate] = useState<{
        open: boolean;
        trigger: App.Enums.Dockerfiles.DockerfileGenerationTrigger;
    }>(() => {
        // ?generate=plan_failed|build_failed|on_demand (FailureCard "Generate a Dockerfile").
        const requested =
            typeof window === 'undefined'
                ? null
                : new URLSearchParams(window.location.search).get('generate');
        const trigger = (
            ['plan_failed', 'build_failed', 'on_demand'] as const
        ).find((value) => value === requested);

        return {
            open:
                trigger !== undefined && settings.dockerfileGeneration !== null,
            trigger: trigger ?? 'on_demand',
        };
    });
    const panel = settings.dockerfileGeneration;
    const openGenerate = (
        trigger: App.Enums.Dockerfiles.DockerfileGenerationTrigger,
    ) => setGenerate({ open: true, trigger });
    const form = useForm<{ build: App.Data.Services.BuildSettings }>({
        build: settings.build,
    });
    const savedBuild = JSON.stringify(settings.build);

    // "Use this Dockerfile" staged new build settings: take them over.
    useEffect(() => {
        if (!form.isDirty) {
            form.setDefaults('build', settings.build);
            form.setData('build', settings.build);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [savedBuild]);
    const build = form.data.build;
    const set = (patch: Partial<App.Data.Services.BuildSettings>) =>
        form.setData('build', { ...build, ...patch });
    const error = (key: string) =>
        (form.errors as Record<string, string | undefined>)[`build.${key}`];

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.patch(services.settings(context.route).url, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => form.setDefaults(),
        });
    };

    const toggleDecision = (id: string) =>
        set({
            disabledDecisions: build.disabledDecisions.includes(id)
                ? build.disabledDecisions.filter((item) => item !== id)
                : [...build.disabledDecisions, id],
        });
    const decisions = (detected?.presets ?? []).flatMap((preset) =>
        preset.decisions.map((decision) => ({ preset, decision })),
    );

    return (
        <SettingsPage
            title="Build"
            description="How your code becomes an image. Overrides live here, not in your repository."
        >
            <DetectedHero
                context={context}
                onGenerate={
                    panel?.canStart ? () => openGenerate('plan_failed') : null
                }
            />
            {detected && <Warnings plan={detected} />}

            <form onSubmit={submit} className="flex flex-col gap-8">
                <section
                    aria-label="Builder"
                    data-setting="builder"
                    className="flex flex-col gap-3.5"
                >
                    <h4 className="type-heading">Builder</h4>
                    <OptionCards
                        label="Builder"
                        className="sm:grid-cols-2"
                        options={builderOptions.map((option) => ({
                            ...option,
                            disabled: !canUpdate,
                        }))}
                        value={(build.builder as Builder) ?? 'auto'}
                        onChange={(builder) => set({ builder })}
                    />
                    <InputError message={error('builder')} />
                </section>

                <SettingsCard
                    title="Commands"
                    description="Detected from your repository. Override only what needs to differ."
                >
                    {build.builder === 'dockerfile' &&
                        build.generatedDockerfile !== null && (
                            <SettingsRow
                                setting="dockerfile"
                                label="Dockerfile"
                                description="The Dockerfile launchroom generated and verified, stored in launchroom."
                            >
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => openGenerate('on_demand')}
                                >
                                    View
                                </Button>
                            </SettingsRow>
                        )}
                    {(build.builder === 'dockerfile' ||
                        build.dockerfilePath !== null) &&
                        build.generatedDockerfile === null && (
                            <OverrideRow
                                setting="dockerfile"
                                label="Dockerfile"
                                detected="Dockerfile"
                                source="Uses"
                                value={build.dockerfilePath}
                                disabled={!canUpdate}
                                onChange={(dockerfilePath) =>
                                    set({ dockerfilePath })
                                }
                                error={error('dockerfilePath')}
                            />
                        )}
                    <OverrideRow
                        setting="install-command"
                        label="Install"
                        detected={detected?.installCommand ?? null}
                        value={build.installCommand}
                        disabled={!canUpdate}
                        onChange={(installCommand) => set({ installCommand })}
                        error={error('installCommand')}
                    />
                    <OverrideRow
                        setting="build-command"
                        label="Build"
                        detected={detected?.buildCommand ?? null}
                        detectedFallback="Nothing to build, or detected on the next build"
                        value={build.buildCommand}
                        disabled={!canUpdate}
                        onChange={(buildCommand) => set({ buildCommand })}
                        error={error('buildCommand')}
                    />
                </SettingsCard>

                {decisions.length > 0 && (
                    <SettingsCard
                        setting="preset-decisions"
                        title="Preset decisions"
                        description={`What the ${detected?.presets.length === 1 ? `${detected.presets[0].name} preset` : 'presets'} changed. Remove one to build without it.`}
                    >
                        <SettingsBody>
                            <ul
                                className="flex flex-wrap gap-2"
                                aria-label="Preset decisions"
                            >
                                {decisions.map(({ decision }) => {
                                    const removed =
                                        build.disabledDecisions.includes(
                                            decision.id,
                                        );

                                    return (
                                        <li
                                            key={decision.id}
                                            title={decision.detail ?? undefined}
                                            className={cn(
                                                'inline-flex h-8 max-w-full items-center gap-1.5 rounded-lg border pr-1 pl-2.5 type-small',
                                                removed
                                                    ? 'border-dashed text-muted-foreground'
                                                    : 'bg-card',
                                                !canUpdate && 'pr-2.5',
                                            )}
                                        >
                                            <Sparkles
                                                aria-hidden="true"
                                                className="size-3.5 shrink-0 text-muted-foreground"
                                            />
                                            <span
                                                className={cn(
                                                    'truncate',
                                                    removed && 'line-through',
                                                )}
                                            >
                                                {decision.title}
                                            </span>
                                            {removed && (
                                                <span className="sr-only">
                                                    (removed)
                                                </span>
                                            )}
                                            {canUpdate && (
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        toggleDecision(
                                                            decision.id,
                                                        )
                                                    }
                                                    className="flex size-6 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-hover hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                                    aria-label={
                                                        removed
                                                            ? `Restore: ${decision.title}`
                                                            : `Remove: ${decision.title}`
                                                    }
                                                >
                                                    {removed ? (
                                                        <Plus className="size-3.5" />
                                                    ) : (
                                                        <X className="size-3.5" />
                                                    )}
                                                </button>
                                            )}
                                        </li>
                                    );
                                })}
                            </ul>
                            <InputError
                                message={
                                    error('disabledDecisions') ??
                                    error('disabledDecisions.0')
                                }
                            />
                        </SettingsBody>
                    </SettingsCard>
                )}

                <SettingsCard title="When to build">
                    <SettingsRow
                        setting="watch-paths"
                        label="Watch paths"
                        description="Only pushes that touch these paths deploy. Comma-separated globs."
                        htmlFor="build-watch"
                    >
                        <div className="flex w-full flex-col gap-1 sm:w-72">
                            <Input
                                id="build-watch"
                                value={build.watchPaths.join(', ')}
                                disabled={!canUpdate}
                                placeholder="Every push deploys"
                                className="font-mono text-[13px]"
                                onChange={(event) =>
                                    set({
                                        watchPaths: event.target.value
                                            .split(',')
                                            .map((path) => path.trim())
                                            .filter(Boolean),
                                    })
                                }
                            />
                            <InputError message={error('watchPaths')} />
                        </div>
                    </SettingsRow>
                    <OverrideRow
                        setting="railpack-version"
                        label="Railpack version"
                        source="Pinned by launchroom:"
                        detected={
                            detected?.railpackVersion ??
                            settings.defaultRailpackVersion
                        }
                        value={build.railpackVersion}
                        disabled={!canUpdate}
                        helper="Match a server you updated yourself."
                        onChange={(railpackVersion) => set({ railpackVersion })}
                        error={error('railpackVersion')}
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

            {build.builder !== 'dockerfile' && (
                <SettingsCard
                    setting="railpack-json"
                    title="Keep it with your code"
                    footer={
                        detected?.planJson ? (
                            <button
                                type="button"
                                className="text-signal underline-offset-4 hover:underline"
                                onClick={() => setJsonOpen(true)}
                            >
                                View the full build plan
                            </button>
                        ) : undefined
                    }
                >
                    <SettingsRow
                        media={
                            <span className="flex size-8 items-center justify-center rounded-lg border bg-recessed text-muted-foreground">
                                <FileCode2
                                    aria-hidden="true"
                                    className="size-4"
                                />
                            </span>
                        }
                        label="railpack.json"
                        description={`Commit it to ${settings.source.rootDirectory ? `${settings.source.rootDirectory}/` : 'the root of your repository'} so the build settings travel with your code.`}
                    >
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={
                                !detected && build.installCommand === null
                            }
                            onClick={() =>
                                download(
                                    'railpack.json',
                                    railpackJson(
                                        build,
                                        detected,
                                        settings.deploy.startCommand
                                            ? settings.deploy.startCommand.join(
                                                  ' ',
                                              )
                                            : null,
                                    ),
                                )
                            }
                        >
                            <Download />
                            Download
                        </Button>
                    </SettingsRow>
                </SettingsCard>
            )}
            <GenerateCard
                context={context}
                onOpen={() => openGenerate('on_demand')}
            />
            {panel && (
                <DockerfileGenerationSheet
                    open={generate.open}
                    onOpenChange={(open) =>
                        setGenerate((current) => ({ ...current, open }))
                    }
                    route={context.route}
                    panel={panel}
                    trigger={generate.trigger}
                />
            )}
            {detected?.planJson && (
                <PlanJsonDialog
                    plan={detected.planJson}
                    open={jsonOpen}
                    onOpenChange={setJsonOpen}
                />
            )}
        </SettingsPage>
    );
}
