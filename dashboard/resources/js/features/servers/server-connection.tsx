import type { ReactNode } from 'react';
import { Section } from '@/components/patterns/section';
import { Status } from '@/components/status';
import type { StatusState } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-1">
            <dt className="type-small text-muted-foreground">{label}</dt>
            <dd className="min-w-0 type-body break-words">{children}</dd>
        </div>
    );
}

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

/** Public traffic on an edge: one quiet card, only for edges. */
export function EdgeTraffic({
    edge,
}: {
    edge: App.Data.Ingress.EdgeIngressStatusData;
}) {
    const routes = edge.state ? routeStates[edge.state] : undefined;
    const storage = edge.storage ? storageStates[edge.storage] : undefined;

    return (
        <Section title="Public traffic">
            <dl className="grid grid-cols-2 gap-x-6 gap-y-5 rounded-xl border bg-card p-6 shadow-card sm:grid-cols-4">
                <Fact label="Public address">
                    <span className="type-mono">
                        {edge.publicIpv4 ?? edge.publicIpv6 ?? 'Unknown'}
                    </span>
                </Fact>
                <Fact label="Routes">
                    {routes ? (
                        <Status
                            state={routes.state}
                            label={
                                edge.routes !== null
                                    ? `${edge.routes} applied`
                                    : routes.label
                            }
                        />
                    ) : (
                        <span className="text-muted-foreground">
                            Not reported
                        </span>
                    )}
                </Fact>
                <Fact label="Certificates">
                    {storage ? (
                        <Status state={storage.state} label={storage.label} />
                    ) : (
                        <span className="text-muted-foreground">Unknown</span>
                    )}
                </Fact>
                <Fact label="Reported">
                    <TimeAgo value={edge.reportedAt} />
                </Fact>
            </dl>
            {edge.message && (
                <p className="type-small text-muted-foreground">
                    {edge.message}
                </p>
            )}
        </Section>
    );
}

/**
 * Connection details (direction §9.2) on the Network tab: versions, keys,
 * the desired state and its addresses.
 */
export function ServerConnection({
    server,
    mesh,
    replacedBy,
}: {
    server: App.Data.Servers.ServerDetailData;
    mesh: App.Data.Servers.ServerMeshData;
    replacedBy: string | null;
}) {
    return (
        <Section
            title="Connection"
            description="What this server reports about itself."
        >
            <div className="rounded-xl border bg-card shadow-card">
                <dl className="grid grid-cols-2 gap-x-6 gap-y-5 p-6 md:grid-cols-4">
                    <Fact label="Server version">
                        <span className="type-mono">
                            {server.daemonVersion ?? 'Unknown'}
                        </span>
                    </Fact>
                    <Fact label="Protocol">
                        <span className="type-mono">
                            {server.protocolVersion ?? 'Unknown'}
                        </span>
                    </Fact>
                    <Fact label="Last report">
                        <TimeAgo value={server.lastSeenAt} />
                    </Fact>
                    <Fact label="Desired state">
                        <span className="tabular-nums">
                            v{server.observedVersion} of v
                            {server.desiredVersion} applied
                        </span>
                    </Fact>
                    <Fact label="Hostname">
                        <span className="type-mono">
                            {server.facts.hostname ?? 'Unknown'}
                        </span>
                    </Fact>
                    <Fact label="Platform">
                        <span className="type-mono">
                            {server.facts.os ?? '?'}/{server.facts.arch ?? '?'}
                        </span>
                    </Fact>
                    <Fact label="Private address">
                        <span className="type-mono">
                            {mesh.ip ?? 'None yet'}
                        </span>
                    </Fact>
                    <Fact label="Approved">
                        <TimeAgo value={server.approvedAt} fallback="Not yet" />
                    </Fact>
                    {server.revokedAt && (
                        <Fact label="Revoked">
                            <TimeAgo value={server.revokedAt} />
                        </Fact>
                    )}
                    {replacedBy !== null && (
                        <Fact label="Replaced by">{replacedBy}</Fact>
                    )}
                    <div className="col-span-2 md:col-span-4">
                        <Fact label="Signing key">
                            <span className="type-mono-small break-all">
                                {server.signingKeyFingerprint ?? 'Unknown'}
                            </span>
                        </Fact>
                    </div>
                </dl>
            </div>
        </Section>
    );
}
