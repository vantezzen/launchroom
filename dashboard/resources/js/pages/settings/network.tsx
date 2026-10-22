import { edit as teamSettings } from '@/routes/team';
import { AppDomainsSettings } from '@/features/domains/app-domains-settings';
import { Form, Head, Link, router } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { useState } from 'react';
import { ConfirmAction } from '@/components/confirm-action';
import InputError from '@/components/input-error';
import { Logo } from '@/components/logo';
import { ChoiceTiles } from '@/components/patterns/choice-tiles';
import { OptionCards } from '@/components/patterns/option-cards';
import { Section } from '@/components/patterns/section';
import { Status, StatusIcon, StatusPill } from '@/components/status';
import type { StatusState } from '@/components/status';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { shortAge } from '@/features/servers/format';
import { MeshStatus, meshModeLabels } from '@/features/servers/mesh-status';
import {
    useDocsUrl,
    LearnMore,
    SettingsBody,
    SettingsCard,
    SettingsRow,
} from '@/features/settings/settings-card';
import { cn } from '@/lib/utils';
import { edit as networkSettings } from '@/routes/network-settings';
import { show as onboarding } from '@/routes/onboarding';
import { show as serverPage } from '@/routes/servers';
import { destroy, update, verify } from '@/routes/servers/ingress/dns';
import { update as updateEdge } from '@/routes/servers/ingress/edges';
import { enable, ownTailscale } from '@/routes/servers/mesh';

type Props = {
    mesh: App.Data.Servers.MeshOverviewData;
    members: App.Data.Settings.NetworkMemberData[];
    recommendedPolicy: string;
    tags: string[];
    ingress: App.Data.Ingress.IngressSettingsData;
    edgeAddresses: string[];
    errors?: Record<string, string>;
};

type Mode = App.Enums.Mesh.MeshMode;

/** Logo keys (components/logo.tsx) of the DNS providers. */
const dnsLogo: Record<App.Enums.DnsProvider, string> = {
    cloudflare: 'cloudflare',
    hetzner: 'hetzner',
    route53: 'route53',
    digitalocean: 'digitalocean',
    porkbun: 'porkbun',
    desec: 'desec',
    gandi: 'gandi',
    ovh: 'ovh',
    netcup: 'netcup',
};

const routeStates: Record<string, { state: StatusState; label: string }> = {
    applied: { state: 'active', label: 'Routes applied' },
    pending: { state: 'progress', label: 'Applying routes' },
    failed: { state: 'failed', label: 'Routes not applied' },
};

const storageStates: Record<string, { state: StatusState; label: string }> = {
    local: { state: 'idle', label: 'Own certificates' },
    shared: { state: 'active', label: 'Shared certificates' },
    degraded: { state: 'warning', label: 'Serving a local copy' },
};

