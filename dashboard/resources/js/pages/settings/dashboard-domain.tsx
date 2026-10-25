import { Form, Head, router, usePoll } from '@inertiajs/react';
import { Check, Globe, LockKeyhole, Minus, RotateCcw, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { CopyValue } from '@/components/copy-value';
import InputError from '@/components/input-error';
import { MetaLine } from '@/components/patterns/meta-line';
import { StatusPill } from '@/components/status';
import type { StatusState } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    LearnMore,
    SettingsBody,
    SettingsCard,
    SettingsRow,
    useDocsUrl,
} from '@/features/settings/settings-card';
import { cn } from '@/lib/utils';
import {
    activate,
    check,
    destroy,
    revert,
    store,
} from '@/routes/dashboard-domain';

type Settings = App.Data.Instance.DashboardDomainSettingsData;
type Change = App.Data.Instance.DashboardDomainChangeData;
type Step = App.Data.Instance.DashboardDomainStepData;

const stepTitles: Record<string, { title: string; waiting: string }> = {
    dns: {
        title: 'Point DNS at this server',
        waiting: 'Waiting for the record to show up',
    },
    certificate: {
        title: 'Get a certificate',
        waiting: 'Caddy is getting one from Let’s Encrypt',
    },
    https: {
        title: 'Test HTTPS',
        waiting: 'Testing once the certificate is there',
    },
};

const serverStates: Record<string, { label: string; state: StatusState }> = {
    adopted: { label: 'Uses the new address', state: 'active' },
    configured: { label: 'Uses its install address', state: 'idle' },
    fallback: { label: 'On its fallback', state: 'warning' },
    pending: { label: 'Checking the new address', state: 'progress' },
    refused: { label: 'Stays on its address', state: 'idle' },
    unknown: { label: 'No report yet', state: 'idle' },
};

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

/**
 * Settings → Dashboard domain (docs/05 §4.1, docs/08 §17), instance
 * owners: enter a domain, point DNS here, let Caddy get a certificate,
 * test HTTPS, then switch. One guided flow; the steps update by
 * themselves while the domain is being set up.
 */
export default function DashboardDomain({ settings }: { settings: Settings }) {
    const { change } = settings;
    const waiting = change !== null && change.status === 'pending';
    const { start, stop } = usePoll(5000, {}, { autoStart: false });

    useEffect(() => {
        if (waiting) {
            start();
        } else {
            stop();
        }

        return stop;
    }, [waiting, start, stop]);

    return (
        <>
            <Head title="Dashboard domain" />

            <CurrentAddress settings={settings} />

            {change === null ? (
                <NewDomain settings={settings} />
            ) : (
                <ChangeFlow settings={settings} change={change} />
            )}

            {settings.legacy.length > 0 && (
                <EarlierAddresses settings={settings} />
            )}

            {settings.servers.length > 0 &&
                (settings.current.switched || settings.legacy.length > 0) && (
                    <ServerAddresses settings={settings} />
                )}

            {settings.githubApps.length > 0 &&
                (settings.current.switched || change?.status === 'ready') && (
                    <GitHubApps settings={settings} />
                )}

            <SettingsCard
                title="Locked out"
                description="Run this on the main server to go back to the install address."
            >
                <SettingsBody>
                    <CopyValue
                        value={settings.resetCommand}
                        label="reset command"
                    />
                </SettingsBody>
            </SettingsCard>
        </>
    );
}

