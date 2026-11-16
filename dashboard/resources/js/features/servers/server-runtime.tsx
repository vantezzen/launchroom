import { labels as wordsOf, vocabulary } from '@/lib/vocabulary';
import { TriangleAlert } from 'lucide-react';
import { Section } from '@/components/patterns/section';
import { Status } from '@/components/status';
import type { StatusState } from '@/components/status';

type Runtime = App.Data.Servers.ServerRuntimeData;
type Row = App.Data.Servers.CapabilityRowData;
type Capability = App.Enums.Servers.RuntimeCapability;
type CapabilityState = App.Enums.Servers.CapabilityState;

/** Row labels, in display order. */
const labels: Record<Capability, string> = {
    dockerAccess: 'Server access',
    edgePorts: 'Public ports 80/443',
    clientAddresses: 'Visitor addresses',
    firewall: 'Network isolation',
    mesh: 'Private network',
    portDiscovery: 'Port detection',
    workloads: 'Apps',
    builds: 'Builds',
};

const order = Object.keys(labels) as Capability[];

const states: Record<CapabilityState, { state: StatusState; label: string }> = {
    full: { state: 'active', label: 'Full' },
    degraded: { state: 'warning', label: 'Limited' },
    unavailable: { state: 'failed', label: 'Unavailable' },
};

/** What a limited capability means for the apps (the warning callout). */
const consequences: Record<Capability, string> = {
    dockerAccess:
        'launchroom has more access to this server than it needs. Run the install command on the server again to limit it.',
    edgePorts:
        'Ports 80 and 443 can’t be opened directly, so public traffic may not reach this server.',
    clientAddresses:
        'Apps see the server’s own address instead of their visitors’ addresses.',
    firewall:
        'The private network’s firewall is limited, so environments on this server are not fully isolated from each other.',
    mesh: 'The private network to your other servers is limited.',
    portDiscovery:
        'launchroom can’t detect which port an app listens on. Set the port in the service’s settings.',
    workloads:
        'Apps here can’t be reached from your other servers, so this server only builds.',
    builds: 'Ubuntu’s user-namespace restriction stops builds here. Run the launchroom installer on this server again and allow launchroom’s build profile, or run the command shown in the Builds row.',
};

const engines = wordsOf(vocabulary.Servers.DockerEngine);

/** "OrbStack on macOS", "Rootless Docker", "Docker" (Network → Connection). */
export function runtimeTitle(runtime: Runtime): string {
    if (runtime.docker === 'rootless') {
        return 'Rootless Docker';
    }

    const engine = engines[runtime.engine ?? 'docker-engine'];

    return runtime.hostOs === 'darwin' ? `${engine} on macOS` : engine;
}

function LimitedCallout({
    rootless,
    mac,
    limited,
}: {
    rootless: boolean;
    mac: boolean;
    limited: Row[];
}) {
    const accessOnly =
        limited.length === 1 && limited[0].name === 'dockerAccess';

    return (
        <div
            role="note"
            className="flex items-start gap-3.5 rounded-xl border bg-card px-4 py-3.5"
        >
            <span
                aria-hidden="true"
                className="flex size-7 shrink-0 items-center justify-center rounded-lg bg-recessed text-status-progress"
            >
                <TriangleAlert className="size-3.5" />
            </span>
            <div className="flex min-w-0 flex-col gap-1">
                <p className="type-body font-medium">
                    <span className="sr-only">Heads up: </span>
                    {accessOnly
                        ? 'This server gives launchroom more access than it needs.'
                        : rootless
                          ? 'Rootless mode limits some features on this server.'
                          : mac
                            ? 'Apps run in a Linux VM on this Mac, which limits some features.'
                            : 'Some features are limited on this server.'}
                </p>
                {accessOnly ? (
                    <p className="type-small text-muted-foreground">
                        Run the install command on the server again to limit it.
                    </p>
                ) : (
                    <ul className="flex flex-col gap-0.5 type-small text-muted-foreground">
                        {limited.map((capability) => (
                            <li key={capability.name}>
                                <span className="text-foreground">
                                    {labels[capability.name]}:
                                </span>{' '}
                                {consequences[capability.name]}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}

/**
 * What launchroom can do on the server (D49) on the Network tab: one row
 * per capability with icon + word and the dashboard-worded explanation
 * (CapabilityReason), and one callout when something is limited. How the
 * engine runs is a fact under Connection.
 */
export function ServerRuntime({
    runtime,
    capabilities: rows,
}: {
    runtime: Runtime | null;
    capabilities: Row[];
}) {
    if (runtime === null) {
        return (
            <Section title="What this server can do">
                <div className="rounded-xl border bg-card px-6 py-5 shadow-card">
                    <p className="type-body text-muted-foreground">
                        Reported once the server checks in.
                    </p>
                </div>
            </Section>
        );
    }

    const rootless = runtime.docker === 'rootless';
    const mac = runtime.hostOs === 'darwin';
    const capabilities = [...rows].sort(
        (a, b) => order.indexOf(a.name) - order.indexOf(b.name),
    );
    const limited = capabilities.filter((c) => c.state !== 'full');

    return (
        <Section title="What this server can do">
            {limited.length > 0 && (
                <LimitedCallout
                    rootless={rootless}
                    mac={mac}
                    limited={limited}
                />
            )}
            <div className="rounded-xl border bg-card shadow-card">
                {capabilities.length === 0 ? (
                    <p className="px-6 py-5 type-body text-muted-foreground">
                        No capabilities reported yet.
                    </p>
                ) : (
                    <ul className="divide-y">
                        {capabilities.map((capability) => {
                            const state = states[capability.state];

                            return (
                                <li
                                    key={capability.name}
                                    className="flex items-start justify-between gap-4 px-6 py-3.5"
                                >
                                    <div className="flex min-w-0 flex-col gap-0.5">
                                        <p className="type-body">
                                            {labels[capability.name]}
                                        </p>
                                        {capability.explanation &&
                                            !(
                                                capability.name ===
                                                    'dockerAccess' &&
                                                capability.state !== 'full'
                                            ) && (
                                                <p className="type-small break-words text-muted-foreground">
                                                    {capability.explanation}
                                                </p>
                                            )}
                                    </div>
                                    <Status
                                        state={state.state}
                                        label={state.label}
                                        className="shrink-0"
                                    />
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>
        </Section>
    );
}
