import { Link } from '@inertiajs/react';
import { ArrowDown, ArrowUp } from 'lucide-react';
import { Sparkline } from '@/components/charts/sparkline';
import { Section } from '@/components/patterns/section';
import { StatusIcon } from '@/components/status';
import { Skeleton } from '@/components/ui/skeleton';
import { relativeTime } from '@/components/time-ago';
import { formatLatency, formatRate } from '@/features/servers/link-format';
import { MeshStatus, pathLabels } from '@/features/servers/mesh-status';
import { show } from '@/routes/servers';

type Peer = App.Data.Servers.MeshPeerData;

function peerName(peer: Peer): string {
    return (
        peer.name ??
        (peer.isControlPlane ? 'Dashboard’s server' : 'Another server')
    );
}

function historyLabel(values: (number | null)[]): string {
    const measured = values.filter((v): v is number => v !== null);

    if (measured.length === 0) {
        return 'Round trip, last 24 hours: not measured yet';
    }

    const lo = Math.min(...measured);
    const hi = Math.max(...measured);

    return `Round trip, last 24 hours, ${formatLatency(lo)} to ${formatLatency(hi)}`;
}

function Latency({ peer }: { peer: Peer }) {
    const q = peer.quality;

    if (q === null) {
        return (
            <span className="type-small text-muted-foreground">
                Not measured
            </span>
        );
    }

    if (q.latencyMs === null) {
        return (
            <span className="inline-flex items-center gap-1.5 type-small text-muted-foreground">
                <StatusIcon state="warning" />
                {q.error ? 'No answer' : 'Not measured lately'}
            </span>
        );
    }

    return (
        <span className="flex flex-col items-end">
            <span className="type-heading tabular-nums">
                {formatLatency(q.latencyMs)}
            </span>
            <span className="type-small text-muted-foreground">round trip</span>
        </span>
    );
}

function Rates({ peer, serverName }: { peer: Peer; serverName: string }) {
    const q = peer.quality;
    const name = peerName(peer);

    if (q === null || (q.downloadMbps === null && q.uploadMbps === null)) {
        return (
            <span className="type-small text-muted-foreground">
                {q === null ? '' : 'Speed test pending'}
            </span>
        );
    }

    return (
        <span className="flex flex-row gap-4 type-small tabular-nums sm:flex-col sm:gap-0.5">
            {q.downloadMbps !== null && (
                <span
                    className="inline-flex items-center gap-1"
                    title={`${name} to ${serverName}`}
                >
                    <ArrowDown
                        aria-hidden="true"
                        className="size-3 text-muted-foreground"
                    />
                    <span className="sr-only">
                        {name} to {serverName}:
                    </span>
                    {formatRate(q.downloadMbps)}
                </span>
            )}
            {q.uploadMbps !== null && (
                <span
                    className="inline-flex items-center gap-1"
                    title={`${serverName} to ${name}`}
                >
                    <ArrowUp
                        aria-hidden="true"
                        className="size-3 text-muted-foreground"
                    />
                    <span className="sr-only">
                        {serverName} to {name}:
                    </span>
                    {formatRate(q.uploadMbps)}
                </span>
            )}
        </span>
    );
}

function Footnote({ peer }: { peer: Peer }) {
    const q = peer.quality;
    const parts: string[] = [];

    if (q?.throughputAt) {
        parts.push(`speed test ${relativeTime(q.throughputAt)}`);
    }

    if (q && q.failedProbes > 0) {
        parts.push(
            `${q.failedProbes} of ${q.probes.toLocaleString()} checks failed today`,
        );
    }

    if (q?.latencyMs === null && q.error) {
        parts.push(q.error);
    }

    return parts.length > 0 ? (
        <span className="type-small text-muted-foreground">
            {parts.join(' · ')}
        </span>
    ) : null;
}

/**
 * How this server reaches the others on the private network (docs/05
 * §2.1): per peer the path, the round trip now with its last 24 hours, and
 * the latest speed test in both directions. Only measured values; a server
 * that opted out says so.
 */
export function ServerLinks({
    serverName,
    peers,
    measuring,
    mesh,
}: {
    serverName: string;
    /** Deferred: undefined while it loads. */
    peers: Peer[] | undefined;
    measuring: boolean;
    mesh: App.Data.Servers.ServerMeshData;
}) {
    const measured = (peers ?? []).some((peer) => peer.quality !== null);
    const description =
        peers === undefined || peers.length === 0
            ? undefined
            : measuring
              ? measured
                  ? 'Round trips every minute, a short speed test every few hours.'
                  : 'The first measurements arrive within a few minutes.'
              : 'Measurements are off for this server.';

    if (peers === undefined || peers.length === 0) {
        return (
            <Section
                title="Private network"
                actions={<MeshStatus mesh={mesh} />}
            >
                {peers === undefined ? (
                    <div
                        aria-busy="true"
                        aria-label="Loading the private network"
                        className="flex flex-col divide-y rounded-xl border bg-card shadow-card"
                    >
                        {[0, 1].map((row) => (
                            <div
                                key={row}
                                className="flex items-center justify-between gap-6 p-4 sm:px-5"
                            >
                                <div className="flex flex-col gap-2">
                                    <Skeleton className="h-4 w-28" />
                                    <Skeleton className="h-3 w-44" />
                                </div>
                                <Skeleton className="h-6 w-20" />
                            </div>
                        ))}
                    </div>
                ) : (
                    <p className="rounded-xl border bg-card px-6 py-5 type-body text-muted-foreground shadow-card">
                        {mesh.message ??
                            (mesh.state === 'off'
                                ? 'Turns on by itself when a second server joins. Servers then talk to each other privately, encrypted end to end.'
                                : 'No other servers on the private network yet.')}
                    </p>
                )}
            </Section>
        );
    }

    return (
        <Section
            title="Private network"
            description={description}
            actions={<MeshStatus mesh={mesh} />}
        >
            <ul className="flex flex-col divide-y rounded-xl border bg-card shadow-card">
                {peers.map((peer) => {
                    const q = peer.quality;
                    const name = peerName(peer);

                    return (
                        <li
                            key={peer.ip}
                            className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-6 gap-y-3 p-4 sm:grid-cols-[minmax(0,1fr)_10rem_6rem_9rem] sm:px-5"
                        >
                            <span className="flex min-w-0 flex-col gap-0.5">
                                {peer.serverId !== null ? (
                                    <Link
                                        href={show(peer.serverId)}
                                        className="truncate type-label hover:underline"
                                    >
                                        {name}
                                    </Link>
                                ) : (
                                    <span className="truncate type-label">
                                        {name}
                                    </span>
                                )}
                                <span className="type-small text-muted-foreground">
                                    {pathLabels[peer.path]}
                                    {peer.path === 'relay' &&
                                        peer.relay !== null &&
                                        ` via ${peer.relay}`}
                                    {!peer.online && ' · offline'}
                                    <span className="font-mono">
                                        {' · '}
                                        {peer.ip}
                                    </span>
                                </span>
                                <Footnote peer={peer} />
                            </span>
                            <span className="hidden sm:block">
                                {q !== null && (
                                    <Sparkline
                                        values={q.latencyHistory}
                                        label={historyLabel(q.latencyHistory)}
                                        height={28}
                                        min={0}
                                    />
                                )}
                            </span>
                            <span className="justify-self-end">
                                <Latency peer={peer} />
                            </span>
                            <span className="col-span-2 sm:col-span-1 sm:justify-self-end">
                                <Rates peer={peer} serverName={serverName} />
                            </span>
                        </li>
                    );
                })}
            </ul>
        </Section>
    );
}
