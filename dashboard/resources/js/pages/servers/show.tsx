import { Head, Link, usePage } from '@inertiajs/react';
import { Ban, ShieldCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { TimeRangeSelect } from '@/components/charts/time-range-select';
import type { TimeRange } from '@/components/charts/time-range-select';
import { ConfirmAction } from '@/components/confirm-action';
import { EmptyState } from '@/components/patterns/empty-state';
import { Page } from '@/components/patterns/page';
import { PillTabs } from '@/components/patterns/pill-tabs';
import { Section } from '@/components/patterns/section';
import { TopBarActions } from '@/components/patterns/top-bar';
import { Button } from '@/components/ui/button';
import { ServerCharts } from '@/features/metrics/server-charts';
import { RemovePendingButton } from '@/features/servers/remove-pending';
import { RetiredVolumes } from '@/features/servers/retired-volumes';
import {
    EdgeTraffic,
    ServerConnection,
} from '@/features/servers/server-connection';
import { ServerGlance } from '@/features/servers/server-glance';
import { ServerHealth } from '@/features/servers/server-health';
import { ServerHero } from '@/features/servers/server-hero';
import { ServerLinks } from '@/features/servers/server-links';
import { ServerRoles } from '@/features/servers/server-roles';
import { ServerRuntime } from '@/features/servers/server-runtime';
import { ServerSettings } from '@/features/servers/server-settings';
import { ServerUsage, WhatRunsHere } from '@/features/servers/server-usage';
import {
    ServerMetricsLiveUpdates,
    useServerMetrics,
} from '@/features/servers/use-server-metrics';
import { SettingsCard, SettingsRow } from '@/features/settings/settings-card';
import { edit as moveLaunchroom } from '@/routes/move';
import {
    approve,
    index,
    move as moveRoute,
    replace as replaceRoute,
    revoke,
    show,
    tab as tabRoute,
} from '@/routes/servers';

/**
 * Every tab gets the hero's props; the rest only arrive on the tab that
 * shows them (ServerController::show), `meshPeers` deferred.
 */
type Props = {
    tab: App.Enums.ServerTab;
    server: App.Data.Servers.ServerDetailData;
    mesh: App.Data.Servers.ServerMeshData;
    /** Overview. */
    health?: App.Data.Servers.ServerHealthData;
    /** Overview, Settings. */
    retiredVolumes?: App.Data.Servers.RetiredVolumeData[];
    placement?: App.Data.Servers.ServerPlacementData;
    roleHints?: App.Data.Servers.RoleHintData[];
    /** Overview, Network (deferred). */
    meshPeers?: App.Data.Servers.MeshPeerData[];
    /** Network. */
    measureLinks?: boolean;
    runtime?: App.Data.Servers.ServerRuntimeData | null;
    /** Settings. */
    canUpdateRoles?: boolean;
    /** Roles this machine can't take, with the reason (D53). */
    roleLimits?: Partial<Record<App.Enums.ServerRole, string>>;
    canConfirmVolumeDeletion?: boolean;
    replace: {
        available: boolean;
        canView: boolean;
        replacedBy: string | null;
        replacedAt: string | null;
    };
    edge: App.Data.Ingress.EdgeIngressStatusData | null;
    teamId: string;
};

function ApprovalCallout({
    server,
}: {
    server: App.Data.Servers.ServerDetailData;
}) {
    return (
        <div className="flex flex-col gap-4 rounded-xl border bg-card p-6 shadow-card sm:flex-row sm:items-center">
            <ShieldCheck
                className="size-5 shrink-0 text-signal"
                aria-hidden="true"
            />
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <p className="type-body font-medium">
                    {server.name} is waiting for your approval.
                </p>
                <p className="type-small text-muted-foreground">
                    Compare this key with the “identity ready” line on the
                    server first:{' '}
                    <code className="type-mono-small break-all text-foreground">
                        {server.signingKeyFingerprint}
                    </code>
                </p>
            </div>
            <ConfirmAction
                trigger="Approve"
                title={`Approve ${server.name}?`}
                description="It starts receiving apps and tasks from this dashboard right away."
                confirmLabel="Approve server"
                form={approve.form(server.id)}
            />
        </div>
    );
}

const TAB_LABELS: Record<App.Enums.ServerTab, string> = {
    overview: 'Overview',
    metrics: 'Metrics',
    network: 'Network',
    settings: 'Settings',
};

function DangerZone({ server }: { server: App.Data.Servers.ServerDetailData }) {
    if (!server.canRevoke) {
        return null;
    }

    if (server.status === 'pending_approval') {
        return (
            <SettingsCard title="Danger zone">
                <SettingsRow
                    label={`Remove ${server.name}`}
                    description="It was never approved and runs nothing; its key stops working."
                >
                    <RemovePendingButton
                        target={{
                            kind: 'server',
                            id: server.id,
                            name: server.name,
                        }}
                    />
                </SettingsRow>
            </SettingsCard>
        );
    }

    return (
        <SettingsCard title="Danger zone">
            <MoveRow server={server} />
            <SettingsRow
                label={`Revoke ${server.name}`}
                description="Its key is rejected immediately; it needs a new install command to come back."
            >
                <ConfirmAction
                    trigger="Revoke"
                    title={`Revoke ${server.name}?`}
                    description="The server’s key is rejected immediately. It stops receiving apps and tasks, and must be added again with a new install command. This can’t be undone."
                    confirmLabel="Revoke server"
                    form={revoke.form(server.id)}
                    destructive
                />
            </SettingsRow>
        </SettingsCard>
    );
}

/**
 * "Move to another server" (docs/14 §13.2); the dashboard's own server
 * moves with Settings → Move launchroom (instance owners).
 */
function MoveRow({ server }: { server: App.Data.Servers.ServerDetailData }) {
    const { auth } = usePage().props;

    if (server.status === 'revoked') {
        return null;
    }

    if (server.isControlPlane) {
        return auth.user.is_instance_owner === true ? (
            <SettingsRow
                label="Move launchroom to a new server"
                description="The dashboard and everything on this server move to another machine, without losing data."
            >
                <Button asChild variant="outline">
                    <Link href={moveLaunchroom()}>Move</Link>
                </Button>
            </SettingsRow>
        ) : null;
    }

    return (
        <SettingsRow
            label="Move to another server"
            description="Everything on it moves to another server while it keeps running; databases and volumes stop briefly while they are copied."
        >
            <Button asChild variant="outline">
                <Link href={moveRoute(server.id)}>Move</Link>
            </Button>
        </SettingsRow>
    );
}

/** Network → Public traffic for a server that doesn't take any. */
function NoPublicTraffic({ serverId }: { serverId: string }) {
    return (
        <Section title="Public traffic">
            <div className="flex flex-col gap-1 rounded-xl border bg-card px-6 py-5 shadow-card">
                <p className="type-body">This server doesn’t serve traffic.</p>
                <p className="type-small text-muted-foreground">
                    Requests to your apps enter through your other servers and
                    reach this one over the private network. Turn on{' '}
                    <Link
                        href={`${tabRoute({ server: serverId, tab: 'settings' }).url}#roles`}
                        className="text-foreground underline underline-offset-4"
                    >
                        Serve traffic
                    </Link>{' '}
                    to make it an entry point too.
                </p>
            </div>
        </Section>
    );
}

export default function ShowServer({
    tab,
    server,
    health,
    retiredVolumes,
    canConfirmVolumeDeletion,
    mesh,
    meshPeers,
    measureLinks,
    runtime,
    placement,
    canUpdateRoles,
    roleLimits,
    roleHints,
    replace,
    edge,
    teamId,
}: Props) {
    const [range, setRange] = useState<TimeRange>('1h');
    const revoked = server.status === 'revoked';
    // Usage tiles (Overview) and charts (Metrics) share one query and range.
    const metrics = useServerMetrics(server.id, range);
    const barRef = useRef<HTMLDivElement>(null);

    // Switching tabs keeps the scroll position; from far down a long tab,
    // the new tab starts at the tab bar instead of mid-page.
    useEffect(() => {
        const bar = barRef.current;

        if (bar && bar.getBoundingClientRect().top < 0) {
            bar.scrollIntoView({ block: 'start' });
        }
    }, [tab]);

    const tabs: App.Enums.ServerTab[] = revoked
        ? ['overview', 'network', 'settings']
        : ['overview', 'metrics', 'network', 'settings'];

    return (
        <>
            <Head title={`${server.name} · ${TAB_LABELS[tab]}`} />
            <ServerMetricsLiveUpdates team={teamId} />

            <TopBarActions>
                {replace.replacedBy !== null && replace.canView && (
                    <Button asChild variant="ghost" size="sm">
                        <Link href={replaceRoute(server.id)}>
                            Replacement details
                        </Link>
                    </Button>
                )}
                {replace.available && (
                    <Button asChild size="sm">
                        <Link href={replaceRoute(server.id)}>
                            Replace server
                        </Link>
                    </Button>
                )}
            </TopBarActions>

            <Page className="gap-12">
                <div className="flex flex-col gap-8">
                    <ServerHero server={server} />

                    {server.canApprove && <ApprovalCallout server={server} />}

                    <div ref={barRef} className="scroll-mt-4 border-b pb-3">
                        <PillTabs
                            label={`${server.name} pages`}
                            scroll
                            preserve
                            tabs={tabs.map((key) => ({
                                label: TAB_LABELS[key],
                                href:
                                    key === 'overview'
                                        ? show(server.id).url
                                        : tabRoute({
                                              server: server.id,
                                              tab: key,
                                          }).url,
                                active: key === tab,
                            }))}
                        />
                    </div>
                </div>

                {tab === 'overview' && (
                    <>
                        {!revoked && (
                            <ServerUsage
                                metrics={metrics.data}
                                specs={server.specs}
                                range={range}
                                onRangeChange={setRange}
                            />
                        )}

                        <div className="grid gap-12 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)] lg:gap-8">
                            {placement && (
                                <WhatRunsHere
                                    placement={placement}
                                    metrics={metrics.data}
                                    cores={server.specs?.cpus ?? null}
                                />
                            )}
                            {health && <ServerHealth health={health} />}
                        </div>

                        <ServerGlance
                            server={server}
                            edge={edge}
                            mesh={mesh}
                            peers={meshPeers}
                            retiredVolumes={retiredVolumes ?? []}
                            hints={revoked ? [] : (roleHints ?? [])}
                        />
                    </>
                )}

                {tab === 'metrics' && (
                    <ServerCharts
                        metrics={metrics.data}
                        range={range}
                        actions={
                            <TimeRangeSelect
                                value={range}
                                onChange={setRange}
                            />
                        }
                    />
                )}

                {tab === 'network' && (
                    <>
                        {edge !== null ? (
                            <EdgeTraffic edge={edge} />
                        ) : (
                            !revoked && <NoPublicTraffic serverId={server.id} />
                        )}

                        {!revoked && (
                            <ServerLinks
                                serverName={server.name}
                                peers={meshPeers}
                                measuring={measureLinks ?? true}
                                mesh={mesh}
                            />
                        )}

                        {!(revoked && !runtime) && (
                            <ServerRuntime runtime={runtime ?? null} />
                        )}

                        <ServerConnection
                            server={server}
                            mesh={mesh}
                            replacedBy={replace.replacedBy}
                        />
                    </>
                )}

                {tab === 'settings' && (
                    <div className="flex flex-col gap-10">
                        {!revoked && placement && (
                            <ServerRoles
                                serverId={server.id}
                                name={server.name}
                                roles={server.roles}
                                workloads={placement.workloads.length}
                                canUpdate={canUpdateRoles ?? false}
                                hints={roleHints ?? []}
                                limits={roleLimits ?? {}}
                            />
                        )}

                        {!revoked && placement && (
                            <ServerSettings
                                server={server}
                                placement={placement}
                            />
                        )}

                        {retiredVolumes && retiredVolumes.length > 0 && (
                            <Section title="Storage">
                                <RetiredVolumes
                                    serverId={server.id}
                                    volumes={retiredVolumes}
                                    canConfirm={
                                        canConfirmVolumeDeletion ?? false
                                    }
                                />
                            </Section>
                        )}

                        {revoked && (retiredVolumes ?? []).length === 0 && (
                            <EmptyState icon={Ban}>
                                {server.name} was revoked. There is nothing left
                                to set up here.
                            </EmptyState>
                        )}

                        <DangerZone server={server} />
                    </div>
                )}
            </Page>
        </>
    );
}

ShowServer.layout = {
    breadcrumbs: [{ title: 'Servers', href: index() }],
};
