import { Link, router, usePage } from '@inertiajs/react';
import { useQuery } from '@tanstack/react-query';
import { ArrowUpRight, ChevronRight, Copy, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Duration, Elapsed } from '@/components/elapsed';
import { Logo } from '@/components/logo';
import { StatusIcon } from '@/components/status';
import { STEP_STATUS_LABEL, StepIcon } from '@/components/steps/step-icon';
import { Button } from '@/components/ui/button';
import { quietDeployToasts } from '@/features/deployments/deploy-toasts';
import { requestJson } from '@/lib/http';
import { settings as backupSettings } from '@/routes/backups';
import { show as deploymentShow } from '@/routes/deployments';
import { edit as gitSettings } from '@/routes/git';
import onboardingRoutes from '@/routes/onboarding';
import services from '@/routes/projects/services';

type FirstApp = App.Data.Instance.FirstAppData;

/**
 * "Your first app is live" (flows §5, docs/08 §3.1): on the project canvas
 * while the team's first deploy runs (its steps, live) and for an hour after
 * it went live (the address and where to go next), until dismissed. Instance
 * owners only, once per instance. The deploy toasts for that service stay
 * quiet while it shows.
 */
export function FirstLiveCard({
    project,
    environment,
}: {
    project: string;
    environment: string;
}) {
    const owner = usePage().props.onboardingProgress != null;
    const [closed, setClosed] = useState(false);
    const { data } = useQuery({
        queryKey: ['first-app'],
        enabled: owner && !closed,
        queryFn: ({ signal }) =>
            requestJson<{ firstApp: FirstApp | null }>(
                'get',
                onboardingRoutes.firstApp().url,
                undefined,
                signal,
            ),
        refetchInterval: (query) =>
            query.state.data?.firstApp?.state === 'deploying' ? 3_000 : false,
    });
    const app = data?.firstApp ?? null;
    const shown =
        !closed &&
        app !== null &&
        app.projectId === project &&
        app.environmentId === environment;

    useEffect(
        () => (shown && app ? quietDeployToasts(app.serviceId) : undefined),
        [shown, app],
    );

    if (!shown || !app) {
        return null;
    }

    const dismiss = () => {
        setClosed(true);
        router.post(
            onboardingRoutes.firstApp.dismiss().url,
            {},
            { preserveScroll: true, preserveState: true },
        );
    };

    return (
        <aside
            aria-label="Your first app"
            className="absolute inset-x-4 top-4 z-20 flex flex-col gap-4 rounded-[14px] border bg-card p-4 shadow-float sm:right-auto sm:w-[360px]"
        >
            {app.state === 'live' ? (
                <Live app={app} onDismiss={dismiss} />
            ) : (
                <Deploying app={app} />
            )}
        </aside>
    );
}

function Deploying({ app }: { app: FirstApp }) {
    return (
        <>
            <header className="flex items-center gap-3">
                <Logo
                    kind={app.logo}
                    name={app.serviceName}
                    size={32}
                    decorative
                />
                <div className="flex min-w-0 flex-1 flex-col">
                    <h2 className="truncate type-label">
                        Deploying {app.serviceName}
                    </h2>
                    <span className="type-small text-muted-foreground tabular-nums">
                        <Elapsed since={app.startedAt} />
                    </span>
                </div>
                {app.deploymentId && (
                    <Link
                        href={
                            deploymentShow({
                                project: app.projectId,
                                environment: app.environmentId,
                                deployment: app.deploymentId,
                            }).url
                        }
                        className="inline-flex shrink-0 items-center gap-0.5 rounded-sm type-small text-muted-foreground underline-offset-4 hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        Logs
                        <ChevronRight aria-hidden="true" className="size-3.5" />
                    </Link>
                )}
            </header>
            <ol className="flex flex-col gap-2.5">
                {app.steps.map((step) => (
                    <li
                        key={step.label}
                        className="flex items-center gap-2.5 type-small"
                    >
                        <StepIcon status={step.status} size={16} />
                        <span
                            className={
                                step.status === 'pending'
                                    ? 'flex-1 text-muted-foreground'
                                    : 'flex-1'
                            }
                        >
                            {step.label}
                        </span>
                        <span className="text-muted-foreground tabular-nums">
                            {step.status === 'running' ? (
                                <Elapsed since={step.startedAt} />
                            ) : step.status === 'succeeded' &&
                              step.startedAt &&
                              step.finishedAt ? (
                                <Duration
                                    seconds={
                                        (Date.parse(step.finishedAt) -
                                            Date.parse(step.startedAt)) /
                                        1000
                                    }
                                />
                            ) : (
                                STEP_STATUS_LABEL[step.status]
                            )}
                        </span>
                    </li>
                ))}
            </ol>
        </>
    );
}

function Live({ app, onDismiss }: { app: FirstApp; onDismiss: () => void }) {
    const url = app.hostname ? `https://${app.hostname}` : null;
    const next = [
        {
            label: 'Use your own domain',
            href: services.section({
                project: app.projectId,
                environment: app.environmentId,
                service: app.serviceId,
                section: 'domains',
            }).url,
        },
        ...(app.suggestGit
            ? [{ label: 'Deploy on every push', href: gitSettings().url }]
            : []),
        { label: 'Keep a backup off this server', href: backupSettings().url },
    ];

    return (
        <>
            <header className="flex items-start gap-3">
                <Logo
                    kind={app.logo}
                    name={app.serviceName}
                    size={32}
                    decorative
                />
                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <h2 className="flex items-center gap-1.5 type-label">
                        <StatusIcon state="active" />
                        Your first app is live
                    </h2>
                    <span className="truncate type-small text-muted-foreground">
                        {app.serviceName} in {app.projectName}
                    </span>
                </div>
                <Button
                    variant="ghost"
                    size="icon-sm"
                    aria-label="Dismiss"
                    onClick={onDismiss}
                    className="-mt-1 -mr-2 shrink-0 text-muted-foreground"
                >
                    <X aria-hidden="true" />
                </Button>
            </header>
            {url && app.hostname && (
                <div className="flex min-w-0 items-center gap-2 rounded-[10px] border bg-recessed py-1.5 pr-1.5 pl-3">
                    <span className="min-w-0 flex-1 truncate type-mono-small">
                        {app.hostname}
                    </span>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        aria-label="Copy address"
                        onClick={() =>
                            void navigator.clipboard
                                ?.writeText(url)
                                .then(() => toast.success('Copied'))
                        }
                    >
                        <Copy aria-hidden="true" />
                    </Button>
                    <Button asChild size="sm">
                        <a href={url} target="_blank" rel="noreferrer">
                            Open
                            <ArrowUpRight aria-hidden="true" />
                        </a>
                    </Button>
                </div>
            )}
            <nav aria-label="Next steps">
                <ul className="-mx-2 flex flex-col">
                    {next.map((item) => (
                        <li key={item.label}>
                            <Link
                                href={item.href}
                                className="flex items-center gap-2 rounded-md px-2 py-1.5 type-small text-muted-foreground hover:bg-(--hover) hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                <span className="flex-1">{item.label}</span>
                                <ChevronRight
                                    aria-hidden="true"
                                    className="size-3.5"
                                />
                            </Link>
                        </li>
                    ))}
                </ul>
            </nav>
        </>
    );
}
