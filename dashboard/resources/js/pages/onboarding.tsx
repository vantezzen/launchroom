import { Head, Link, router } from '@inertiajs/react';
import { Check } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import { Logo } from '@/components/logo';
import { LiveWait } from '@/components/patterns/live-wait';
import { MetaLine } from '@/components/patterns/meta-line';
import { Page, PageHeader } from '@/components/patterns/page';
import { TopBarActions } from '@/components/patterns/top-bar';
import { Status } from '@/components/status';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { AppsDomainPicker } from '@/features/domains/apps-domain-setup';
import { GitHubSetupSteps } from '@/features/git/github-setup-steps';
import { ManifestRedirect } from '@/features/git/manifest-redirect';
import { WebhookReachabilityNote } from '@/features/git/webhook-reachability-note';
import {
    ServerDevice,
    serverFacts,
} from '@/features/onboarding/server-summary';
import { ProgressRing, StepCard } from '@/features/onboarding/step-card';
import type { GuidedStepStatus } from '@/features/onboarding/step-card';
import { TwoFactorRecommendation } from '@/features/onboarding/two-factor-recommendation';
import { SmallServerHint } from '@/features/servers/small-server-hint';
import { cn } from '@/lib/utils';
import { settings as backupSettings } from '@/routes/backups';
import { edit as gitSettings } from '@/routes/git';
import { store as startManifest } from '@/routes/git/github/manifest';
import onboardingRoutes from '@/routes/onboarding';
import {
    importMethod as importProject,
    index as projects,
} from '@/routes/projects';

type Onboarding = App.Data.Instance.OnboardingData;
type Step = App.Data.Instance.OnboardingStepData;

function update(data: Record<string, string | boolean>) {
    router.patch(onboardingRoutes.update().url, data, {
        preserveScroll: true,
        preserveState: true,
    });
}

/** The free sslip.io wildcard for this server ("*.203-0-113-7.sslip.io"). */
function freeWildcard(data: Onboarding): string | null {
    return data.sslipExample
        ? `*.${data.sslipExample.split('.').slice(1).join('.')}`
        : null;
}

function plural(count: number, one: string, many: string): string {
    return `${count} ${count === 1 ? one : many}`;
}

type StepView = {
    title: string;
    /** One line under the title: the result when done, the ask when open. */
    line?: ReactNode;
    /** Right side of the collapsed row. */
    aside?: ReactNode;
    body: ReactNode;
};

function SkipLink({
    step,
    label = 'Skip for now',
}: {
    step: Step;
    label?: string;
}) {
    if (!step.skippable) {
        return null;
    }

    return step.status === 'skipped' ? (
        <button
            type="button"
            className="self-start type-small text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
            onClick={() => update({ unskip: step.id })}
        >
            Undo skip
        </button>
    ) : step.status === 'pending' ? (
        <button
            type="button"
            className="self-start type-small text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
            onClick={() => update({ skip: step.id })}
        >
            {label}
        </button>
    ) : null;
}

function serverView(data: Onboarding, step: Step): StepView {
    const server = data.server;

    if (step.status === 'done' && server) {
        return {
            title: 'Your server is connected',
            line: <MetaLine>{[server.name, ...serverFacts(server)]}</MetaLine>,
            aside: <ServerDevice server={server} size={72} />,
            body: (
                <div className="flex flex-col items-center gap-4 rounded-xl bg-recessed px-6 py-6 text-center sm:flex-row sm:text-left">
                    <ServerDevice server={server} size={160} />
                    <div className="flex flex-col gap-1">
                        <span className="type-heading">{server.name}</span>
                        <Status state="active" label="Online" />
                        {data.serverAddress && (
                            <span className="type-mono-small text-muted-foreground">
                                {data.serverAddress}
                            </span>
                        )}
                    </div>
                </div>
            ),
        };
    }

    return {
        title: 'Connect this server',
        line: 'The installer connects it to this dashboard.',
        body: (
            <LiveWait
                media={<ServerDevice server={server} size={160} />}
                status={
                    server
                        ? `Waiting for ${server.name} to come online`
                        : 'Waiting for this server'
                }
                hint="Usually under a minute after the install finishes. This page updates automatically."
                fallback={
                    <span className="type-small text-muted-foreground">
                        Still waiting? Run{' '}
                        <code className="type-mono-small">
                            ./launchroom status
                        </code>{' '}
                        on the server.
                    </span>
                }
            />
        ),
    };
}