function CurrentAddress({ settings }: { settings: Settings }) {
    const { current } = settings;
    const secure = current.https && !current.ip;
    const Icon = secure ? LockKeyhole : Globe;

    return (
        <div className="overflow-hidden rounded-xl border bg-card shadow-card">
            <div className="flex flex-col gap-5 p-6 sm:flex-row sm:items-start sm:justify-between">
                <div className="flex min-w-0 items-start gap-4">
                    <span className="flex size-11 shrink-0 items-center justify-center rounded-full bg-recessed">
                        <Icon
                            aria-hidden="true"
                            className="size-5 text-muted-foreground"
                        />
                    </span>
                    <div className="flex min-w-0 flex-col gap-1">
                        <h2 className="type-stat break-all">{current.host}</h2>
                        <MetaLine className="type-body text-muted-foreground">
                            {current.switched
                                ? 'Your dashboard’s own domain'
                                : 'The address launchroom was installed with'}
                            {secure ? 'HTTPS' : 'No certificate'}
                        </MetaLine>
                    </div>
                </div>
                {settings.switchBackTo !== null && (
                    <SwitchBack target={settings.switchBackTo} />
                )}
            </div>
            <div className="border-t bg-recessed px-6 py-3 type-small text-muted-foreground">
                {current.switched && current.switchedAt ? (
                    <>
                        Switched <TimeAgo value={current.switchedAt} />. It was{' '}
                        <span className="type-mono-small">
                            {current.installUrl}
                        </span>{' '}
                        at install.
                    </>
                ) : (
                    <>
                        Changing this address doesn’t affect your apps’ domains.
                    </>
                )}
            </div>
        </div>
    );
}

function NewDomain({ settings }: { settings: Settings }) {
    const docsUrl = useDocsUrl();
    return (
        <SettingsCard
            title={
                settings.current.switched
                    ? 'Move to another domain'
                    : 'Use your own domain'
            }
            description="A name like dash.example.com, with HTTPS from Let’s Encrypt. Nothing changes until you switch."
            footer={
                !settings.routable ? (
                    <>
                        This launchroom doesn’t route the dashboard through its
                        main server yet, so the HTTPS test can’t pass here.
                    </>
                ) : (
                    <>
                        Use a different name than your apps’ domain, so apps
                        can’t read the dashboard’s cookies.{' '}
                        <LearnMore
                            href={docsUrl('operations/dashboard-domain')}
                        />
                    </>
                )
            }
        >
            <SettingsBody>
                <Form
                    {...store.form()}
                    options={{ preserveScroll: true }}
                    className="flex flex-col gap-3 sm:flex-row sm:items-start"
                    disableWhileProcessing
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid flex-1 gap-2">
                                <Label
                                    htmlFor="dashboard-hostname"
                                    className="sr-only"
                                >
                                    Domain
                                </Label>
                                <Input
                                    id="dashboard-hostname"
                                    name="hostname"
                                    placeholder="dash.example.com"
                                    autoComplete="off"
                                    spellCheck={false}
                                    className="font-mono"
                                    required
                                />
                                <InputError message={errors.hostname} />
                            </div>
                            <Button type="submit" disabled={processing}>
                                {processing && <Spinner />}
                                Continue
                            </Button>
                        </>
                    )}
                </Form>
            </SettingsBody>
        </SettingsCard>
    );
}

function StepIcon({ state, number }: { state: string; number: number }) {
    if (state === 'done') {
        return (
            <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-foreground text-background">
                <Check aria-hidden="true" className="size-4" />
                <span className="sr-only">Done</span>
            </span>
        );
    }

    if (state === 'failed') {
        return (
            <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-status-error-soft text-status-error">
                <X aria-hidden="true" className="size-4" />
                <span className="sr-only">Needs a fix</span>
            </span>
        );
    }

    if (state === 'waiting') {
        return (
            <span className="flex size-8 shrink-0 items-center justify-center rounded-full border">
                <Spinner className="size-4 text-status-progress" />
                <span className="sr-only">In progress</span>
            </span>
        );
    }

    return (
        <span className="flex size-8 shrink-0 items-center justify-center rounded-full border type-label text-muted-foreground tabular-nums">
            {number}
            <span className="sr-only">Not yet</span>
        </span>
    );
}

