import { Head, Link } from '@inertiajs/react';
import { ChevronRight, Network, Plus } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { ServerMap } from '@/components/map';
import type { MapLink, MapLocation } from '@/components/map';
import { EmptyState } from '@/components/patterns/empty-state';
import { Page, PageHeader } from '@/components/patterns/page';
import { Section } from '@/components/patterns/section';
import { StatusIcon } from '@/components/status';
import { Button } from '@/components/ui/button';
import {
    AddServerCard,
    FleetCard,
    PendingEnrollmentCard,
} from '@/features/servers/fleet-card';
import { FleetLoad } from '@/features/servers/fleet-load';
import { FleetTopology } from '@/features/servers/fleet-topology';
import { fleetOrder, shortAge } from '@/features/servers/format';
import { formatLatency } from '@/features/servers/link-format';
import { roleIcons } from '@/features/servers/server-status';
import {
    ServerMetricsLiveUpdates,
    useFleetMetrics,
} from '@/features/servers/use-server-metrics';
import { edit as networkSettings } from '@/routes/network-settings';
import { create, index, show } from '@/routes/servers';

type Server = App.Data.Servers.ServerData;

type Props = {
    servers: Server[];
    canAddServer: boolean;
    mesh: App.Data.Servers.MeshOverviewData;
    edgeOverview?: App.Data.Ingress.EdgeOverviewData;
    canManageIngress?: boolean;
    teamId: string;
    pendingEnrollments: App.Data.Servers.EnrollmentData[];
    replaceable: string[];
    meshLinks: App.Data.Servers.MeshLinkData[];
};

function plural(count: number, one: string, many = `${one}s`): string {
    return `${count} ${count === 1 ? one : many}`;
}

function summary(servers: Server[], places: number): string {
    const online = servers.filter((s) => s.status === 'online').length;
    const down = servers.filter(
        (s) => s.status === 'unreachable' || s.status === 'offline',
    ).length;
    const waiting = servers.filter(
        (s) => s.status === 'pending_approval',
    ).length;

    return [
        places > 1
            ? `${plural(servers.length, 'server')} in ${places} places`
            : plural(servers.length, 'server'),
        online > 0 && online < servers.length ? `${online} online` : null,
        online === servers.length && servers.length > 0 ? 'all online' : null,
        down > 0 ? `${down} unreachable` : null,
        waiting > 0 ? `${waiting} waiting for approval` : null,
    ]
        .filter(Boolean)
        .join(' · ');
}

/** One bubble per place; links follow each server's path to the dashboard's server. */
function mapData(servers: Server[]): {
    locations: MapLocation[];
    links: MapLink[];
} {
    const byPlace = new Map<string, MapLocation & { down: number }>();
    const placeOf = new Map<string, string>();

    for (const server of servers) {
        const location = server.location;

        if (!location) {
            continue;
        }

        const id = `${location.latitude.toFixed(1)},${location.longitude.toFixed(1)}`;
        const place = byPlace.get(id) ?? {
            id,
            label: location.label,
            lat: location.latitude,
            lon: location.longitude,
            servers: 0,
            down: 0,
            edge: false,
        };
        place.servers += 1;
        place.down +=
            server.status === 'unreachable' || server.status === 'offline'
                ? 1
                : 0;
        place.edge = place.edge || server.roles.includes('edge');
        byPlace.set(id, place);
        placeOf.set(server.id, id);
    }

    const locations = [...byPlace.values()].map(({ down, ...place }) => ({
        ...place,
        status:
            down === place.servers ? ('unreachable' as const) : ('ok' as const),
    }));

    const home = servers.find((server) => server.isControlPlane);
    const homePlace = home ? placeOf.get(home.id) : undefined;
    const links: MapLink[] = [];
    const seen = new Set<string>();

    for (const server of servers) {
        const place = placeOf.get(server.id);
        const path = server.mesh?.pathToControlPlane;

        if (!homePlace || !place || place === homePlace || !path) {
            continue;
        }

        const key = `${place}:${path}`;

        if (!seen.has(key)) {
            seen.add(key);
            links.push({
                from: place,
                to: homePlace,
                relayed: path === 'relay',
            });
        }
    }

    return { locations, links };
}