function GitHubConnect() {
    const [organization, setOrganization] = useState('');
    const [showOrganization, setShowOrganization] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const start = (event: FormEvent) => {
        event.preventDefault();
        router.post(
            startManifest().url,
            {
                returnTo: 'onboarding',
                ...(organization.trim() !== ''
                    ? { organization: organization.trim() }
                    : {}),
            },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (failure) => setErrors(failure),
            },
        );
    };

    return (
        <form onSubmit={start} className="flex flex-col gap-4">
            {showOrganization && (
                <div className="grid max-w-[360px] gap-2">
                    <Label htmlFor="github-organization">
                        GitHub organization
                    </Label>
                    <Input
                        id="github-organization"
                        value={organization}
                        onChange={(event) =>
                            setOrganization(event.target.value)
                        }
                        autoComplete="off"
                        spellCheck={false}
                        placeholder="acme"
                        className="type-mono"
                        autoFocus
                    />
                    <InputError message={errors.organization} />
                </div>
            )}
            <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                <Button type="submit" size="lg" disabled={processing}>
                    {processing ? (
                        <Spinner />
                    ) : (
                        <Logo kind="github" size={20} decorative />
                    )}
                    Create the app on GitHub
                </Button>
                {!showOrganization && (
                    <button
                        type="button"
                        className="type-small text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                        onClick={() => setShowOrganization(true)}
                    >
                        For an organization?
                    </button>
                )}
            </div>
            <InputError message={errors.githubUrl ?? errors.github} />
        </form>
    );
}

function githubView(
    data: Onboarding,
    step: Step,
    manifest: App.Data.Git.ManifestRedirectData | null,
    unreachableWebhookUrl: string | null,
): StepView {
    if (step.status === 'done') {
        return {
            title: 'GitHub is connected',
            line: data.githubAccount ? (
                <MetaLine>
                    {data.githubAccount}
                    {plural(
                        data.githubRepositories,
                        'repository',
                        'repositories',
                    )}
                </MetaLine>
            ) : (
                (data.githubAppName ?? undefined)
            ),
            aside: <Logo kind="github" size={32} decorative />,
            body: (
                <div className="flex flex-wrap gap-2">
                    {data.githubInstallUrl && (
                        <Button asChild variant="outline">
                            <a href={data.githubInstallUrl}>
                                Choose repositories on GitHub
                            </a>
                        </Button>
                    )}
                    <Button asChild variant="ghost">
                        <Link href={gitSettings()}>Git settings</Link>
                    </Button>
                </div>
            ),
        };
    }

    const installOnly = data.githubAppName !== null;

    return {
        title: installOnly ? 'Install the GitHub App' : 'Connect GitHub',
        line:
            step.status === 'skipped'
                ? 'Skipped'
                : installOnly
                  ? 'Step 2 of 2: install the app and pick the repositories to deploy.'
                  : 'Create the GitHub App, then install it. After that, every push deploys.',
        aside: <Logo kind="github" size={32} decorative />,
        body: (
            <>
                {!data.canConnectGitHub ? (
                    <div>
                        <Button asChild variant="outline">
                            <Link href={gitSettings()}>Open Git settings</Link>
                        </Button>
                    </div>
                ) : (
                    <div className="flex flex-col gap-4">
                        {unreachableWebhookUrl !== null && !installOnly && (
                            <WebhookReachabilityNote
                                webhookUrl={unreachableWebhookUrl}
                            />
                        )}
                        <GitHubSetupSteps
                            appName={data.githubAppName}
                            installUrl={data.githubInstallUrl}
                            createAction={
                                manifest !== null ? (
                                    <ManifestRedirect manifest={manifest} />
                                ) : (
                                    <GitHubConnect />
                                )
                            }
                        />
                    </div>
                )}
                <SkipLink step={step} />
            </>
        ),
    };
}

function DomainBody({ data, step }: { data: Onboarding; step: Step }) {
    return (
        <AppsDomainPicker
            domain={data.appsDomain}
            addresses={
                data.edgeAddresses.length > 0
                    ? data.edgeAddresses
                    : data.serverAddress
                      ? [data.serverAddress]
                      : []
            }
            freeWildcard={freeWildcard(data)}
            dashboardHost={data.dashboardHost}
            usingFree={step.status === 'skipped'}
            freeAction={
                <Button onClick={() => update({ skip: 'domain' })}>
                    Use free addresses
                </Button>
            }
            mode="onboarding"
            done={step.status === 'done'}
            footer={step.status === 'pending' ? <SkipLink step={step} /> : null}
        />
    );
}

