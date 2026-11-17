import { Head } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { Meter } from '@/components/charts/usage-meter';
import { CopyValue } from '@/components/copy-value';
import { Callout } from '@/components/patterns/callout';
import { Status, StatusIcon, StatusPill } from '@/components/status';
import type { StatusState } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import {
    SettingsBody,
    SettingsCard,
    SettingsRow,
} from '@/components/settings/settings-card';
import SettingsLayout from '@/features/settings/settings-layout';
import { formatBytes, formatMemory } from '@/lib/format';

type System = App.Data.Instance.System.SystemHealthData;
type Check = App.Data.Instance.System.SystemCheckData;

const checkState: Record<Check['status'], StatusState> = {
    ok: 'active',
    warn: 'warning',
    fail: 'failed',
    skip: 'idle',
};

const serviceState: Record<string, { state: StatusState; label: string }> = {
    running: { state: 'active', label: 'Running' },
    unhealthy: { state: 'failed', label: 'Not healthy' },
    restarting: { state: 'progress', label: 'Restarting' },
    stopped: { state: 'idle', label: 'Stopped' },
    missing: { state: 'failed', label: 'Missing' },
};

const backupState: Record<string, { state: StatusState; label: string }> = {
    ok: { state: 'active', label: 'Backed up' },
    running: { state: 'progress', label: 'Backing up' },
    none: { state: 'idle', label: 'Scheduled' },
    stale: { state: 'warning', label: 'Overdue' },
    failing: { state: 'failed', label: 'Failing' },
    unconfigured: { state: 'idle', label: 'Off' },
};