/** Things that need an owner's attention, one quiet line each. */
function Attention({
    mesh,
    edgeOverview,
    canManageIngress,
}: {
    mesh: App.Data.Servers.MeshOverviewData;
    edgeOverview?: App.Data.Ingress.EdgeOverviewData;
    canManageIngress: boolean;
}) {
    const items: { key: string; text: string; detail: string }[] = [];

    if (edgeOverview?.singlePointOfFailure) {
        items.push({
            key: 'spof',
            text: 'All public traffic goes through one server.',
            detail: 'Give the edge role to a second public server so one can fail without downtime.',
        });
    }

    if (edgeOverview && edgeOverview.degradedStorage.length > 0) {
        items.push({
            key: 'storage',
            text: `${edgeOverview.degradedStorage.join(', ')} ${edgeOverview.degradedStorage.length === 1 ? 'is' : 'are'} serving certificates from a local copy.`,
            detail: 'Shared certificate storage is unreachable.',
        });
    }

    if (mesh.canManage && mesh.error !== null) {
        items.push({
            key: 'mesh',
            text: 'The private network has a problem.',
            detail: mesh.error,
        });
    }

    if (items.length === 0) {
        return null;
    }

    return (
        <ul className="flex flex-col divide-y overflow-hidden rounded-xl border bg-card">
            {items.map((item) => (
                <li
                    key={item.key}
                    className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-4"
                >
                    <StatusIcon state="warning" />
                    <div className="flex min-w-0 flex-1 flex-col">
                        <span className="type-body font-medium">
                            {item.text}
                        </span>
                        <span className="type-small text-muted-foreground">
                            {item.detail}
                        </span>
                    </div>
                    {canManageIngress && (
                        <Button asChild size="sm" variant="ghost">
                            <Link href={networkSettings()}>Open network</Link>
                        </Button>
                    )}
                    {item.key === 'spof' && edgeOverview && (
                        <a
                            href={edgeOverview.guideUrl}
                            target="_blank"
                            rel="noreferrer"
                            className="type-small text-signal underline-offset-4 hover:underline"
                        >
                            Learn more
                        </a>
                    )}
                </li>
            ))}
        </ul>
    );
}

function names(servers: Server[]): string {
    const list = servers.map((server) => server.name);

    return list.length <= 2
        ? list.join(' and ')
        : `${list.slice(0, 2).join(', ')} and ${list.length - 2} more`;
}

function Fact({
    icon,
    label,
    children,
}: {
    icon: ReactNode;
    label: string;
    children: ReactNode;
}) {
    return (
        <li className="flex items-start gap-3.5 py-4 first:pt-0 last:pb-0">
            <span
                aria-hidden="true"
                className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg bg-recessed text-muted-foreground"
            >
                {icon}
            </span>
            <span className="flex min-w-0 flex-col">
                <span className="type-small text-muted-foreground">
                    {label}
                </span>
                <span className="type-body">{children}</span>
            </span>
        </li>
    );
}