function domainView(data: Onboarding, step: Step): StepView {
    const free = freeWildcard(data);
    const body = <DomainBody data={data} step={step} />;

    if (step.status === 'done') {
        return {
            title: 'Your apps have an address',
            line:
                data.appsDomainVerified === null ? (
                    `*.${data.appsDomain}`
                ) : (
                    <MetaLine>
                        {`*.${data.appsDomain}`}
                        {data.appsDomainVerified
                            ? 'DNS verified'
                            : 'DNS not verified yet'}
                    </MetaLine>
                ),
            body,
        };
    }

    if (step.status === 'skipped') {
        return {
            title: 'Your apps use a free address for now',
            line: free ?? 'sslip.io addresses',
            body,
        };
    }

    return {
        title: 'Give your apps an address',
        line: data.appsDomain
            ? 'Create the DNS record, then continue once it reaches your server.'
            : 'Every app gets an address under this domain. You can add custom domains per app later.',
        body,
    };
}

const frameworks = ['nextjs', 'laravel', 'node', 'python', 'vite'] as const;

function projectView(data: Onboarding, step: Step): StepView {
    const tiles = (
        <span className="flex gap-1" aria-hidden="true">
            {frameworks.slice(0, 3).map((kind) => (
                <Logo key={kind} kind={kind} size={32} decorative />
            ))}
        </span>
    );

    if (step.status === 'done') {
        return {
            title: 'Your first app is set up',
            line: plural(data.projectCount, 'project', 'projects'),
            body: (
                <div>
                    <Button asChild variant="outline">
                        <Link href={projects()}>Open projects</Link>
                    </Button>
                </div>
            ),
        };
    }

    return {
        title: 'Deploy your first app',
        line: 'Pick a repository and launchroom detects the rest.',
        aside: tiles,
        body: (
            <>
                <div className="flex flex-wrap gap-2" aria-hidden="true">
                    {frameworks.map((kind) => (
                        <Logo key={kind} kind={kind} size={44} decorative />
                    ))}
                </div>
                <div>
                    {data.canCreateProject ? (
                        <Button asChild size="lg">
                            <Link href={importProject()}>
                                Import a repository
                            </Link>
                        </Button>
                    ) : (
                        <p className="type-small text-muted-foreground">
                            Ask a team admin to create the first project.
                        </p>
                    )}
                </div>
            </>
        ),
    };
}

function backupsView(data: Onboarding, step: Step): StepView {
    const kit = data.recoveryKit;
    const items: [boolean, string][] = [
        [kit.destinationConfigured, 'Storage for launchroom itself'],
        [kit.passphraseSet, 'Recovery passphrase'],
        [kit.kitConfirmedAt !== null, 'Recovery kit saved'],
        [data.teamBackupsConfigured, 'Storage for your databases'],
    ];
    const optional = (
        <span className="rounded-full border px-2 py-0.5 type-small text-muted-foreground">
            Optional
        </span>
    );

    return {
        title:
            step.status === 'done'
                ? 'Backups are off this server'
                : 'Keep a backup off this server',
        line:
            step.status === 'done'
                ? 'Recovery kit saved'
                : step.status === 'skipped'
                  ? 'Skipped'
                  : 'S3-compatible storage you own, and a kit to rebuild everything.',
        aside: step.status === 'done' ? undefined : optional,
        body: (
            <>
                <ul className="grid gap-2 sm:grid-cols-2">
                    {items.map(([done, label]) => (
                        <li
                            key={label}
                            className="flex items-center gap-2 type-body"
                        >
                            {done ? (
                                <Check
                                    aria-hidden="true"
                                    className="size-4 text-status-ok"
                                />
                            ) : (
                                <span
                                    aria-hidden="true"
                                    className="size-4 rounded-full border border-dashed border-border-strong"
                                />
                            )}
                            <span
                                className={cn(!done && 'text-muted-foreground')}
                            >
                                {label}
                            </span>
                            <span className="sr-only">
                                {done ? 'done' : 'not done'}
                            </span>
                        </li>
                    ))}
                </ul>
                {step.status !== 'done' && (
                    <div>
                        <Button asChild variant="outline">
                            <Link href={backupSettings()}>Set up backups</Link>
                        </Button>
                    </div>
                )}
                <SkipLink step={step} label="Not now" />
            </>
        ),
    };
}