function PrivateNetwork({
    mesh,
    members,
    recommendedPolicy,
    tags,
    errors,
}: Omit<Props, 'ingress' | 'edgeAddresses'>) {
    const [mode, setMode] = useState<Mode>(
        mesh.mode ?? (mesh.builtInAvailable ? 'embedded' : 'byo'),
    );
    const [rulesOpen, setRulesOpen] = useState(false);
    const on = mesh.enabled && mesh.mode !== null;

    return (
        <Section
            id="private-network"
            title="Private network"
            description="Servers reach each other and this dashboard, even behind NAT."
            actions={
                <StatusPill
                    state={on ? 'active' : 'idle'}
                    label={on ? `On · ${meshModeLabels[mesh.mode!]}` : 'Off'}
                />
            }
        >
            {mesh.error !== null && (
                <div className="flex items-start gap-3 rounded-xl border bg-card px-4 py-3">
                    <StatusIcon state="failed" className="mt-1" />
                    <p className="type-body">
                        {mesh.error}
                        {mesh.errorAt !== null && (
                            <span className="text-muted-foreground">
                                {' '}
                                · {shortAge(mesh.errorAt)}
                            </span>
                        )}
                    </p>
                </div>
            )}
            <InputError message={errors?.mesh} />

            <OptionCards<Mode>
                label="How servers connect"
                value={mode}
                onChange={setMode}
                options={[
                    {
                        value: 'embedded',
                        title: 'Built-in',
                        description: mesh.builtInAvailable
                            ? 'Runs on the dashboard’s server and turns on by itself when a second server joins.'
                            : 'Not available on this install.',
                        disabled: !mesh.builtInAvailable,
                    },
                    {
                        value: 'byo',
                        title: 'Your own Tailscale',
                        description:
                            'Servers join a tailnet you run: Tailscale or your own Headscale.',
                    },
                ]}
            />

            {mode === 'embedded' &&
                mesh.builtInAvailable &&
                !(mesh.enabled && mesh.mode === 'embedded') && (
                    <SettingsCard>
                        <SettingsRow
                            label={
                                mesh.enabled && mesh.mode === 'embedded'
                                    ? 'The built-in network is on'
                                    : 'Turn on the built-in network'
                            }
                            description={
                                mesh.enabled && mesh.mode === 'embedded'
                                    ? mesh.enabledAt
                                        ? `Since ${shortAge(mesh.enabledAt)}.`
                                        : undefined
                                    : 'Other servers need to reach this dashboard over HTTPS; UDP 3478 helps them connect directly.'
                            }
                        >
                            {!(mesh.enabled && mesh.mode === 'embedded') && (
                                <ConfirmAction
                                    trigger="Turn on now"
                                    title="Turn on the private network?"
                                    description="Every server joins the built-in network. Servers using your own Tailscale network move over."
                                    confirmLabel="Turn on"
                                    form={enable.form()}
                                />
                            )}
                        </SettingsRow>
                    </SettingsCard>
                )}

            {mode === 'byo' && (
                <SettingsCard
                    footer={
                        <span>
                            The auth key is stored encrypted and only reaches
                            servers an owner added or approved. Use one that
                            expires, limited to the tags{' '}
                            <span className="type-mono-small text-foreground">
                                {tags.join(', ')}
                            </span>
                            .
                        </span>
                    }
                >
                    <SettingsBody>
                        <Form
                            {...ownTailscale.form()}
                            resetOnSuccess={['authKey']}
                            options={{ preserveScroll: true }}
                            className="flex flex-col gap-5"
                        >
                            {({ processing, errors: formErrors }) => (
                                <>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="grid gap-1.5">
                                            <label
                                                htmlFor="loginServer"
                                                className="type-label"
                                            >
                                                Login server
                                            </label>
                                            <Input
                                                id="loginServer"
                                                name="loginServer"
                                                defaultValue={
                                                    mesh.loginServer ??
                                                    'https://controlplane.tailscale.com'
                                                }
                                                autoComplete="off"
                                                maxLength={512}
                                                className="type-mono"
                                            />
                                            <InputError
                                                message={formErrors.loginServer}
                                            />
                                        </div>
                                        <div className="grid gap-1.5">
                                            <label
                                                htmlFor="authKey"
                                                className="type-label"
                                            >
                                                Auth key
                                            </label>
                                            <Input
                                                id="authKey"
                                                name="authKey"
                                                type="password"
                                                autoComplete="off"
                                                maxLength={1024}
                                            />
                                            <InputError
                                                message={formErrors.authKey}
                                            />
                                        </div>
                                    </div>
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <button
                                            type="button"
                                            aria-expanded={rulesOpen}
                                            onClick={() =>
                                                setRulesOpen((v) => !v)
                                            }
                                            className="inline-flex items-center gap-1 rounded-md type-small text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-signal focus-visible:outline-none"
                                        >
                                            <ChevronRight
                                                aria-hidden="true"
                                                className={`size-3.5 transition-transform duration-fast ${rulesOpen ? 'rotate-90' : ''}`}
                                            />
                                            Recommended access rules
                                        </button>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            Use this network
                                        </Button>
                                    </div>
                                    {rulesOpen && (
                                        <pre className="max-h-72 overflow-auto rounded-lg bg-recessed p-3 type-mono-small">
                                            {recommendedPolicy}
                                        </pre>
                                    )}
                                </>
                            )}
                        </Form>
                    </SettingsBody>
                </SettingsCard>
            )}

            {on && members.length > 0 && (
                <SettingsCard title="On the network" className="mt-4">
                    {members.map((member) => (
                        <SettingsRow
                            key={member.id}
                            label={
                                <Link
                                    href={serverPage(member.id)}
                                    className="hover:underline"
                                >
                                    {member.name}
                                </Link>
                            }
                            description={
                                member.mesh.ip ? (
                                    <span className="type-mono-small">
                                        {member.mesh.ip}
                                    </span>
                                ) : member.isControlPlane ? (
                                    'Dashboard’s server'
                                ) : undefined
                            }
                        >
                            <MeshStatus mesh={member.mesh} />
                        </SettingsRow>
                    ))}
                </SettingsCard>
            )}
        </Section>
    );
}

