import { router, useForm } from '@inertiajs/react';
import {
    ArrowRight,
    ArrowUpRight,
    Check,
    CornerDownRight,
    Globe,
    Lock,
    MoreHorizontal,
    Plus,
    RefreshCw,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import InputError from '@/components/input-error';
import { ConfirmPopover } from '@/components/patterns/confirm-popover';
import { EmptyState } from '@/components/patterns/empty-state';
import { LiveWait } from '@/components/patterns/live-wait';
import { DeviceImage } from '@/components/devices';
import type { StatusState } from '@/components/status';
import { StatusIcon, StatusPill } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { deviceKind, deviceStatus } from '@/features/servers/format';
import { ServerStatus } from '@/features/servers/server-status';
import { cn } from '@/lib/utils';
import domainRoutes from '@/routes/projects/domains';
import { CopyButton } from './general-section';
import { SettingsPage, useSettingsPage } from './section';
import type { SettingsContext } from './settings-context';

type Domain = App.Data.Canvas.DomainData;
type Step = { state: StatusState; title: string; detail?: ReactNode };

const AUTO_CHECK_MS = 20_000;
const AUTO_CHECKS = 15;

function dnsStep(domain: Domain): Step {
    if (domain.kind !== 'custom') {
        return {
            state: 'active',
            title: 'Managed for you',
            detail: 'launchroom points this name at your servers.',
        };
    }

    const check = domain.verification;

    if (!check) {
        return { state: 'idle', title: 'Not checked yet' };
    }

    const when = (
        <>
            Checked <TimeAgo value={check.checkedAt} />
        </>
    );

    switch (check.status) {
        case 'ok':
            return {
                state: 'active',
                title: check.proxy
                    ? `Reached through a proxy (${check.proxy})`
                    : check.reachedOverHttp
                      ? 'Reaches your servers'
                      : 'Points at your servers',
                detail: check.proxy
                    ? `${check.proxy} forwards to your server. Use SSL/TLS Full (strict).`
                    : when,
            };
        case 'proxied':
            return {
                state: 'warning',
                title: `Behind ${check.proxy ?? 'a proxy'}, not confirmed`,
                detail: 'Couldn’t confirm the proxy forwards to your servers.',
            };
        case 'mismatch':
            return {
                state: 'failed',
                title: 'Points elsewhere',
                detail:
                    check.addresses.length > 0
                        ? `Now ${check.addresses.slice(0, 2).join(', ')}`
                        : when,
            };
        case 'missing':
            return { state: 'warning', title: 'No record yet', detail: when };
        case 'unknown':
            return {
                state: 'warning',
                title: 'Can’t compare yet',
                detail: 'No server has a public address.',
            };
        default:
            return {
                state: 'warning',
                title: 'Check didn’t answer',
                detail: when,
            };
    }
}

function certificateStep(domain: Domain, dnsOk: boolean): Step {
    const cert = domain.certificate;

    if (!cert) {
        if (domain.hostname.endsWith('.localhost')) {
            return {
                state: 'active',
                title: 'Local certificate',
                detail: 'Trusted on this machine',
            };
        }

        return {
            state: 'idle',
            title: 'Issued on the first visit',
            detail: dnsOk
                ? 'Open the domain once to start it'
                : 'Once DNS points here',
        };
    }

    if (cert.status === 'failed') {
        return {
            state: 'failed',
            title: 'Couldn’t be issued',
            detail: cert.error ? (
                <span title={cert.error}>{cert.error}</span>
            ) : undefined,
        };
    }

    if (cert.status === 'pending') {
        return {
            state: dnsOk ? 'progress' : 'idle',
            title: 'Being issued',
            detail: cert.error
                ? `Last try: ${cert.error}`
                : 'Usually takes under a minute',
        };
    }

    if (cert.warning === 'expired' && cert.expiresAt) {
        return {
            state: 'failed',
            title: 'Expired',
            detail: (
                <>
                    Expired <TimeAgo value={cert.expiresAt} />
                </>
            ),
        };
    }

    if (cert.warning === 'expiring' && cert.expiresAt) {
        return {
            state: 'warning',
            title: 'Renewal overdue',
            detail: (
                <>
                    Expires <TimeAgo value={cert.expiresAt} />
                </>
            ),
        };
    }

    return {
        state: 'active',
        title: cert.wildcard ? 'Wildcard certificate' : 'Certificate issued',
        detail: [
            cert.issuer ? shortIssuer(cert.issuer) : null,
            cert.expiresAt
                ? `renews before ${new Date(cert.expiresAt).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}`
                : null,
        ]
            .filter(Boolean)
            .join(' · '),
    };
}

/** "Let's Encrypt", "Caddy Local Authority" without the intermediate name. */
function shortIssuer(issuer: string): string {
    return issuer
        .replace(/\s*-\s*(ECC|RSA)?\s*Intermediate.*$/i, '')
        .replace(/^.*?(Let's Encrypt|ZeroSSL).*$/i, '$1');
}

function overall(
    domain: Domain,
    dns: Step,
    cert: Step,
): { state: StatusState; label: string } {
    if (domain.staged) {
        return { state: 'staged', label: 'With the next deploy' };
    }

    if (dns.state === 'failed' || cert.state === 'failed') {
        return { state: 'failed', label: 'Needs attention' };
    }

    if (dns.state !== 'active') {
        return { state: 'warning', label: 'Waiting for DNS' };
    }

    if (cert.state === 'warning') {
        return { state: 'warning', label: 'Renewal overdue' };
    }

    if (cert.state !== 'active') {
        return { state: cert.state, label: cert.title };
    }

    return { state: 'active', label: 'Live' };
}

/**
 * Where a request goes: DNS → HTTPS → the edge servers. One step per
 * column with an icon, the status word and one quiet line.
 */
function DomainPath({
    steps,
}: {
    steps: { label: string; step: Step; media?: ReactNode }[];
}) {
    return (
        <ol className="grid divide-y border-t @xl/settings:grid-cols-3 @xl/settings:divide-x @xl/settings:divide-y-0">
            {steps.map(({ label, step, media }, index) => (
                <li
                    key={label}
                    data-setting={
                        label === 'HTTPS' ? 'domain-https' : undefined
                    }
                    className="relative grid min-w-0 grid-cols-[4.5rem_minmax(0,1fr)] items-center gap-x-3 gap-y-1 px-5 py-3 @xl/settings:flex @xl/settings:flex-col @xl/settings:items-stretch @xl/settings:gap-1.5 @xl/settings:py-4"
                >
                    <span className="type-small text-muted-foreground">
                        {label}
                    </span>
                    <span className="flex min-w-0 items-center gap-2 type-body font-medium">
                        <StatusIcon state={step.state} />
                        <span className="truncate">{step.title}</span>
                    </span>
                    {media}
                    {step.detail && (
                        <span className="col-start-2 truncate type-small text-muted-foreground">
                            {step.detail}
                        </span>
                    )}
                    {index < steps.length - 1 && (
                        <ArrowRight
                            aria-hidden="true"
                            className="absolute top-1/2 -right-2 z-10 hidden size-4 -translate-y-1/2 rounded-full bg-card text-faint @xl/settings:block"
                        />
                    )}
                </li>
            ))}
        </ol>
    );
}

function edgeStep({
    edges,
}: {
    edges: App.Data.Canvas.EdgeServerData[];
}): Step {
    return edges.length === 0
        ? {
              state: 'warning',
              title: 'No server routes traffic',
              detail: 'Give a server the edge role.',
          }
        : {
              state: edges.some((edge) => edge.status === 'online')
                  ? 'active'
                  : 'failed',
              title:
                  edges.length === 1
                      ? `Served by ${edges[0].name}`
                      : `Served by ${edges.length} servers`,
              detail:
                  edges.length > 1
                      ? edges.map((edge) => edge.name).join(', ')
                      : undefined,
          };
}

/**
 * The servers with the edge role, under the domain cards: a title and one
 * muted sentence, then one tile per server (device render with its LED in
 * a recessed 16:10 well, name, status). Wraps to as many columns as fit.
 */
function EdgeServers({ edges }: { edges: App.Data.Canvas.EdgeServerData[] }) {
    return (
        <section
            aria-labelledby="domain-edges-title"
            data-setting="domain-edges"
            className="flex flex-col gap-3 rounded-xl"
        >
            <div className="flex flex-col gap-0.5">
                <h4 id="domain-edges-title" className="type-label">
                    {edges.length === 1 ? 'Edge server' : 'Edge servers'}
                </h4>
                <p className="type-small text-muted-foreground">
                    {edges.length === 1
                        ? 'Answers for every domain here. A second server with the edge role keeps traffic flowing if it’s down.'
                        : `These ${edges.length} answer for every domain here, so traffic keeps flowing if one is down.`}
                </p>
            </div>
            <ul className="grid grid-cols-1 gap-3 @md/page:grid-cols-2 @2xl/page:grid-cols-3">
                {edges.map((edge) => (
                    <li
                        key={edge.id}
                        className="flex min-w-0 items-center gap-3 rounded-xl border bg-card p-2.5 pr-4"
                    >
                        <span className="flex w-20 shrink-0 items-end justify-center overflow-hidden rounded-lg bg-recessed">
                            <DeviceImage
                                device={deviceKind(edge)}
                                status={deviceStatus(edge.status)}
                                size={80}
                            />
                        </span>
                        <span className="flex min-w-0 flex-col gap-0.5">
                            <span className="truncate type-body font-medium">
                                {edge.name}
                            </span>
                            <ServerStatus
                                status={edge.status}
                                className="type-small"
                            />
                        </span>
                    </li>
                ))}
            </ul>
        </section>
    );
}

/** The records to create, each value copyable. */
export function DnsRecords({ domain }: { domain: Domain }) {
    const records = domain.wwwRedirect
        ? [
              ...domain.dnsRecords,
              ...domain.dnsRecords.map((record) => ({
                  ...record,
                  name: `www.${record.name}`,
              })),
          ]
        : domain.dnsRecords;

    if (records.length === 0) {
        return (
            <p className="type-small text-muted-foreground">
                No server with the edge role has a public address yet. Once one
                does, its address shows up here.
            </p>
        );
    }

    return (
        <div className="flex flex-col gap-3">
            <ul className="divide-y overflow-hidden rounded-lg border bg-card">
                {records.map((record) => (
                    <li
                        key={`${record.type}-${record.name}-${record.value}`}
                        className="grid min-w-0 grid-cols-[3.5rem_minmax(0,1fr)] items-start gap-x-3 gap-y-2 p-4"
                    >
                        <span className="inline-flex h-6 w-fit items-center rounded-md bg-hover px-2 type-mono-small font-medium">
                            {record.type}
                        </span>
                        <span className="flex min-w-0 items-start">
                            <span className="flex min-w-0 flex-1 flex-col gap-1">
                                <span className="type-small text-muted-foreground">
                                    Name
                                </span>
                                <code className="type-mono-small break-all">
                                    {record.name}
                                </code>
                            </span>
                            <CopyButton
                                value={record.name}
                                label={`${record.type} record name`}
                            />
                        </span>
                        <span className="col-start-2 flex min-w-0 items-start">
                            <span className="flex min-w-0 flex-1 flex-col gap-1">
                                <span className="type-small text-muted-foreground">
                                    Value
                                </span>
                                <code className="type-mono-small break-all">
                                    {record.value}
                                </code>
                            </span>
                            <CopyButton
                                value={record.value}
                                label={`${record.type} record value`}
                            />
                        </span>
                    </li>
                ))}
            </ul>
            {domain.cnameTarget && (
                <p className="flex flex-wrap items-center gap-x-1 type-small text-muted-foreground">
                    Or one{' '}
                    <code className="type-mono-small text-foreground">
                        CNAME
                    </code>{' '}
                    to
                    <code className="min-w-0 type-mono-small break-all text-foreground">
                        {domain.cnameTarget}
                    </code>
                    <CopyButton
                        value={domain.cnameTarget}
                        label="CNAME target"
                    />
                </p>
            )}
        </div>
    );
}

/**
 * Checks a custom domain's DNS by itself while its setup is on screen
 * (quiet: no toast per check), every 20 s for about five minutes.
 */
function useAutoCheck(domain: Domain, route: string[], enabled: boolean) {
    const [checking, setChecking] = useState(false);
    const count = useRef(0);
    const { active } = useSettingsPage();
    const on =
        enabled &&
        active &&
        domain.kind === 'custom' &&
        domain.verification?.status !== 'ok';
    const [project, environment, service] = route;

    const check = (quiet: boolean) => {
        setChecking(true);
        router.post(
            domainRoutes.verify(
                { project, environment, service, domain: domain.id },
                quiet ? { query: { quiet: 1 } } : undefined,
            ).url,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                only: ['panel', 'flash'],
                onFinish: () => setChecking(false),
            },
        );
    };

    useEffect(() => {
        if (!on) {
            return;
        }

        const interval = window.setInterval(() => {
            if (
                document.visibilityState !== 'visible' ||
                count.current >= AUTO_CHECKS
            ) {
                return;
            }

            count.current++;
            check(true);
        }, AUTO_CHECK_MS);

        return () => window.clearInterval(interval);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [on, domain.id]);

    return {
        checking,
        check: () => check(false),
        live: on && count.current < AUTO_CHECKS,
    };
}

function DomainCard({
    domain,
    context,
}: {
    domain: Domain;
    context: SettingsContext;
}) {
    const [busy, setBusy] = useState<'remove' | 'www' | 'hsts' | null>(null);
    const custom = domain.kind === 'custom';
    const editable = custom && context.canUpdate;
    const route = {
        project: context.route[0],
        environment: context.route[1],
        service: context.route[2],
        domain: domain.id,
    };
    const dns = dnsStep(domain);
    const cert = certificateStep(domain, dns.state === 'active');
    const edges = context.settings.edges;
    const edge = edgeStep({ edges });
    const status = overall(domain, dns, cert);
    const needsDns = custom && domain.verification?.status !== 'ok';
    const auto = useAutoCheck(domain, context.route, editable);
    const options = {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => setBusy(null),
    };

    return (
        <li className="overflow-hidden rounded-xl border bg-card shadow-card">
            <div className="flex items-start gap-3 px-5 py-4">
                <span
                    aria-hidden="true"
                    className="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-lg border bg-recessed text-muted-foreground"
                >
                    {cert.state === 'active' ? (
                        <Lock className="size-4" />
                    ) : (
                        <Globe className="size-4" />
                    )}
                </span>
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                    <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                        <a
                            href={`https://${domain.hostname}`}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex min-w-0 items-center gap-1 type-mono text-[15px] font-medium [overflow-wrap:anywhere] hover:underline"
                        >
                            {domain.hostname}
                            <ArrowUpRight
                                aria-hidden="true"
                                className="size-3.5 shrink-0 text-muted-foreground"
                            />
                        </a>
                        <CopyButton
                            value={domain.hostname}
                            label={domain.hostname}
                        />
                    </div>
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1 type-small text-muted-foreground">
                        <StatusPill
                            state={status.state}
                            label={status.label}
                            className="@xl/settings:hidden"
                        />
                        <span>
                            {custom
                                ? 'Custom domain'
                                : 'Generated by launchroom'}
                        </span>
                        {domain.wwwRedirect && (
                            <span className="inline-flex items-center gap-1">
                                <CornerDownRight
                                    aria-hidden="true"
                                    className="size-3.5"
                                />
                                <code className="type-mono-small">
                                    www.{domain.hostname}
                                </code>{' '}
                                redirects here
                            </span>
                        )}
                    </div>
                </div>
                <div className="flex shrink-0 items-center gap-1">
                    <StatusPill
                        state={status.state}
                        label={status.label}
                        className="hidden @xl/settings:inline-flex"
                    />
                    {editable && (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    aria-label={`Options for ${domain.hostname}`}
                                >
                                    <MoreHorizontal />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem onSelect={() => auto.check()}>
                                    <RefreshCw /> Check DNS now
                                </DropdownMenuItem>
                                <DropdownMenuItem asChild>
                                    <a
                                        href={`https://${domain.hostname}`}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        <ArrowUpRight /> Open
                                    </a>
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}
                </div>
            </div>

            <DomainPath
                steps={[
                    { label: 'DNS', step: dns },
                    { label: 'HTTPS', step: cert },
                    { label: 'Traffic', step: edge },
                ]}
            />

            {needsDns && (
                <div className="flex flex-col gap-4 border-t bg-recessed px-5 py-4">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex flex-col gap-0.5">
                            <span className="type-body font-medium">
                                Point your DNS here
                            </span>
                            <span className="type-small text-muted-foreground">
                                Add{' '}
                                {domain.dnsRecords.length === 1
                                    ? 'this record'
                                    : 'these records'}{' '}
                                at your DNS provider.
                            </span>
                        </div>
                        {editable && (
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={auto.checking}
                                onClick={auto.check}
                            >
                                <RefreshCw
                                    className={cn(
                                        auto.checking &&
                                            'motion-safe:animate-spin',
                                    )}
                                />
                                Check now
                            </Button>
                        )}
                    </div>
                    <DnsRecords domain={domain} />
                    {auto.live && (
                        <p
                            className="flex items-center gap-2 type-small text-muted-foreground"
                            aria-live="polite"
                        >
                            <StatusIcon state="progress" />
                            Checking by itself every 20 seconds
                            {domain.verification && (
                                <>
                                    {' '}
                                    · last{' '}
                                    <TimeAgo
                                        value={domain.verification.checkedAt}
                                    />
                                </>
                            )}
                        </p>
                    )}
                </div>
            )}

            {(domain.canRedirectWww ||
                domain.canSetHsts ||
                (editable && domain.canDelete)) && (
                <div className="flex flex-col divide-y border-t">
                    {domain.canRedirectWww && (
                        <ToggleRow
                            setting="domain-www"
                            label={
                                <>
                                    Redirect{' '}
                                    <code className="type-mono-small">
                                        www.{domain.hostname}
                                    </code>{' '}
                                    here
                                </>
                            }
                            checked={domain.wwwRedirect}
                            disabled={!editable || busy !== null}
                            aria={`Redirect www.${domain.hostname} to ${domain.hostname}`}
                            onChange={(wwwRedirect) => {
                                setBusy('www');
                                router.patch(
                                    domainRoutes.update(route).url,
                                    { wwwRedirect },
                                    options,
                                );
                            }}
                        />
                    )}
                    {domain.canSetHsts && (
                        <ToggleRow
                            setting="domain-hsts"
                            label="Always HTTPS (HSTS)"
                            description="Browsers remember to use HTTPS once the certificate is issued."
                            checked={domain.hsts}
                            disabled={!editable || busy !== null}
                            aria={`HSTS for ${domain.hostname}`}
                            onChange={(hsts) => {
                                setBusy('hsts');
                                router.patch(
                                    domainRoutes.update(route).url,
                                    { hsts },
                                    options,
                                );
                            }}
                        />
                    )}
                    {editable && domain.canDelete && (
                        <div className="flex items-center justify-between gap-4 px-5 py-3">
                            <span className="type-small text-muted-foreground">
                                Routing stops right away when you remove it.
                            </span>
                            <ConfirmPopover
                                tone="destructive"
                                title={`Remove ${domain.hostname}?`}
                                consequence="Visitors get an error until you add it again. Nothing to deploy."
                                confirmLabel="Remove domain"
                                onConfirm={() => {
                                    setBusy('remove');
                                    router.delete(
                                        domainRoutes.destroy(route).url,
                                        options,
                                    );
                                }}
                            >
                                <Button
                                    variant="destructive"
                                    size="sm"
                                    disabled={busy !== null}
                                >
                                    Remove
                                </Button>
                            </ConfirmPopover>
                        </div>
                    )}
                </div>
            )}
        </li>
    );
}

function ToggleRow({
    label,
    description,
    checked,
    disabled,
    aria,
    onChange,
    setting,
}: {
    label: ReactNode;
    description?: string;
    checked: boolean;
    disabled: boolean;
    aria: string;
    onChange: (value: boolean) => void;
    setting?: string;
}) {
    return (
        <div
            data-setting={setting}
            className="flex items-center justify-between gap-4 px-5 py-3"
        >
            <span className="flex min-w-0 flex-col">
                <span className="type-body">{label}</span>
                {description && (
                    <span className="type-small text-muted-foreground">
                        {description}
                    </span>
                )}
            </span>
            <Switch
                checked={checked}
                disabled={disabled}
                aria-label={aria}
                onCheckedChange={onChange}
            />
        </div>
    );
}

/** "shop.example.com" is a subdomain: a CNAME works; an apex needs A records. */
function hostnameHint(
    hostname: string,
    appsDomain: string | null,
): string | null {
    const value = hostname.trim().toLowerCase();

    if (!/^[a-z0-9-]+(\.[a-z0-9-]+)+$/.test(value)) {
        return null;
    }

    // Names under the platform domain are generated by launchroom; adding
    // one is refused (AddDomain), so say so before the request does.
    if (
        appsDomain &&
        (value === appsDomain.toLowerCase() ||
            value.endsWith(`.${appsDomain.toLowerCase()}`))
    ) {
        return `Addresses under ${appsDomain} are generated by launchroom. Use a domain of your own.`;
    }

    return value.split('.').length >= 3
        ? 'A subdomain: one A record (or a CNAME) at your DNS provider is enough.'
        : 'A root domain: you’ll add an A record at your DNS provider.';
}

/**
 * The guided add-domain flow: the name (with a hint of what DNS it will
 * need), then the records to copy with a live check until it points here.
 * Adding applies right away: no deploy.
 */
function AddDomainDialog({
    context,
    open,
    onOpenChange,
}: {
    context: SettingsContext;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm({ hostname: '' });
    const [added, setAdded] = useState<string | null>(null);
    const domain = added
        ? context.settings.domains.find((item) => item.hostname === added)
        : undefined;
    const hint = hostnameHint(form.data.hostname, context.settings.appsDomain);
    const auto = useAutoCheckFor(domain, context, open && added !== null);
    const ok = domain?.verification?.status === 'ok';

    useEffect(() => {
        if (!open) {
            setAdded(null);
            form.reset();
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const hostname = form.data.hostname.trim().toLowerCase();
        form.post(domainRoutes.store(context.route).url, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => setAdded(hostname),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85dvh] gap-6 overflow-y-auto sm:max-w-lg">
                {added === null ? (
                    <form onSubmit={submit} className="flex flex-col gap-6">
                        <div className="flex flex-col gap-1.5">
                            <DialogTitle>Add a domain</DialogTitle>
                            <DialogDescription>
                                Routing starts as soon as DNS points at your
                                servers. Nothing to deploy.
                            </DialogDescription>
                        </div>
                        <div className="flex flex-col gap-2">
                            <label
                                htmlFor="domain-hostname"
                                className="type-label"
                            >
                                Domain
                            </label>
                            <Input
                                id="domain-hostname"
                                autoFocus
                                value={form.data.hostname}
                                onChange={(event) =>
                                    form.setData('hostname', event.target.value)
                                }
                                placeholder="shop.example.com"
                                autoComplete="off"
                                spellCheck={false}
                                className="h-11 font-mono text-[15px]"
                            />
                            <InputError message={form.errors.hostname} />
                            <p
                                className="min-h-5 type-small text-muted-foreground"
                                aria-live="polite"
                            >
                                {hint}
                            </p>
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => onOpenChange(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={
                                    form.processing ||
                                    form.data.hostname.trim() === ''
                                }
                            >
                                Add domain
                            </Button>
                        </div>
                    </form>
                ) : (
                    <div className="flex flex-col gap-6">
                        <div className="flex flex-col gap-1.5">
                            <DialogTitle>
                                {ok ? 'You’re all set' : 'Point your DNS here'}
                            </DialogTitle>
                            <DialogDescription>
                                {ok ? (
                                    <>
                                        <code className="type-mono-small break-all text-foreground">
                                            {added}
                                        </code>{' '}
                                        points at your servers. HTTPS starts on
                                        the first visit.
                                    </>
                                ) : (
                                    <>
                                        Add{' '}
                                        {domain &&
                                        domain.dnsRecords.length === 1
                                            ? 'this record'
                                            : 'these records'}{' '}
                                        for{' '}
                                        <code className="type-mono-small break-all text-foreground">
                                            {added}
                                        </code>{' '}
                                        at your DNS provider.
                                    </>
                                )}
                            </DialogDescription>
                        </div>
                        {ok ? (
                            <div className="flex items-center gap-3 rounded-xl border bg-status-ok-soft/40 px-4 py-3">
                                <span className="flex size-8 items-center justify-center rounded-full bg-status-ok text-background">
                                    <Check
                                        className="size-4"
                                        strokeWidth={3}
                                        aria-hidden="true"
                                    />
                                </span>
                                <span className="type-body">DNS verified</span>
                            </div>
                        ) : (
                            <>
                                {domain && <DnsRecords domain={domain} />}
                                <LiveWait
                                    status="Waiting for DNS to point here"
                                    hint="We check every 20 seconds. Changes often show up within minutes."
                                    timeoutSeconds={300}
                                    fallback={
                                        <p className="type-small text-muted-foreground">
                                            Still nothing? Some providers take
                                            up to an hour. You can close this;
                                            the domain card keeps checking.
                                        </p>
                                    }
                                />
                            </>
                        )}
                        <div className="flex justify-end gap-2">
                            {!ok && domain && (
                                <Button
                                    variant="outline"
                                    disabled={auto.checking}
                                    onClick={auto.check}
                                >
                                    <RefreshCw
                                        className={cn(
                                            auto.checking &&
                                                'motion-safe:animate-spin',
                                        )}
                                    />
                                    Check now
                                </Button>
                            )}
                            <Button onClick={() => onOpenChange(false)}>
                                {ok ? 'Done' : 'Close'}
                            </Button>
                        </div>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}

function useAutoCheckFor(
    domain: Domain | undefined,
    context: SettingsContext,
    enabled: boolean,
) {
    const [checking, setChecking] = useState(false);
    const [project, environment, service] = context.route;
    const id = domain?.id;
    const done = domain?.verification?.status === 'ok';

    const check = (quiet: boolean) => {
        if (!id) {
            return;
        }

        setChecking(true);
        router.post(
            domainRoutes.verify(
                { project, environment, service, domain: id },
                quiet ? { query: { quiet: 1 } } : undefined,
            ).url,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                only: ['panel', 'flash'],
                onFinish: () => setChecking(false),
            },
        );
    };

    useEffect(() => {
        if (!enabled || !id || done) {
            return;
        }

        const interval = window.setInterval(() => {
            if (document.visibilityState === 'visible') {
                check(true);
            }
        }, AUTO_CHECK_MS);

        return () => window.clearInterval(interval);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [enabled, id, done]);

    return { checking, check: () => check(true) };
}

/**
 * Domains (flows §9, docs/08 §16): one card per domain with where a
 * request goes (DNS → HTTPS → the servers that serve it), the records to
 * copy while DNS isn't there yet (checked by itself), the www redirect and
 * HSTS switches. Adding, removing and the switches apply right away: the
 * edges start or stop routing without a deploy (ApplyDomainRoutes).
 */
export function DomainsSection({ context }: { context: SettingsContext }) {
    const { settings, canUpdate } = context;
    const { active } = useSettingsPage();
    const waitingForCertificate = settings.domains.some(
        (domain) =>
            !domain.staged &&
            !domain.hostname.endsWith('.localhost') &&
            (domain.certificate === null ||
                domain.certificate.status === 'pending'),
    );

    useEffect(() => {
        if (!active || !waitingForCertificate) return;
        const timer = window.setInterval(() => {
            if (document.visibilityState === 'visible') {
                router.reload({ only: ['panel'] });
            }
        }, 5000);
        return () => window.clearInterval(timer);
    }, [active, waitingForCertificate]);
    const [adding, setAdding] = useState(false);
    const port = settings.deploy.port ?? settings.detected?.port ?? null;
    const domains = settings.domains.filter(
        // www redirects show on their target.
        (domain) =>
            !(
                domain.redirectTo &&
                domain.hostname === `www.${domain.redirectTo}`
            ),
    );
    const live = domains.filter((domain) => {
        const dns = dnsStep(domain);

        return (
            overall(
                domain,
                dns,
                certificateStep(domain, dns.state === 'active'),
            ).state === 'active'
        );
    }).length;

    return (
        <SettingsPage
            title="Domains"
            description={
                domains.length === 0
                    ? 'Where people reach this service. Changes apply right away.'
                    : `${live} of ${domains.length} live · changes apply right away, certificates follow by themselves.`
            }
            actions={
                canUpdate && (
                    <Button
                        data-setting="add-domain"
                        onClick={() => setAdding(true)}
                    >
                        <Plus />
                        Add domain
                    </Button>
                )
            }
        >
            {domains.length === 0 ? (
                <EmptyState
                    icon={Globe}
                    title="No domains yet"
                    action={
                        canUpdate && (
                            <Button
                                variant="outline"
                                onClick={() => setAdding(true)}
                            >
                                <Plus /> Add domain
                            </Button>
                        )
                    }
                >
                    {port === null
                        ? 'A generated domain appears once the port is known: deploy once, or set it under Deploy.'
                        : 'Add your own domain; launchroom handles HTTPS.'}
                </EmptyState>
            ) : (
                <ul data-setting="domains-list" className="flex flex-col gap-4">
                    {domains.map((domain) => (
                        <DomainCard
                            key={domain.id}
                            domain={domain}
                            context={context}
                        />
                    ))}
                </ul>
            )}
            {settings.edges.length > 0 && (
                <EdgeServers edges={settings.edges} />
            )}
            <AddDomainDialog
                context={context}
                open={adding}
                onOpenChange={setAdding}
            />
        </SettingsPage>
    );
}