function Summary({ checks }: { checks: Check[] }) {
    const failed = checks.filter((check) => check.status === 'fail').length;
    const warned = checks.filter((check) => check.status === 'warn').length;
    const [state, label]: [StatusState, string] =
        failed > 0
            ? [
                  'failed',
                  failed === 1
                      ? 'Something is broken'
                      : `${failed} things are broken`,
              ]
            : warned > 0
              ? [
                    'warning',
                    warned === 1
                        ? 'One thing needs a look'
                        : `${warned} things need a look`,
                ]
              : ['active', 'Everything is working'];

    return (
        <section className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center gap-3">
                <StatusPill state={state} label={label} />
                <span className="type-small text-muted-foreground">
                    The same checks run on the server with{' '}
                    <span className="type-mono">./launchroom doctor</span>
                </span>
            </div>

            {checks
                .filter(
                    (check) =>
                        check.status === 'fail' || check.status === 'warn',
                )
                .map((check) => (
                    <Callout
                        key={check.id}
                        tone={check.status === 'fail' ? 'error' : 'warning'}
                        title={check.title}
                    >
                        <div className="flex flex-col gap-2.5">
                            <p>{check.summary}</p>
                            {check.detail.length > 0 && (
                                <ul className="flex flex-col gap-0.5 type-small text-muted-foreground">
                                    {check.detail.map((line) => (
                                        <li key={line} className="break-words">
                                            {line}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {check.fix && (
                                <CopyValue
                                    value={`./launchroom doctor --fix ${check.fix}`}
                                    label="fix command"
                                />
                            )}
                        </div>
                    </Callout>
                ))}
        </section>
    );
}

function Services({ system }: { system: System }) {
    return (
        <SettingsCard
            title="Services"
            description={
                system.servicesCheckedAt ? (
                    <>
                        Checked by the main server{' '}
                        <TimeAgo value={system.servicesCheckedAt} />
                    </>
                ) : undefined
            }
        >
            {system.services === null ? (
                <SettingsBody>
                    <p className="type-body text-muted-foreground">
                        The main server shows its services here once it runs
                        this version of launchroom.
                    </p>
                </SettingsBody>
            ) : (
                <SettingsBody>
                    <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {system.services.map((service) => {
                            const shown = serviceState[service.state] ?? {
                                state: 'idle',
                                label: service.state,
                            };

                            return (
                                <li
                                    key={service.name}
                                    className="flex min-w-0 flex-col gap-1.5 rounded-xl border bg-card px-4 py-3"
                                >
                                    <span className="flex items-center gap-2 type-label">
                                        <StatusIcon state={shown.state} />
                                        <span className="truncate">
                                            {service.label}
                                        </span>
                                    </span>
                                    <span className="type-small text-muted-foreground">
                                        {shown.label}
                                        {service.state === 'running' &&
                                            service.startedAt && (
                                                <>
                                                    {', since '}
                                                    <TimeAgo
                                                        value={
                                                            service.startedAt
                                                        }
                                                    />
                                                </>
                                            )}
                                    </span>
                                    {(service.restarts > 0 ||
                                        service.oomKilled) && (
                                        <span className="type-small text-muted-foreground">
                                            {service.restarts > 0 &&
                                                `Restarted ${service.restarts} ${service.restarts === 1 ? 'time' : 'times'}`}
                                            {service.restarts > 0 &&
                                                service.oomKilled &&
                                                ', '}
                                            {service.oomKilled &&
                                                'last stopped for lack of memory'}
                                        </span>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                </SettingsBody>
            )}
        </SettingsCard>
    );
}

function Events({ events }: { events: System['events'] }) {
    if (events.length === 0) {
        return null;
    }

    return (
        <SettingsCard title="What the main server did on its own">
            {events.map((event) => (
                <SettingsRow
                    key={`${event.at}-${event.text}`}
                    label={
                        <span className="inline-flex items-start gap-2">
                            <StatusIcon
                                state={event.failed ? 'warning' : 'idle'}
                                className="mt-0.5"
                            />
                            <span>{event.text}</span>
                        </span>
                    }
                >
                    <span className="type-small text-muted-foreground">
                        <TimeAgo value={event.at} />
                    </span>
                </SettingsRow>
            ))}
        </SettingsCard>
    );
}

function Jobs({ system }: { system: System }) {
    const workers =
        system.workers === 'running'
            ? { state: 'active' as const, label: 'Running' }
            : system.workers === 'paused'
              ? { state: 'warning' as const, label: 'Paused' }
              : system.workers === 'stopped'
                ? { state: 'failed' as const, label: 'Not running' }
                : { state: 'idle' as const, label: 'Unknown' };

    return (
        <SettingsCard
            title="Background jobs"
            actions={
                system.horizonUrl ? (
                    <Button asChild variant="outline" size="sm">
                        <a href={system.horizonUrl}>
                            Job details
                            <ArrowUpRight aria-hidden="true" />
                        </a>
                    </Button>
                ) : undefined
            }
        >
            <SettingsRow label="Workers" inline>
                <Status state={workers.state} label={workers.label} />
            </SettingsRow>
            {system.queues === null ? (
                <SettingsRow
                    label="Queues"
                    description="Can’t be read while the cache is down."
                />
            ) : (
                system.queues.map((queue) => (
                    <SettingsRow
                        key={queue.name}
                        label={queue.label}
                        description={
                            queue.waitSeconds > 60
                                ? `The newest job waits about ${Math.round(queue.waitSeconds / 60)} min`
                                : undefined
                        }
                        inline
                    >
                        <span className="type-small text-muted-foreground tabular-nums">
                            {queue.waiting === 0
                                ? 'Nothing waiting'
                                : `${queue.waiting} waiting`}
                        </span>
                    </SettingsRow>
                ))
            )}
            <SettingsRow
                label="Failed in the last 24 hours"
                description={
                    system.failedJobs && system.failedJobs.length > 0
                        ? system.failedJobs
                              .map((job) => `${job.job} (${job.count})`)
                              .join(', ')
                        : undefined
                }
                inline
            >
                <span className="type-small text-muted-foreground tabular-nums">
                    {system.failedJobs === null
                        ? 'Unknown'
                        : system.failedJobs.reduce(
                              (sum, job) => sum + job.count,
                              0,
                          )}
                </span>
            </SettingsRow>
            <SettingsRow
                label="Scheduler"
                description={
                    system.staleLocks.length > 0
                        ? `Held up: ${system.staleLocks.join(', ')}`
                        : undefined
                }
                inline
            >
                <span className="type-small text-muted-foreground">
                    {system.schedulerBeatAt ? (
                        <>
                            Last ran <TimeAgo value={system.schedulerBeatAt} />
                        </>
                    ) : (
                        'Not running'
                    )}
                </span>
            </SettingsRow>
        </SettingsCard>
    );
}

function Storage({ system }: { system: System }) {
    const used =
        system.diskTotalMb !== null && system.diskFreeMb !== null
            ? system.diskTotalMb - system.diskFreeMb
            : null;

    return (
        <SettingsCard title="Storage on the main server">
            {system.diskTotalMb !== null && used !== null ? (
                <SettingsBody className="flex flex-col gap-2.5">
                    <div className="flex items-baseline justify-between gap-3">
                        <span className="type-label">Disk</span>
                        <span className="type-small text-muted-foreground tabular-nums">
                            {formatMemory(system.diskFreeMb)} free of{' '}
                            {formatMemory(system.diskTotalMb)}
                        </span>
                    </div>
                    <Meter
                        value={used}
                        max={system.diskTotalMb}
                        label="Disk used"
                        warnAt={90}
                        criticalAt={95}
                    />
                </SettingsBody>
            ) : (
                <SettingsRow label="Disk" description="Not reported yet." />
            )}
            <SettingsRow label="Database" inline>
                <span className="type-small text-muted-foreground tabular-nums">
                    {formatBytes(system.databaseBytes, 'Unknown')}
                </span>
            </SettingsRow>
            <SettingsRow label="Cache" inline>
                <span className="type-small text-muted-foreground tabular-nums">
                    {formatBytes(system.cacheBytes, 'Unknown')}
                </span>
            </SettingsRow>
            {system.registryMb !== null && (
                <SettingsRow label="App images" inline>
                    <span className="type-small text-muted-foreground tabular-nums">
                        {formatMemory(system.registryMb)}
                    </span>
                </SettingsRow>
            )}
        </SettingsCard>
    );
}

function Protection({ system }: { system: System }) {
    const backup = backupState[system.backupStatus] ?? backupState.none;

    return (
        <SettingsCard title="Protection">
            <SettingsRow
                label="Control-plane backup"
                description={
                    system.backupLastSuccessAt ? (
                        <>
                            Last good backup{' '}
                            <TimeAgo value={system.backupLastSuccessAt} />
                        </>
                    ) : system.backupStatus === 'unconfigured' ? (
                        'Add a destination under Backups to turn it on.'
                    ) : undefined
                }
                inline
            >
                <Status state={backup.state} label={backup.label} />
            </SettingsRow>
            {system.certificateHost && (
                <SettingsRow
                    label="Dashboard certificate"
                    description={system.certificateHost}
                    inline
                >
                    <span className="type-small text-muted-foreground">
                        {system.certificateExpiresAt
                            ? `Valid until ${new Date(system.certificateExpiresAt).toLocaleDateString()}`
                            : 'Not reported yet'}
                    </span>
                </SettingsRow>
            )}
        </SettingsCard>
    );
}

const checkWord: Record<Check['status'], string> = {
    ok: 'OK',
    warn: 'Needs a look',
    fail: 'Broken',
    skip: 'Not checked',
};

function Checks({ checks }: { checks: Check[] }) {
    return (
        <SettingsCard title="All checks">
            {checks.map((check) => (
                <SettingsRow
                    key={check.id}
                    label={check.title}
                    description={check.summary}
                    inline
                >
                    <Status
                        state={checkState[check.status]}
                        label={checkWord[check.status]}
                    />
                </SettingsRow>
            ))}
        </SettingsCard>
    );
}

function Versions({ system }: { system: System }) {
    const rows: [string, string | null][] = [
        ['launchroom', system.version],
        ['Main server', system.serverVersion],
        ['Database', system.databaseVersion],
        ['Cache', system.cacheVersion],
    ];

    return (
        <SettingsCard title="Versions">
            {rows.map(([label, version]) => (
                <SettingsRow key={label} label={label} inline>
                    <span className="type-mono text-muted-foreground">
                        {version ?? 'Unknown'}
                    </span>
                </SettingsRow>
            ))}
        </SettingsCard>
    );
}

/**
 * Settings → System (instance owners): how launchroom itself is doing on
 * the main server. What needs a look comes first, with the command that
 * fixes it; then services, background jobs, storage, protection, versions.
 */
export default function SystemSettings({ system }: { system: System }) {
    return (
        <>
            <Head title="System" />
            <Summary checks={system.checks} />
            <Services system={system} />
            <Events events={system.events} />
            <Jobs system={system} />
            <Storage system={system} />
            <Protection system={system} />
            <Checks checks={system.checks} />
            <Versions system={system} />
        </>
    );
}

SystemSettings.layout = SettingsLayout;