function PublicTraffic({
    ingress,
}: {
    ingress: App.Data.Ingress.IngressSettingsData;
}) {
    const { overview, edges } = ingress;

    return (
        <SettingsCard
            id="public-traffic"
            title="Public traffic"
            description="Edge servers receive it; DNS points at all of them."
            footer={
                overview.singlePointOfFailure ? (
                    <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <StatusIcon state="warning" />
                        <span className="text-foreground">
                            One edge is a single point of failure.
                        </span>
                        Give a second public server the edge role.
                        <LearnMore href={overview.guideUrl} />
                    </span>
                ) : undefined
            }
        >
            {edges.length === 0 ? (
                <SettingsRow
                    label="No edge server yet"
                    description="Give a public server the edge role to serve your apps."
                />
            ) : (
                edges.map((edge) => {
                    const routes = edge.state
                        ? routeStates[edge.state]
                        : undefined;
                    const storage = edge.storage
                        ? storageStates[edge.storage]
                        : undefined;

                    return (
                        <SettingsRow
                            key={edge.id}
                            label={
                                <Link
                                    href={serverPage(edge.id)}
                                    className="hover:underline"
                                >
                                    {edge.name}
                                </Link>
                            }
                            description={
                                <span className="flex flex-wrap gap-x-3 gap-y-1">
                                    {edge.publicIpv4 && (
                                        <span className="type-mono-small">
                                            {edge.publicIpv4}
                                        </span>
                                    )}
                                    {routes ? (
                                        <Status
                                            state={routes.state}
                                            label={
                                                edge.routes !== null
                                                    ? `${edge.routes} routes`
                                                    : routes.label
                                            }
                                            className="type-small"
                                        />
                                    ) : (
                                        <span>Not reported yet</span>
                                    )}
                                    {storage && (
                                        <Status
                                            state={storage.state}
                                            label={
                                                edge.mirrored !== null &&
                                                edge.storage !== 'local'
                                                    ? `${storage.label} · ${edge.mirrored} mirrored`
                                                    : storage.label
                                            }
                                            className="type-small"
                                        />
                                    )}
                                    {edge.message && (
                                        <span>{edge.message}</span>
                                    )}
                                </span>
                            }
                        >
                            {edge.controlPlane ? (
                                <span className="type-small text-muted-foreground">
                                    Shares certificates
                                </span>
                            ) : (
                                <label className="flex items-center gap-2 type-small text-muted-foreground">
                                    Share certificates
                                    <Switch
                                        checked={edge.shared}
                                        aria-label={`Share certificates with ${edge.name}`}
                                        onCheckedChange={(shared) =>
                                            router.put(
                                                updateEdge(edge.id).url,
                                                { shared },
                                                { preserveScroll: true },
                                            )
                                        }
                                    />
                                </label>
                            )}
                        </SettingsRow>
                    );
                })
            )}
        </SettingsCard>
    );
}

/**
 * One provider's credential fields. Secret fields are write-only: with
 * stored credentials of the same provider an empty field keeps its value.
 */