function StepRow({
    step,
    number,
    children,
}: {
    step: Step;
    number: number;
    children?: ReactNode;
}) {
    const text = stepTitles[step.key];
    const line =
        step.state === 'done'
            ? step.key === 'certificate'
                ? 'Issued'
                : (step.detail ?? 'Done')
            : step.state === 'blocked'
              ? 'After the step above'
              : step.state === 'failed'
                ? (step.detail ?? 'Needs a fix')
                : (step.detail ?? text.waiting);

    return (
        <li className="flex gap-4 px-5 py-4">
            <StepIcon state={step.state} number={number} />
            <div className="flex min-w-0 flex-1 flex-col gap-3 pt-1">
                <div className="flex flex-col gap-0.5">
                    <p
                        className={cn(
                            'type-body font-medium',
                            step.state === 'blocked' && 'text-muted-foreground',
                        )}
                    >
                        {text.title}
                    </p>
                    <p
                        className={cn(
                            'type-small',
                            step.state === 'failed'
                                ? 'text-status-error'
                                : 'text-muted-foreground',
                        )}
                    >
                        {line}
                    </p>
                </div>
                {children}
            </div>
        </li>
    );
}

function ChangeFlow({
    settings,
    change,
}: {
    settings: Settings;
    change: Change;
}) {
    const [dns, certificate, https] = change.steps;
    const ready = change.status === 'ready';

    return (
        <SettingsCard
            title={change.hostname}
            description={
                ready
                    ? 'Everything checks out. Switch when you’re ready.'
                    : 'Setting up. This page updates by itself.'
            }
            actions={<CancelChange hostname={change.hostname} />}
            footer={
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <span>
                        {change.checkedAt ? (
                            <>
                                Checked <TimeAgo value={change.checkedAt} />,
                                again every minute.
                            </>
                        ) : (
                            'Checking…'
                        )}
                    </span>
                    <Form {...check.form()} options={{ preserveScroll: true }}>
                        {({ processing }) => (
                            <Button
                                type="submit"
                                variant="outline"
                                size="sm"
                                disabled={processing}
                            >
                                {processing ? <Spinner /> : <RotateCcw />}
                                Check now
                            </Button>
                        )}
                    </Form>
                </div>
            }
        >
            <ol className="divide-y">
                <StepRow step={dns} number={1}>
                    {dns.state !== 'done' && <DnsRecords settings={settings} />}
                </StepRow>
                <StepRow step={certificate} number={2} />
                <StepRow step={https} number={3} />
                <li className="flex gap-4 px-5 py-4">
                    <StepIcon state={ready ? 'ready' : 'blocked'} number={4} />
                    <div className="flex min-w-0 flex-1 flex-col gap-4 pt-1">
                        <div className="flex flex-col gap-0.5">
                            <p
                                className={cn(
                                    'type-body font-medium',
                                    !ready && 'text-muted-foreground',
                                )}
                            >
                                Switch
                            </p>
                            <p className="type-small text-muted-foreground">
                                {ready
                                    ? 'What changes when you switch:'
                                    : 'After the test passes'}
                            </p>
                        </div>
                        {ready && (
                            <SwitchPlan settings={settings} change={change} />
                        )}
                    </div>
                </li>
            </ol>
        </SettingsCard>
    );
}

function DnsRecords({ settings }: { settings: Settings }) {
    if (settings.records.length === 0) {
        return (
            <p className="type-small text-muted-foreground">
                The main server hasn’t reported a public address yet.
            </p>
        );
    }

    return (
        <div className="flex flex-col gap-2 rounded-lg bg-recessed p-3">
            <p className="type-small text-muted-foreground">
                Create{' '}
                {settings.records.length === 1
                    ? 'this record'
                    : 'these records'}{' '}
                at your DNS provider. They point at the main server, which
                serves the dashboard.
            </p>
            <p className="type-small text-muted-foreground">
                Use A records here, not a CNAME to your platform domain: that
                name can lead to other edge servers, and only the main server
                serves the dashboard.
            </p>
            {settings.records.map((record) => (
                <div
                    key={`${record.type}-${record.value}`}
                    className="flex flex-col gap-1.5 sm:flex-row sm:items-center sm:gap-3"
                >
                    <span className="inline-flex h-6 w-14 shrink-0 items-center justify-center rounded border bg-card type-mono-small">
                        {record.type}
                    </span>
                    <CopyValue
                        value={record.name}
                        label="record name"
                        className="flex-1 bg-card"
                    />
                    <CopyValue
                        value={record.value}
                        label="record value"
                        className="flex-1 bg-card"
                    />
                </div>
            ))}
        </div>
    );
}

