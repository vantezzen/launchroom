import { Link, router } from '@inertiajs/react';
import {
    Check,
    Copy,
    Download,
    ExternalLink,
    FileCode2,
    FolderTree,
    GitPullRequest,
    KeyRound,
    TriangleAlert,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { SheetSection, SidePanel } from '@/components/patterns/sheet';
import { StepList } from '@/components/steps';
import type { Step } from '@/components/steps/step-list';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { edit as settingsRoute } from '@/routes/dockerfile-generation';
import { formatCost, formatMs, useGeneration } from './api';
import type { Generation } from './api';
import { DockerfileDiff } from './dockerfile-diff';

type Panel = App.Data.Dockerfiles.DockerfileGenerationPanelData;
type Trigger = App.Enums.Dockerfiles.DockerfileGenerationTrigger;

function plural(count: number, word: string): string {
    return `${count} ${word}${count === 1 ? '' : 's'}`;
}

/** Where a failed generation stopped, from its last attempt. */
function failedStep(generation: Generation): string {
    const last = generation.attempts[generation.attempts.length - 1];

    switch (last?.outcome) {
        case 'build':
            return 'build';
        case 'start':
            return 'start';
        case undefined:
        case null:
            return generation.attempts.length === 0 ? 'read' : 'draft';
        default:
            return 'draft';
    }
}

const ORDER = ['read', 'draft', 'build', 'start', 'ready'] as const;

function currentStep(generation: Generation): (typeof ORDER)[number] {
    switch (generation.status) {
        case 'reading':
            return 'read';
        case 'drafting':
            return 'draft';
        case 'building':
            return 'build';
        case 'starting':
            return 'start';
        default:
            return 'ready';
    }
}

/** Why the next draft runs ("Second try: fixing the build"). */
function repairLabel(generation: Generation): string | null {
    if (generation.status !== 'drafting' || generation.attempts.length < 2) {
        return null;
    }

    const failed = generation.attempts[generation.attempts.length - 2];
    const ordinal = ['First', 'Second', 'Third', 'Fourth'][
        generation.attempts.length - 1
    ];
    const what =
        failed?.outcome === 'build'
            ? 'fixing the build'
            : failed?.outcome === 'start'
              ? 'fixing how the app starts'
              : failed?.outcome === 'lint'
                ? 'fixing what launchroom refused'
                : 'trying again';

    return `${ordinal ?? 'Next'} try: ${what}`;
}

function steps(generation: Generation): Step[] {
    const failed =
        generation.status === 'failed' ? failedStep(generation) : null;
    const cancelled = generation.status === 'cancelled';
    const at = failed ?? currentStep(generation);
    const index = ORDER.indexOf(at as (typeof ORDER)[number]);
    const port = generation.port;
    const repair = repairLabel(generation);

    const status = (id: (typeof ORDER)[number]): Step['status'] => {
        const position = ORDER.indexOf(id);

        if (generation.status === 'verified') {
            return 'succeeded';
        }

        if (position < index) {
            return 'succeeded';
        }

        if (position === index) {
            return failed ? 'failed' : cancelled ? 'canceled' : 'running';
        }

        return cancelled ? 'canceled' : 'pending';
    };

    return [
        {
            id: 'read',
            name: 'Reading your repository',
            status: status('read'),
            summary:
                generation.filesSent.length > 0
                    ? `${plural(generation.filesSent.length, 'file')}, ${plural(generation.filesListed, 'path')} listed`
                    : undefined,
        },
        {
            id: 'draft',
            name: 'Drafting a Dockerfile',
            status: status('draft'),
            summary: repair ?? `with ${generation.model}`,
        },
        {
            id: 'build',
            name: 'Building it',
            status: status('build'),
            duration: formatMs(generation.buildMs),
        },
        {
            id: 'start',
            name: port
                ? `Starting your app on port ${port}`
                : 'Starting your app',
            status: status('start'),
            duration: formatMs(generation.startedAfterMs),
        },
        {
            id: 'ready',
            name: 'Ready',
            status:
                generation.status === 'verified'
                    ? 'succeeded'
                    : cancelled
                      ? 'canceled'
                      : 'pending',
        },
    ];
}

function download(contents: string) {
    const url = URL.createObjectURL(
        new Blob([contents], { type: 'text/plain' }),
    );
    const link = document.createElement('a');
    link.href = url;
    link.download = 'Dockerfile';
    link.click();
    URL.revokeObjectURL(url);
}

function Fact({ label, value }: { label: string; value: string | null }) {
    if (!value) {
        return null;
    }

    return (
        <div className="flex min-w-0 flex-col gap-0.5">
            <dt className="type-small text-muted-foreground">{label}</dt>
            <dd className="truncate type-body">{value}</dd>
        </div>
    );
}

/**
 * What leaves the server (docs/20 §2), in three plain rows. Shown before
 * anything is sent, and again as "What launchroom sent".
 */
export function SharingNotice({
    providerLabel,
    model,
}: {
    providerLabel: string;
    model: string;
}) {
    const rows = [
        {
            icon: FolderTree,
            title: 'A list of file names',
            body: 'The paths in the app directory, without dependency and build folders.',
        },
        {
            icon: FileCode2,
            title: 'Manifests and entry files',
            body: 'package.json, pyproject.toml, go.mod and similar, plus the start of likely entry points. Each file is cut at 32 KB.',
        },
        {
            icon: KeyRound,
            title: 'No secrets',
            body: 'No .env files, keys, credentials or variable values. Anything that looks like a credential is redacted.',
        },
    ];

    return (
        <div className="flex flex-col gap-3">
            <ul className="flex flex-col divide-y rounded-xl border bg-recessed">
                {rows.map((row) => (
                    <li key={row.title} className="flex gap-3 px-4 py-3">
                        <row.icon
                            aria-hidden="true"
                            className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                        />
                        <div className="flex min-w-0 flex-col gap-0.5">
                            <span className="type-label">{row.title}</span>
                            <span className="type-small text-muted-foreground">
                                {row.body}
                            </span>
                        </div>
                    </li>
                ))}
            </ul>
            <p className="type-small text-muted-foreground">
                Sent to Anthropic&apos;s {providerLabel} (
                <code className="type-mono-small">{model}</code>) with this
                launchroom&apos;s API key. A run usually costs a few cents, up
                to about $0.50 when repairs are needed.
            </p>
        </div>
    );
}

function Intro({
    panel,
    onStart,
    busy,
}: {
    panel: Panel;
    onStart: () => void;
    busy: boolean;
}) {
    return (
        <>
            <SheetSection>
                <p className="max-w-[60ch] type-body text-muted-foreground">
                    launchroom suggests a Dockerfile for this app, then builds
                    it and starts the app once to check that it answers. You
                    only see it when it works, and nothing changes in your
                    repository.
                </p>
            </SheetSection>
            <SheetSection title="What launchroom sends">
                <SharingNotice
                    providerLabel={panel.providerLabel}
                    model={panel.model}
                />
            </SheetSection>
            <SheetSection>
                <div>
                    <Button onClick={onStart} disabled={busy}>
                        Generate a Dockerfile
                    </Button>
                </div>
            </SheetSection>
        </>
    );
}

function FilesSent({ generation }: { generation: Generation }) {
    const [open, setOpen] = useState(false);

    if (generation.filesSent.length === 0) {
        return null;
    }

    return (
        <SheetSection
            title="What launchroom sent"
            description={`${plural(generation.filesSent.length, 'file')} and a list of ${plural(generation.filesListed, 'path')}. No secrets were included.`}
            control={
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => setOpen(!open)}
                    aria-expanded={open}
                >
                    {open ? 'Hide' : 'Show files'}
                </Button>
            }
        >
            {open && (
                <ul className="flex flex-col gap-1 rounded-xl border bg-recessed px-4 py-3">
                    {generation.filesSent.map((file) => (
                        <li
                            key={file.path}
                            className="flex justify-between gap-4 type-mono-small"
                        >
                            <span className="truncate">{file.path}</span>
                            <span className="shrink-0 text-muted-foreground tabular-nums">
                                {(file.bytes / 1024).toFixed(1)} KB
                                {file.truncated ? ' (cut)' : ''}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </SheetSection>
    );
}

function Verified({
    generation,
    onUse,
    onPullRequest,
    onRestart,
    busy,
}: {
    generation: Generation;
    onUse: () => void;
    onPullRequest: () => void;
    onRestart: (() => void) | null;
    busy: string | null;
}) {
    const [copied, setCopied] = useState(false);
    const dockerfile = generation.dockerfile ?? '';

    return (
        <>
            <SheetSection>
                <div className="flex items-start gap-3">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-status-ok-soft text-status-ok">
                        <Check aria-hidden="true" className="size-[18px]" />
                    </span>
                    <div className="flex min-w-0 flex-col gap-1">
                        <h3 className="type-heading">
                            Your app builds and starts
                        </h3>
                        <p className="type-body text-muted-foreground">
                            launchroom built this Dockerfile and your app
                            answered on port {generation.port}.
                        </p>
                    </div>
                </div>
                <dl className="grid grid-cols-2 gap-x-6 gap-y-3 rounded-xl border bg-recessed px-4 py-3 sm:grid-cols-3">
                    <Fact
                        label="Port"
                        value={generation.port ? String(generation.port) : null}
                    />
                    <Fact
                        label="Built in"
                        value={formatMs(generation.buildMs)}
                    />
                    <Fact
                        label="Answered after"
                        value={formatMs(generation.startedAfterMs)}
                    />
                    <Fact
                        label="Model's confidence"
                        value={generation.confidence}
                    />
                    <Fact
                        label="Tries"
                        value={`${generation.attempt} of ${generation.maxAttempts}`}
                    />
                    <Fact label="Cost" value={formatCost(generation.costUsd)} />
                </dl>
                {generation.startCommand && (
                    <p className="type-small text-muted-foreground">
                        Starts with{' '}
                        <code className="type-mono-small text-foreground">
                            {generation.startCommand}
                        </code>
                    </p>
                )}
            </SheetSection>
            {generation.explanation && (
                <SheetSection title="What it does">
                    <p className="max-w-[65ch] type-body text-muted-foreground">
                        {generation.explanation}
                    </p>
                </SheetSection>
            )}
            <SheetSection
                title={generation.repositoryPath}
                description={
                    generation.previousDockerfile
                        ? 'Changes from the previous attempt are marked.'
                        : 'Base images are pinned to their digests.'
                }
                control={
                    <div className="flex gap-1">
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            aria-label="Copy Dockerfile"
                            onClick={() => {
                                void navigator.clipboard.writeText(dockerfile);
                                setCopied(true);
                                window.setTimeout(() => setCopied(false), 1500);
                            }}
                        >
                            {copied ? <Check /> : <Copy />}
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            aria-label="Download Dockerfile"
                            onClick={() => download(dockerfile)}
                        >
                            <Download />
                        </Button>
                    </div>
                }
            >
                <DockerfileDiff
                    previous={generation.previousDockerfile}
                    next={dockerfile}
                />
            </SheetSection>
            <FilesSent generation={generation} />
            <SheetSection>
                <div className="flex flex-wrap items-center gap-2">
                    {generation.inUse ? (
                        <span className="inline-flex items-center gap-1.5 type-body">
                            <Check
                                aria-hidden="true"
                                className="size-4 text-status-ok"
                            />
                            In use for this service
                        </span>
                    ) : (
                        <Button
                            onClick={onUse}
                            disabled={!generation.canUse || busy !== null}
                        >
                            Use this Dockerfile
                        </Button>
                    )}
                    {generation.pullRequestUrl ? (
                        <Button variant="outline" asChild>
                            <a
                                href={generation.pullRequestUrl}
                                target="_blank"
                                rel="noreferrer"
                            >
                                <ExternalLink />
                                Open the pull request
                            </a>
                        </Button>
                    ) : (
                        generation.canOpenPullRequest && (
                            <Button
                                variant="outline"
                                onClick={onPullRequest}
                                disabled={busy !== null}
                            >
                                <GitPullRequest />
                                Open a pull request
                            </Button>
                        )
                    )}
                    {onRestart && (
                        <Button
                            variant="ghost"
                            onClick={onRestart}
                            disabled={busy !== null}
                        >
                            Generate a new one
                        </Button>
                    )}
                </div>
                <p className="type-small text-muted-foreground">
                    Using it stores the Dockerfile in launchroom and stages a
                    rebuild. Your repository stays as it is
                    {generation.pullRequestProvider
                        ? `, unless you open a pull request on ${generation.pullRequestProvider}`
                        : ''}
                    .
                </p>
            </SheetSection>
        </>
    );
}

function Failed({
    generation,
    onRetry,
    busy,
}: {
    generation: Generation;
    onRetry: () => void;
    busy: boolean;
}) {
    const [show, setShow] = useState(false);

    return (
        <>
            <SheetSection>
                <div className="flex items-start gap-3">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-status-progress-soft text-status-progress">
                        <TriangleAlert
                            aria-hidden="true"
                            className="size-[18px]"
                        />
                    </span>
                    <div className="flex min-w-0 flex-col gap-1">
                        <h3 className="type-heading">
                            launchroom couldn&apos;t get a Dockerfile to work
                        </h3>
                        <p className="max-w-[65ch] type-body text-muted-foreground">
                            {generation.error ??
                                'The last try did not build or start.'}
                        </p>
                    </div>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button onClick={onRetry} disabled={busy}>
                        Try again
                    </Button>
                    {generation.dockerfile && (
                        <Button
                            variant="outline"
                            onClick={() => setShow(!show)}
                            aria-expanded={show}
                        >
                            {show
                                ? 'Hide the last Dockerfile'
                                : 'Show the last Dockerfile'}
                        </Button>
                    )}
                </div>
                {show && generation.dockerfile && (
                    <DockerfileDiff
                        previous={null}
                        next={generation.dockerfile}
                    />
                )}
            </SheetSection>
            <FilesSent generation={generation} />
        </>
    );
}

/**
 * "Generate a Dockerfile" (docs/20 §7): intro with what is sent → live
 * progress (read, draft, build, start) → the verified Dockerfile as a diff
 * with its explanation and Use / Open a pull request. Calm and honest:
 * "launchroom suggests", drafted with Claude, verified by a real build.
 */
export function DockerfileGenerationSheet({
    open,
    onOpenChange,
    route,
    panel,
    trigger,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    route: [project: string, environment: string, service: string];
    panel: Panel;
    trigger: Trigger;
}) {
    const flow = useGeneration(route, panel.latest);
    const generation = flow.generation;
    const couldNotDetect = trigger === 'plan_failed' || panel.planFailed;

    const title =
        generation?.status === 'verified'
            ? 'Your Dockerfile is ready'
            : couldNotDetect && !generation
              ? "launchroom couldn't detect how to build this app"
              : 'Generate a Dockerfile';

    const start = () => void flow.start(trigger);

    return (
        <SidePanel
            open={open}
            onOpenChange={onOpenChange}
            size="lg"
            title={title}
            description={
                generation && flow.running
                    ? 'This takes a minute or two. You can close this and come back.'
                    : undefined
            }
            media={
                <span className="flex size-10 items-center justify-center rounded-xl border bg-recessed text-muted-foreground">
                    <FileCode2 aria-hidden="true" className="size-5" />
                </span>
            }
        >
            {flow.error && (
                <SheetSection>
                    <p role="alert" className="type-body text-status-error">
                        {flow.error}
                    </p>
                </SheetSection>
            )}
            {!generation || generation.status === 'cancelled' ? (
                panel.canStart ? (
                    <Intro
                        panel={panel}
                        onStart={start}
                        busy={flow.busy !== null}
                    />
                ) : (
                    <SheetSection>
                        <p className="type-body text-muted-foreground">
                            {panel.reason === 'team_off'
                                ? 'Dockerfile generation is off for this team.'
                                : 'Dockerfile generation is not set up on this launchroom yet.'}{' '}
                            {panel.canEnable ? (
                                <Link
                                    href={settingsRoute().url}
                                    className="text-signal underline-offset-4 hover:underline"
                                >
                                    Turn it on in Settings
                                </Link>
                            ) : (
                                'An owner can turn it on in Settings → Dockerfile generation.'
                            )}
                        </p>
                    </SheetSection>
                )
            ) : (
                <>
                    {generation.status !== 'verified' &&
                        generation.status !== 'failed' && (
                            <SheetSection>
                                <div aria-live="polite">
                                    <StepList steps={steps(generation)} />
                                </div>
                                {generation.canCancel && (
                                    <div>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => void flow.cancel()}
                                            disabled={flow.busy !== null}
                                        >
                                            Cancel
                                        </Button>
                                    </div>
                                )}
                            </SheetSection>
                        )}
                    {generation.status === 'verified' && (
                        <Verified
                            generation={generation}
                            busy={flow.busy}
                            onRestart={panel.canStart ? flow.reset : null}
                            onUse={() =>
                                void flow.use().then((next) => {
                                    if (next) {
                                        toast.success(
                                            'Dockerfile saved. Deploy to build with it.',
                                        );
                                        router.reload();
                                    }
                                })
                            }
                            onPullRequest={() =>
                                void flow.pullRequest().then((next) => {
                                    if (next?.pullRequestUrl) {
                                        toast.success('Pull request opened.', {
                                            action: {
                                                label: 'Open',
                                                onClick: () =>
                                                    window.open(
                                                        next.pullRequestUrl ??
                                                            '',
                                                        '_blank',
                                                        'noreferrer',
                                                    ),
                                            },
                                        });
                                    }
                                })
                            }
                        />
                    )}
                    {generation.status === 'failed' && (
                        <Failed
                            generation={generation}
                            busy={flow.busy !== null || !panel.canStart}
                            onRetry={start}
                        />
                    )}
                </>
            )}
            <SheetSection className={cn(!generation && 'hidden')}>
                <p className="type-small text-faint">
                    Drafted with {generation?.model ?? panel.model}. launchroom
                    checks every Dockerfile for pinned base images, host access
                    and secrets in the image.
                </p>
            </SheetSection>
        </SidePanel>
    );
}