function DnsCredentialFields({
    option,
    keep,
    stored,
    errors,
}: {
    option: App.Data.Ingress.DnsProviderOptionData;
    keep: boolean;
    stored: Record<string, string | null>;
    errors: Record<string, string>;
}) {
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            {option.fields.map((field) => {
                const id = `dns-${field.key}`;
                const name = `credentials[${field.key}]`;
                const error = errors[`credentials.${field.key}`];
                const current = keep
                    ? (stored[field.key] ?? undefined)
                    : undefined;

                return (
                    <div
                        key={`${option.value}-${field.key}`}
                        className={cn(
                            'grid content-start gap-1.5',
                            option.fields.length === 1 && 'sm:col-span-2',
                        )}
                    >
                        <label htmlFor={id} className="type-label">
                            {field.label}
                        </label>
                        {field.options !== null ? (
                            <select
                                id={id}
                                name={name}
                                defaultValue={
                                    current ?? Object.keys(field.options)[0]
                                }
                                className="h-9 rounded-md border bg-background px-3 type-body"
                            >
                                {Object.entries(field.options).map(
                                    ([value, label]) => (
                                        <option key={value} value={value}>
                                            {label}
                                        </option>
                                    ),
                                )}
                            </select>
                        ) : (
                            <Input
                                id={id}
                                name={name}
                                type={field.secret ? 'password' : 'text'}
                                autoComplete="off"
                                spellCheck={false}
                                maxLength={512}
                                defaultValue={
                                    field.secret ? undefined : current
                                }
                                pattern={field.pattern ?? undefined}
                                required={!keep}
                                placeholder={
                                    keep && field.secret
                                        ? 'Stored · leave empty to keep'
                                        : undefined
                                }
                                className={cn(!field.secret && 'type-mono')}
                            />
                        )}
                        <InputError message={error} />
                    </div>
                );
            })}
        </div>
    );
}

