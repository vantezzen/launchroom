import { Ban, Cpu, Globe, Hammer, Info, TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { Section } from '@/components/patterns/section';
import { formatBytes } from '@/features/backups/format';
import { formatDuration } from '@/features/moves/format';

function Note({
    icon,
    children,
    tone = 'neutral',
}: {
    icon: ReactNode;
    children: ReactNode;
    tone?: 'neutral' | 'warning' | 'blocker';
}) {
    return (
        <div
            className={
                tone === 'blocker'
                    ? 'flex items-start gap-3 rounded-xl border border-status-error/40 bg-status-error-soft px-4 py-3'
                    : tone === 'warning'
                      ? 'flex items-start gap-3 rounded-xl border bg-status-progress-soft px-4 py-3'
                      : 'flex items-start gap-3 rounded-xl border bg-card px-4 py-3'
            }
        >
            <span className="mt-0.5 shrink-0">{icon}</span>
            <p className="min-w-0 type-small">{children}</p>
        </div>
    );
}

/**
 * What a move does (docs/14 §13.2): what stops it, what to know, the
 * services without downtime and the ones that stop briefly.
 */
export function MovePlanView({ plan }: { plan: App.Data.Moves.MovePlanData }) {
    const stateless = plan.items.filter((item) => item.kind === 'stateless');
    const stateful = plan.items.filter((item) => item.kind !== 'stateless');
    const target = plan.target?.name ?? 'the new server';

    return (
        <div className="flex flex-col gap-8">
            {(plan.blockers.length > 0 ||
                plan.warnings.length > 0 ||
                plan.platformTo !== null ||
                plan.addsEdge) && (
                <div className="flex flex-col gap-2">
                    {plan.blockers.map((blocker) => (
                        <Note
                            key={blocker}
                            tone="blocker"
                            icon={
                                <Ban
                                    aria-hidden="true"
                                    className="size-4 text-status-error"
                                />
                            }
                        >
                            <span className="sr-only">Blocks the move: </span>
                            {blocker}
                        </Note>
                    ))}
                    {plan.platformTo !== null && (
                        <Note
                            icon={
                                <Cpu
                                    aria-hidden="true"
                                    className="size-4 text-muted-foreground"
                                />
                            }
                        >
                            {plan.source.name} runs {plan.platformFrom},{' '}
                            {target} runs {plan.platformTo}. Apps built from
                            source are built again for {plan.platformTo} before
                            they switch over. Databases and registry images are
                            pulled for {plan.platformTo}; an image that only
                            exists for {plan.platformFrom} can’t move.
                        </Note>
                    )}
                    {plan.addsEdge && (
                        <Note
                            icon={
                                <Globe
                                    aria-hidden="true"
                                    className="size-4 text-muted-foreground"
                                />
                            }
                        >
                            {target} starts serving traffic first, so your
                            domains keep working while you change DNS.
                        </Note>
                    )}
                    {plan.warnings.map((warning) => (
                        <Note
                            key={warning}
                            tone="warning"
                            icon={
                                <TriangleAlert
                                    aria-hidden="true"
                                    className="size-4 text-status-progress"
                                />
                            }
                        >
                            {warning}
                        </Note>
                    ))}
                </div>
            )}

            <Section
                title="Moves without downtime"
                description="Starts on the new server, then the old copy stops."
            >
                {stateless.length === 0 ? (
                    <p className="type-body text-muted-foreground">
                        Nothing here.
                    </p>
                ) : (
                    <ul className="flex flex-col divide-y rounded-xl border bg-card shadow-card">
                        {stateless.map((item) => (
                            <li
                                key={item.instanceId}
                                className="flex flex-wrap items-center justify-between gap-2 px-5 py-3"
                            >
                                <span className="min-w-0 type-body break-words">
                                    {item.name}
                                </span>
                                {item.rebuild && (
                                    <RebuildBadge platform={plan.platformTo} />
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Section>

            <Section
                title="Stops briefly"
                description="One at a time: its apps stop, the data is copied over, then everything starts on the new server."
            >
                {stateful.length === 0 ? (
                    <p className="type-body text-muted-foreground">
                        No databases or volumes live here.
                    </p>
                ) : (
                    <ul className="flex flex-col divide-y rounded-xl border bg-card shadow-card">
                        {stateful.map((item) => (
                            <li
                                key={item.instanceId}
                                className="flex flex-col gap-1 px-5 py-4"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="min-w-0 type-body break-words">
                                        {item.name}
                                    </span>
                                    <span className="type-small tabular-nums">
                                        {item.downtimeSeconds === null
                                            ? 'Down for a few minutes'
                                            : `Down ${formatDuration(item.downtimeSeconds)}`}
                                    </span>
                                </div>
                                <span className="flex flex-wrap items-center gap-x-3 gap-y-1 type-small text-muted-foreground">
                                    <span>{item.engine ?? 'Files'}</span>
                                    <span>
                                        {item.expectedBytes === null
                                            ? 'size unknown'
                                            : formatBytes(item.expectedBytes)}
                                    </span>
                                    {item.rebuild && (
                                        <RebuildBadge
                                            platform={plan.platformTo}
                                        />
                                    )}
                                </span>
                                {item.stops.length > 0 && (
                                    <span className="type-small text-muted-foreground">
                                        Stops: {item.stops.join(', ')}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Section>

            {stateful.length > 0 && (
                <Note
                    icon={
                        <Info
                            aria-hidden="true"
                            className="size-4 text-muted-foreground"
                        />
                    }
                >
                    {plan.downtimeSeconds === null
                        ? 'Some sizes are unknown, so the total downtime can’t be estimated.'
                        : `Total downtime: ${formatDuration(plan.downtimeSeconds)}, spread over ${stateful.length} ${stateful.length === 1 ? 'service' : 'services'}.`}
                    {plan.copyBytes !== null &&
                        ` ${formatBytes(plan.copyBytes)} to copy.`}{' '}
                    Estimates assume about 20 MB/s between the servers.
                </Note>
            )}
        </div>
    );
}

function RebuildBadge({ platform }: { platform: string | null }) {
    return (
        <span className="inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 type-small text-muted-foreground">
            <Hammer aria-hidden="true" className="size-3" />
            Rebuilt for {platform ?? 'the new server'}
        </span>
    );
}
