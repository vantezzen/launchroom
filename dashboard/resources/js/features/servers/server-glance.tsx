import { Link } from '@inertiajs/react';
import { ChevronRight, Clock, Network } from 'lucide-react';
import type { ReactNode } from 'react';
import { Section } from '@/components/patterns/section';
import { StatusIcon } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
import { Skeleton } from '@/components/ui/skeleton';
import { RoleHints } from '@/features/servers/role-hints';
import { formatLatency } from '@/features/servers/link-format';
import { roleIcons, roleTitles } from '@/features/servers/server-status';
import { tab } from '@/routes/servers';

type Server = App.Data.Servers.ServerDetailData;

function Fact({
    icon,
    label,
    href,
    children,
    detail,
}: {
    icon: ReactNode;
    label: string;
    href: string;
    children: ReactNode;
    detail?: ReactNode;
}) {
    return (
        <li className="min-w-0">
            <Link
                href={href}
                className="group -m-3 flex items-start gap-3.5 rounded-lg p-3 transition-colors duration-fast hover:bg-hover focus-visible:ring-2 focus-visible:ring-signal focus-visible:outline-none"
            >
                <span
                    aria-hidden="true"
                    className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg bg-recessed text-muted-foreground"
                >
                    {icon}
                </span>
                <span className="flex min-w-0 flex-1 flex-col">
                    <span className="type-small text-muted-foreground">
                        {label}
                    </span>
                    <span className="type-body">{children}</span>
                    {detail && (
                        <span className="type-small text-muted-foreground">
                            {detail}
                        </span>
                    )}
                </span>
                <ChevronRight
                    aria-hidden="true"
                    className="mt-1 size-4 shrink-0 text-faint opacity-0 transition-opacity duration-fast group-hover:opacity-100"
                />
            </Link>
        </li>
    );
}

const order: App.Enums.ServerRole[] = ['runtime', 'edge', 'build', 'registry'];

/** "Runs apps, serves traffic and builds". */
function duties(roles: App.Enums.ServerRole[]): string {
    const words = order
        .filter((role) => roles.includes(role))
        .map((role) => roleTitles[role].toLowerCase());

    if (words.length === 0) {
        return 'Nothing yet';
    }

    const text =
        words.length === 1
            ? words[0]
            : `${words.slice(0, -1).join(', ')} and ${words.at(-1)}`;

    return text.charAt(0).toUpperCase() + text.slice(1);
}

function median(values: number[]): number | null {
    const sorted = [...values].sort((a, b) => a - b);

    return sorted.length > 0
        ? sorted[Math.floor((sorted.length - 1) / 2)]
        : null;
}

/**
 * Overview → At a glance (docs/08 §14): four quiet facts that each open the
 * tab with the details (what it does, public traffic, the private network,
 * the last report), then what needs attention: retired volumes waiting for
 * an OK and the role hints. Amber only for something to act on.
 */
export function ServerGlance({
    server,
    edge,
    mesh,
    peers,
    retiredVolumes,
    hints,
}: {
    server: Server;
    edge: App.Data.Ingress.EdgeIngressStatusData | null;
    mesh: App.Data.Servers.ServerMeshData;
    /** Deferred: undefined while it loads. */
    peers: App.Data.Servers.MeshPeerData[] | undefined;
    retiredVolumes: App.Data.Servers.RetiredVolumeData[];
    hints: App.Data.Servers.RoleHintData[];
}) {
    const revoked = server.status === 'revoked';
    const settings = tab({ server: server.id, tab: 'settings' }).url;
    const network = tab({ server: server.id, tab: 'network' }).url;
    const Duty = roleIcons.runtime;
    const Edge = roleIcons.edge;
    const latency = median(
        (peers ?? [])
            .map((peer) => peer.quality?.latencyMs ?? null)
            .filter((ms): ms is number => ms !== null),
    );
    const waiting = retiredVolumes.filter(
        (volume) => volume.needsConfirmation && volume.status === 'retained',
    ).length;

    let privateNetwork: ReactNode;
    let privateDetail: ReactNode = null;

    if (peers === undefined) {
        privateNetwork = <Skeleton className="my-1 h-4 w-32" />;
    } else if (peers.length === 0) {
        privateNetwork = mesh.state === 'off' ? 'Off' : 'No other servers';
        privateDetail =
            mesh.state === 'off' ? 'Turns on with a second server' : null;
    } else {
        privateNetwork = `Reaches ${peers.length === 1 ? 'one server' : `${peers.length} servers`}`;
        privateDetail =
            latency !== null
                ? `about ${formatLatency(latency)} round trip`
                : null;
    }

    return (
        <Section title="At a glance">
            <ul className="grid gap-x-8 gap-y-6 rounded-xl border bg-card p-6 shadow-card sm:grid-cols-2 xl:grid-cols-4">
                <Fact
                    icon={<Duty className="size-3.5" />}
                    label="Does"
                    href={`${settings}#roles`}
                    detail={
                        server.isControlPlane ? 'and runs this dashboard' : null
                    }
                >
                    {revoked ? 'Nothing, it was revoked' : duties(server.roles)}
                </Fact>
                <Fact
                    icon={<Edge className="size-3.5" />}
                    label="Public traffic"
                    href={network}
                    detail={
                        edge
                            ? edge.routes !== null
                                ? `${edge.routes} ${edge.routes === 1 ? 'route' : 'routes'} applied`
                                : null
                            : null
                    }
                >
                    {edge ? (
                        <>
                            Enters at{' '}
                            <span className="type-mono">
                                {edge.publicIpv4 ??
                                    edge.publicIpv6 ??
                                    'an unknown address'}
                            </span>
                        </>
                    ) : (
                        'Doesn’t serve traffic'
                    )}
                </Fact>
                <Fact
                    icon={<Network className="size-3.5" />}
                    label="Private network"
                    href={network}
                    detail={privateDetail}
                >
                    {privateNetwork}
                </Fact>
                <Fact
                    icon={<Clock className="size-3.5" />}
                    label="Last report"
                    href={network}
                    detail={
                        server.daemonVersion
                            ? `version ${server.daemonVersion}`
                            : null
                    }
                >
                    <TimeAgo value={server.lastSeenAt} fallback="Never" />
                </Fact>
            </ul>

            {waiting > 0 && (
                <Link
                    href={settings}
                    className="flex items-start gap-3.5 rounded-xl border bg-card px-4 py-3.5 transition-colors duration-fast hover:border-border-strong focus-visible:ring-2 focus-visible:ring-signal focus-visible:outline-none"
                >
                    <span
                        aria-hidden="true"
                        className="flex size-7 shrink-0 items-center justify-center rounded-lg bg-recessed"
                    >
                        <StatusIcon state="warning" />
                    </span>
                    <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span className="type-body font-medium">
                            {waiting === 1
                                ? 'A retired volume needs your OK'
                                : `${waiting} retired volumes need your OK`}
                        </span>
                        <span className="type-small text-muted-foreground">
                            Its database had backups, so it’s only deleted once
                            you agree. Review it in Settings.
                        </span>
                    </span>
                </Link>
            )}

            <RoleHints hints={hints} />
        </Section>
    );
}