function OnboardingStep({
    step,
    number,
    view,
    open,
    onOpen,
}: {
    step: Step;
    number: number;
    view: StepView;
    open: boolean;
    onOpen: () => void;
}) {
    return (
        <StepCard
            id={step.id}
            status={step.status as GuidedStepStatus}
            number={number}
            title={view.title}
            line={view.line}
            aside={view.aside}
            open={open}
            onOpen={onOpen}
        >
            {view.body}
        </StepCard>
    );
}

/**
 * Get started (flows §1, direction §9.12): the hero with a progress ring
 * and the five steps as cards, only the current one open. Done steps are
 * one line with their result.
 */
export default function OnboardingPage({
    onboarding,
    githubManifest = null,
    githubWebhookUrl = '',
    githubWebhookReachable = true,
}: {
    onboarding: Onboarding;
    githubManifest?: App.Data.Git.ManifestRedirectData | null;
    githubWebhookUrl?: string;
    githubWebhookReachable?: boolean;
}) {
    const { progress, steps } = onboarding;
    const current = steps.find((step) => step.status === 'pending')?.id ?? null;
    const [openId, setOpenId] = useState<string | null>(
        githubManifest !== null ? 'github' : current,
    );
    // When a step completes, the next one opens. Saving the domain doesn't
    // complete its step (Continue after the DNS check does), so the records
    // and the check stay in view.
    const [seenCurrent, setSeenCurrent] = useState(current);

    if (current !== seenCurrent) {
        setSeenCurrent(current);
        setOpenId(current);
    }
    const waitingForServer = onboarding.server?.status !== 'online';
    const serverName = onboarding.server?.name;

    // "Waiting for this server" updates by itself.
    useEffect(() => {
        if (!waitingForServer) {
            return;
        }

        const interval = window.setInterval(() => {
            if (document.visibilityState === 'visible') {
                router.reload({ only: ['onboarding'] });
            }
        }, 5000);

        return () => window.clearInterval(interval);
    }, [waitingForServer]);

    const views: Record<string, (step: Step) => StepView> = {
        server: (step) => serverView(onboarding, step),
        github: (step) =>
            githubView(
                onboarding,
                step,
                githubManifest,
                githubWebhookReachable ? null : githubWebhookUrl,
            ),
        domain: (step) => domainView(onboarding, step),
        project: (step) => projectView(onboarding, step),
        backups: (step) => backupsView(onboarding, step),
    };

    const remaining = progress.total - progress.done;
    const projectPending = steps.some(
        (step) => step.id === 'project' && step.status === 'pending',
    );

    return (
        <>
            <Head title="Get started" />

            <TopBarActions>
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => update({ dismissed: !progress.dismissed })}
                >
                    {progress.dismissed ? 'Show after sign-in' : 'Hide for now'}
                </Button>
            </TopBarActions>

            <Page size="narrow" className="gap-8">
                <PageHeader
                    variant="display"
                    title={
                        progress.complete
                            ? 'You’re all set.'
                            : 'Your launchroom is up.'
                    }
                    description={
                        progress.complete
                            ? 'Everything is in place. Deploy away.'
                            : projectPending
                              ? `${remaining} short ${remaining === 1 ? 'step' : 'steps'} until your first app is live${serverName ? ` on ${serverName}` : ''}.`
                              : remaining === 1
                                ? 'One more step and you’re done.'
                                : `${remaining} steps left. Nothing here is urgent.`
                    }
                    actions={
                        <ProgressRing
                            done={progress.done}
                            total={progress.total}
                            size={64}
                        />
                    }
                    className="flex-row items-center justify-between"
                />

                <ol className="flex flex-col gap-3">
                    {steps.map((step, index) => (
                        <OnboardingStep
                            key={step.id}
                            step={step}
                            number={index + 1}
                            view={views[step.id](step)}
                            open={openId === step.id}
                            onOpen={() => setOpenId(step.id)}
                        />
                    ))}
                </ol>

                {onboarding.smallServerHint && (
                    <SmallServerHint hint={onboarding.smallServerHint} />
                )}

                {onboarding.recommendTwoFactor && <TwoFactorRecommendation />}
            </Page>
        </>
    );
}

OnboardingPage.layout = {
    breadcrumbs: [{ title: 'Get started', href: onboardingRoutes.show() }],
};