/** System-wide facts in four quiet rows: what runs, where traffic enters, where builds run, the private network. */
function AtAGlance({
    servers,
    links,
    meshEnabled,
}: {
    servers: Server[];
    links: App.Data.Servers.MeshLinkData[];
    meshEnabled: boolean;
}) {
    const approved = servers.filter((s) => s.status !== 'pending_approval');
    const replicas = approved.reduce((sum, s) => sum + s.workloads, 0);
    const running = approved.filter((s) => s.workloads > 0).length;
    const edges = approved.filter((s) => s.roles.includes('edge'));
    const builders = approved.filter((s) => s.roles.includes('build'));
    const direct = links.filter((l) => l.path === 'direct').length;
    const relayed = links.filter((l) => l.path === 'relay').length;
    // The typical round trip between servers (median of the measured links).
    const latencies = links
        .map((l) => l.latencyMs)
        .filter((ms): ms is number => ms !== null)
        .sort((a, b) => a - b);
    const typical =
        latencies.length > 0
            ? latencies[Math.floor((latencies.length - 1) / 2)]
            : null;
    const Edge = roleIcons.edge;
    const Build = roleIcons.build;
    const Apps = roleIcons.runtime;

    return (
        <section
            aria-labelledby="at-a-glance-title"
            className="flex h-full min-w-0 flex-col gap-5 rounded-xl border bg-card p-6 shadow-card"
        >
            <h2 id="at-a-glance-title" className="type-heading">
                At a glance
            </h2>
            <ul className="flex flex-col divide-y">
                <Fact icon={<Apps className="size-3.5" />} label="Running">
                    {replicas === 0
                        ? 'Nothing deployed yet'
                        : `${replicas} ${replicas === 1 ? 'replica' : 'replicas'} on ${running === 1 ? 'one server' : `${running} servers`}`}
                </Fact>
                <Fact
                    icon={<Edge className="size-3.5" />}
                    label="Public traffic"
                >
                    {edges.length > 0 ? (
                        `Enters through ${names(edges)}`
                    ) : (
                        <span className="inline-flex items-center gap-1.5">
                            <StatusIcon state="warning" />
                            No server takes public traffic
                        </span>
                    )}
                </Fact>
                <Fact icon={<Build className="size-3.5" />} label="Builds">
                    {builders.length > 0 ? (
                        `Run on ${names(builders)}`
                    ) : (
                        <span className="inline-flex items-center gap-1.5">
                            <StatusIcon state="warning" />
                            No build server yet
                        </span>
                    )}
                </Fact>
                <Fact
                    icon={<Network className="size-3.5" />}
                    label="Private network"
                >
                    {!meshEnabled
                        ? approved.length <= 1
                            ? 'Turns on with a second server'
                            : 'Off'
                        : direct + relayed === 0
                          ? 'On'
                          : [
                                direct > 0 ? `${direct} direct` : null,
                                relayed > 0 ? `${relayed} relayed` : null,
                                typical !== null
                                    ? `about ${formatLatency(typical)}`
                                    : null,
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                </Fact>
            </ul>
        </section>
    );
}

function RevokedServers({ servers }: { servers: Server[] }) {
    const [open, setOpen] = useState(false);

    if (servers.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-col gap-3">
            <button
                type="button"
                onClick={() => setOpen((value) => !value)}
                aria-expanded={open}
                className="inline-flex w-fit items-center gap-1 rounded-md type-small text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-signal focus-visible:outline-none"
            >
                <ChevronRight
                    className={`size-3.5 transition-transform duration-fast ${open ? 'rotate-90' : ''}`}
                    aria-hidden="true"
                />
                Revoked ({servers.length})
            </button>
            {open && (
                <ul className="flex flex-col divide-y rounded-xl border">
                    {servers.map((server) => (
                        <li key={server.id}>
                            <Link
                                href={show(server.id)}
                                className="flex items-center justify-between gap-4 px-5 py-3.5 type-body hover:bg-hover"
                            >
                                <span className="text-muted-foreground">
                                    {server.name}
                                </span>
                                <span className="type-small text-muted-foreground">
                                    Last report{' '}
                                    {shortAge(server.lastSeenAt) ?? 'never'}
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

export default function ServersIndex({
    servers,
    canAddServer,
    mesh,
    edgeOverview,
    canManageIngress = false,
    teamId,
    pendingEnrollments,
    replaceable,
    meshLinks,
}: Props) {
    const metrics = useFleetMetrics('1h');
    const usage = new Map(
        (metrics.data?.servers ?? []).map((entry) => [entry.serverId, entry]),
    );
    const active = servers
        .filter((server) => server.status !== 'revoked')
        .sort(fleetOrder);
    const revoked = servers.filter((server) => server.status === 'revoked');
    const { locations, links } = mapData(active);
    const showGhost =
        canAddServer && active.length + pendingEnrollments.length < 3;
    const approved = active.filter(
        (server) => server.status !== 'pending_approval',
    );
    // Capacity from the specs, until the first metrics arrive.
    const capacity = approved.reduce(
        (sum, server) => ({
            cpus: sum.cpus + (server.specs?.cpus ?? 0),
            memoryMb: sum.memoryMb + (server.specs?.memoryMb ?? 0),
            diskMb: sum.diskMb + (server.specs?.diskTotalMb ?? 0),
        }),
        { cpus: 0, memoryMb: 0, diskMb: 0 },
    );

    return (
        <>
            <Head title="Servers" />
            <ServerMetricsLiveUpdates team={teamId} />

            <Page className="gap-12">
                <PageHeader
                    title="Servers"
                    description={
                        active.length > 0
                            ? summary(active, locations.length)
                            : 'Where your apps run.'
                    }
                    actions={
                        <>
                            {canManageIngress && (
                                <Button asChild variant="outline">
                                    <Link href={networkSettings()}>
                                        <Network aria-hidden="true" />
                                        Network
                                    </Link>
                                </Button>
                            )}
                            {canAddServer && (
                                <Button asChild>
                                    <Link href={create()}>
                                        <Plus aria-hidden="true" />
                                        Add server
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                />

                <Attention
                    mesh={mesh}
                    edgeOverview={edgeOverview}
                    canManageIngress={canManageIngress}
                />

                {active.length === 0 && pendingEnrollments.length === 0 ? (
                    <EmptyState
                        icon={Plus}
                        title="No servers yet"
                        action={
                            canAddServer ? (
                                <Button asChild>
                                    <Link href={create()}>Add server</Link>
                                </Button>
                            ) : undefined
                        }
                    >
                        Add a machine with Docker and it connects in one
                        command.
                    </EmptyState>
                ) : (
                    <ul
                        aria-label="Servers"
                        className="grid gap-6 md:grid-cols-2 xl:grid-cols-3"
                    >
                        {active.map((server) => (
                            <li key={server.id}>
                                <FleetCard
                                    server={server}
                                    usage={usage.get(server.id)}
                                    canReplace={replaceable.includes(server.id)}
                                    canRemove={canAddServer}
                                />
                            </li>
                        ))}
                        {pendingEnrollments.map((enrollment) => (
                            <li key={enrollment.id}>
                                <PendingEnrollmentCard
                                    enrollment={enrollment}
                                    canRemove={canAddServer}
                                />
                            </li>
                        ))}
                        {showGhost && (
                            <li>
                                {active.length === 1 ? (
                                    <AddServerCard>
                                        Use a second server to improve
                                        reliability. A machine at home works
                                        too.
                                    </AddServerCard>
                                ) : (
                                    <AddServerCard />
                                )}
                            </li>
                        )}
                    </ul>
                )}

                {active.length > 0 && (
                    <Section
                        title="Overview"
                        className="gap-5"
                        description={
                            mesh.enabled && active.length > 1
                                ? 'Your private network connects them, wherever they are.'
                                : undefined
                        }
                    >
                        <div className="grid gap-6 lg:grid-cols-3">
                            <div className="min-w-0 lg:col-span-2">
                                <FleetLoad
                                    capacity={capacity}
                                    serverCount={approved.length}
                                />
                            </div>
                            <div className="min-w-0">
                                {locations.length > 0 ? (
                                    <ServerMap
                                        locations={locations}
                                        links={links}
                                        height={200}
                                        variant="stacked"
                                        className="h-full"
                                    />
                                ) : (
                                    <AtAGlance
                                        servers={active}
                                        links={meshLinks}
                                        meshEnabled={mesh.enabled}
                                    />
                                )}
                            </div>
                            <div
                                className={
                                    locations.length > 0
                                        ? 'min-w-0 lg:col-span-2'
                                        : 'min-w-0 lg:col-span-3'
                                }
                            >
                                <FleetTopology
                                    servers={active}
                                    links={meshLinks}
                                />
                            </div>
                            {locations.length > 0 && (
                                <div className="min-w-0">
                                    <AtAGlance
                                        servers={active}
                                        links={meshLinks}
                                        meshEnabled={mesh.enabled}
                                    />
                                </div>
                            )}
                        </div>
                    </Section>
                )}

                <RevokedServers servers={revoked} />
            </Page>
        </>
    );
}

ServersIndex.layout = {
    breadcrumbs: [{ title: 'Servers', href: index() }],
};