function PlanRow({
    number,
    title,
    children,
}: {
    number: number;
    title: string;
    children: ReactNode;
}) {
    return (
        <li className="flex gap-3">
            <span className="flex size-5 shrink-0 items-center justify-center rounded-full border type-mono-small text-muted-foreground">
                {number}
            </span>
            <div className="flex flex-col gap-0.5">
                <p className="type-body font-medium">{title}</p>
                <p className="type-small text-muted-foreground">{children}</p>
            </div>
        </li>
    );
}

function SwitchPlan({
    settings,
    change,
}: {
    settings: Settings;
    change: Change;
}) {
    const { impact } = change;
    const apps = settings.githubApps.length;

    return (
        <div className="flex flex-col gap-5">
            <ol className="flex flex-col gap-4 rounded-lg bg-recessed p-4">
                <PlanRow number={1} title="You sign in again">
                    Sign-ins are tied to one address, so everyone signs in again
                    at {change.hostname}.
                </PlanRow>
                <PlanRow
                    number={2}
                    title={
                        impact.keepsPasskeys || impact.passkeysAffected === 0
                            ? 'Passkeys keep working'
                            : `${impact.passkeysAffected} ${impact.passkeysAffected === 1 ? 'passkey stops' : 'passkeys stop'} working`
                    }
                >
                    {impact.keepsPasskeys || impact.passkeysAffected === 0
                        ? 'Nothing to do.'
                        : `Passkeys are tied to ${settings.current.host}. Sign in with your password, then add a new passkey under Settings → Security${impact.yourPasskeysAffected > 0 ? ` (${impact.yourPasskeysAffected} of them are yours)` : ''}.`}
                </PlanRow>
                <PlanRow number={3} title="The old address forwards here">
                    Browsers are redirected to the new address. Servers switch
                    on their own and keep the old address as a fallback.
                </PlanRow>
                {apps > 0 && (
                    <PlanRow
                        number={4}
                        title={`Update ${apps === 1 ? 'your GitHub App' : `${apps} GitHub Apps`}`}
                    >
                        Webhooks keep arriving at the old address for{' '}
                        {settings.transitionDays} days. Set the new URLs on
                        GitHub before then.
                    </PlanRow>
                )}
            </ol>
            <ConfirmSwitch hostname={change.hostname} />
        </div>
    );
}