function Certificates({
    ingress,
}: {
    ingress: App.Data.Ingress.IngressSettingsData;
}) {
    const docsUrl = useDocsUrl();
    const [provider, setProvider] = useState<App.Enums.DnsProvider | null>(
        ingress.dnsProvider ?? ingress.providers[0]?.value ?? null,
    );
    const option = ingress.providers.find((item) => item.value === provider);
    const keep = ingress.hasToken && provider === ingress.dnsProvider;
    const check =
        provider === ingress.dnsProvider ? ingress.verification : null;

    return (
        <SettingsCard
            id="certificates"
            title="Certificates"
            description="HTTPS for every address, issued and renewed by itself."
        >
            <SettingsRow
                label="Wildcard certificates"
                description={
                    ingress.appsDomain === null
                        ? 'Needs a platform domain first.'
                        : ingress.wildcardActive
                          ? `One certificate covers every address under ${ingress.appsDomain}.`
                          : 'With a DNS provider, new addresses get HTTPS instantly, without rate limits.'
                }
            >
                {ingress.appsDomain === null ? (
                    <Button asChild size="sm" variant="outline">
                        <Link href={onboarding()}>Set a platform domain</Link>
                    </Button>
                ) : (
                    <StatusPill
                        state={ingress.wildcardActive ? 'active' : 'idle'}
                        label={ingress.wildcardActive ? 'On' : 'Per address'}
                    />
                )}
            </SettingsRow>

            {!ingress.modulesAvailable && (
                <SettingsRow
                    label={
                        <span className="inline-flex items-center gap-2">
                            <StatusIcon state="warning" />
                            Not available with the current edge image
                        </span>
                    }
                    description="Wildcard and shared certificates need launchroom’s Caddy image on your edges."
                />
            )}

            {ingress.appsDomain !== null && option !== undefined && (
                <SettingsBody>
                    <Form
                        {...update.form()}
                        key={provider}
                        resetOnSuccess
                        options={{ preserveScroll: true }}
                        className="flex flex-col gap-5"
                    >
                        {({ processing, errors }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="provider"
                                    value={option.value}
                                />
                                <div className="flex flex-col gap-2">
                                    <span className="type-label">
                                        DNS provider
                                    </span>
                                    <ChoiceTiles<App.Enums.DnsProvider>
                                        label="DNS provider"
                                        columns={3}
                                        value={provider}
                                        onChange={setProvider}
                                        options={ingress.providers.map(
                                            (item) => ({
                                                value: item.value,
                                                label: item.label,
                                                description:
                                                    item.value ===
                                                    ingress.dnsProvider
                                                        ? 'In use'
                                                        : undefined,
                                                media: (
                                                    <Logo
                                                        kind={
                                                            dnsLogo[item.value]
                                                        }
                                                        name={item.label}
                                                        size={32}
                                                        decorative
                                                    />
                                                ),
                                            }),
                                        )}
                                    />
                                    <InputError message={errors.provider} />
                                </div>
                                <div className="flex flex-col gap-3">
                                    <p className="type-small text-muted-foreground">
                                        {option.help} Stored encrypted; only
                                        edges you share certificates with
                                        receive it.
                                    </p>
                                    <DnsCredentialFields
                                        option={option}
                                        keep={keep}
                                        stored={ingress.storedFields}
                                        errors={errors}
                                    />
                                </div>
                                {check && (
                                    <Status
                                        state={check.ok ? 'active' : 'failed'}
                                        label={
                                            check.ok
                                                ? 'Credentials work'
                                                : 'Check failed'
                                        }
                                        detail={`${check.message} ${shortAge(check.checkedAt) ?? ''}`}
                                        className="type-small"
                                    />
                                )}
                                <div className="flex flex-wrap items-center gap-2">
                                    <Button type="submit" disabled={processing}>
                                        {processing && <Spinner />}
                                        Save and verify
                                    </Button>
                                    {ingress.dnsProvider !== null &&
                                        provider === ingress.dnsProvider && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                onClick={() =>
                                                    router.post(
                                                        verify().url,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Verify again
                                            </Button>
                                        )}
                                </div>
                            </>
                        )}
                    </Form>
                </SettingsBody>
            )}

            <SettingsRow
                label="Shared certificate storage"
                description={
                    ingress.storageConfigured ? (
                        <>
                            Edges share certificates through{' '}
                            <span className="type-mono-small text-foreground">
                                {ingress.storageAddress}
                            </span>{' '}
                            and keep a local copy.
                        </>
                    ) : (
                        <>
                            Each edge keeps its own certificates, fine for one
                            edge.{' '}
                            <LearnMore
                                href={docsUrl(
                                    'servers/high-availability-ingress',
                                )}
                            />
                        </>
                    )
                }
            >
                <StatusPill
                    state={ingress.storageConfigured ? 'active' : 'idle'}
                    label={ingress.storageConfigured ? 'On' : 'Off'}
                />
            </SettingsRow>

            {ingress.dnsProvider !== null && (
                <SettingsRow
                    label="Remove the DNS provider"
                    description="Its credentials are deleted; addresses get a certificate each again."
                >
                    <ConfirmAction
                        trigger="Remove"
                        title="Remove the DNS provider?"
                        description="Its credentials are deleted. Generated addresses get a certificate each again, issued on their first request."
                        confirmLabel="Remove"
                        form={destroy.form()}
                        destructive
                    />
                </SettingsRow>
            )}
        </SettingsCard>
    );
}

/**
 * Settings → Network (direction §9.1): how servers reach each other and
 * how the public reaches your apps, on one page. Instance owners only.
 */
export default function NetworkSettings({
    mesh,
    members,
    recommendedPolicy,
    tags,
    ingress,
    edgeAddresses,
    errors,
}: Props) {
    return (
        <>
            <Head title="Network" />
            <AppDomainsSettings
                key={ingress.appsDomain ?? 'sslip'}
                domain={ingress.appsDomain}
                addresses={edgeAddresses}
            />
            <PrivateNetwork
                mesh={mesh}
                members={members}
                recommendedPolicy={recommendedPolicy}
                tags={tags}
                errors={errors}
            />
            <PublicTraffic ingress={ingress} />
            <Certificates ingress={ingress} />
        </>
    );
}

NetworkSettings.layout = {
    breadcrumbs: [
        { title: 'Settings', href: teamSettings() },
        { title: 'Network', href: networkSettings() },
    ],
};