function ConfirmSwitch({ hostname }: { hostname: string }) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button className="self-start">Switch to {hostname}</Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Switch to {hostname}?</DialogTitle>
                <DialogDescription>
                    You’ll continue at https://{hostname} and sign in there. You
                    can switch back at any time.
                </DialogDescription>
                <Form {...activate.form()}>
                    {({ processing }) => (
                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button variant="ghost" type="button">
                                    Cancel
                                </Button>
                            </DialogClose>
                            <Button type="submit" disabled={processing}>
                                {processing && <Spinner />}
                                Switch
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function CancelChange({ hostname }: { hostname: string }) {
    const [processing, setProcessing] = useState(false);

    return (
        <Button
            variant="ghost"
            size="sm"
            disabled={processing}
            onClick={() =>
                router.delete(destroy.url(), {
                    preserveScroll: true,
                    onStart: () => setProcessing(true),
                    onFinish: () => setProcessing(false),
                })
            }
            aria-label={`Stop setting up ${hostname}`}
        >
            <Minus />
            Cancel
        </Button>
    );
}

function SwitchBack({ target }: { target: string }) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm" className="shrink-0">
                    <RotateCcw />
                    Switch back
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Switch back to {target}?</DialogTitle>
                <DialogDescription>
                    The dashboard moves back to {target} and everyone signs in
                    there again. This domain keeps forwarding to it.
                </DialogDescription>
                <Form {...revert.form()}>
                    {({ processing }) => (
                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button variant="ghost" type="button">
                                    Cancel
                                </Button>
                            </DialogClose>
                            <Button type="submit" disabled={processing}>
                                {processing && <Spinner />}
                                Switch back
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function EarlierAddresses({ settings }: { settings: Settings }) {
    return (
        <SettingsCard
            title="Earlier addresses"
            description="They forward browsers here and keep your servers connected."
        >
            {settings.legacy.map((legacy) => (
                <SettingsRow
                    key={legacy.url}
                    label={
                        <span className="type-mono break-all">
                            {legacy.host}
                        </span>
                    }
                    description={
                        legacy.inTransition
                            ? `The API, CLI, GitHub webhooks and the installer also work here until ${formatDate(legacy.until)}.`
                            : 'Only servers and preview sign-ins still use it.'
                    }
                    align="start"
                >
                    <StatusPill state="idle" label="Forwarding" />
                </SettingsRow>
            ))}
            {settings.meshLoginUrl !== null && (
                <SettingsRow
                    label="Private network"
                    description={
                        <>
                            Servers keep joining through{' '}
                            <span className="type-mono-small">
                                {settings.meshLoginUrl}
                            </span>
                            , because their network clients remember this
                            address.
                        </>
                    }
                />
            )}
        </SettingsCard>
    );
}

function ServerAddresses({ settings }: { settings: Settings }) {
    const moved = settings.servers.filter((server) => server.current).length;

    return (
        <SettingsCard
            title="Servers"
            description={`${moved} of ${settings.servers.length} reach the dashboard at its new address.`}
        >
            {settings.servers.map((server) => {
                const mapped =
                    serverStates[server.state] ?? serverStates.unknown;

                return (
                    <SettingsRow
                        key={server.id}
                        label={server.name}
                        description={
                            server.message ??
                            (server.url ? (
                                <span className="type-mono-small break-all">
                                    {server.url}
                                </span>
                            ) : (
                                'Updates by itself after its next report.'
                            ))
                        }
                        inline
                    >
                        <StatusPill state={mapped.state} label={mapped.label} />
                    </SettingsRow>
                );
            })}
        </SettingsCard>
    );
}

function GitHubApps({ settings }: { settings: Settings }) {
    const urls = settings.githubUrls;

    return (
        <SettingsCard
            title="GitHub Apps"
            description="GitHub doesn’t pick up the new address automatically. Set these URLs in each app’s settings."
            footer={
                <>
                    Or create a new app from Settings → Git, which uses the new
                    address from the start.
                </>
            }
        >
            {settings.githubApps.map((app) => (
                <SettingsRow
                    key={app.appUrl}
                    label={app.name}
                    description={app.team}
                    inline
                >
                    <Button asChild variant="outline" size="sm">
                        <a href={app.appUrl} target="_blank" rel="noreferrer">
                            Open on GitHub
                        </a>
                    </Button>
                </SettingsRow>
            ))}
            <SettingsBody className="grid gap-3">
                <UrlField label="Homepage URL" value={urls.homepage} />
                <UrlField label="Callback URL" value={urls.callback} />
                <UrlField label="Setup URL" value={urls.setup} />
                <UrlField label="Webhook URL" value={urls.webhook} />
            </SettingsBody>
        </SettingsCard>
    );
}

function UrlField({ label, value }: { label: string; value: string }) {
    return (
        <div className="grid gap-1.5 sm:grid-cols-[9rem_1fr] sm:items-center">
            <span className="type-small text-muted-foreground">{label}</span>
            <CopyValue value={value} label={label} />
        </div>
    );
}
