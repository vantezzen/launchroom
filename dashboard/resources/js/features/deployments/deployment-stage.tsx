import { Link } from '@inertiajs/react';
import { Hammer, Hourglass, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { Duration, Elapsed } from '@/components/elapsed';
import { Logo } from '@/components/logo';
import { MetaLine } from '@/components/patterns/meta-line';
import { StatusIcon } from '@/components/status';
import type { StatusState } from '@/components/status';
import { StepIcon, STEP_STATUS_LABEL } from '@/components/steps/step-icon';
import {
    deploymentWord,
    isInProgress,
    isServing,
    stoppedLine,
} from '@/features/deployments/format';
import { SECTION_LABELS } from '@/features/logs/sections';
import { deviceKind, deviceStatus } from '@/features/servers/format';
import { useNow } from '@/hooks/use-now';
import { cn } from '@/lib/utils';
import { show as deploymentShow } from '@/routes/deployments';
import { show as serverPage } from '@/routes/servers';
import { refName } from './deployment-ref';
import { stepRows } from './steps';
import type { StepRow } from './steps';
import { formatDuration, relativeTime, plural } from '@/lib/format';
import { DeviceStage } from '@/components/devices/device-stage';

type Detail = App.Data.Deployments.DeploymentDetailData;
type Replica = App.Data.Deployments.DeploymentReplicaData;
type Server = App.Data.Deployments.DeploymentServerData;

const MAX_SERVERS = 3;

/** Short step names for the timeline's label column. */
const STEP_LABELS: Record<string, string> = {
    ...SECTION_LABELS,
    release: 'Release',
};

/**
 * The overview card's stage while a deployment is under way (docs/08 §12,
 * design-system §13.9 "Deployment detail"): what is happening, as a
 * picture, for every kind of service.
 *
 * - **Where it runs** while its replicas come up (rolling out, ready): the
 *   servers as device renders with one logo tile per replica standing on
 *   them (solid when running, dashed amber while starting, red with ✕ when
 *   failing).
 * - **The build server** while it builds.
 * - Then the steps as one segmented bar (`StepBar`) and a footer with how
 *   long it has been running against the typical deploy.
 *
 * Finished deployments have no stage: `DeploymentSummaryStrip` puts the
 * headline, the outcome and the bar across the top of the card (DPL-1).
 */
export function DeploymentStage({ detail }: { detail: Detail }) {
    const rows = stepRows(detail);
    const scene = sceneFor(detail, rows);

    return (
        <div className="flex min-w-0 flex-col overflow-hidden rounded-[10px] border bg-recessed lg:h-full lg:min-h-60">
            {scene ?? <StoryCaption detail={detail} rows={rows} />}
            <StepBar
                detail={detail}
                rows={rows}
                className={cn(
                    'px-4',
                    scene ? 'pt-1 pb-3' : 'flex-1 justify-center py-4',
                )}
            />
            <Outcome detail={detail} rows={rows} />
        </div>
    );
}

/**
 * A finished deployment's run in one strip (DPL-1): what happened ("Live on
 * hetzner-1", "Stopped at Health check", "Replaced by c663a95"), how long
 * it took, and the steps as one segmented bar. The Steps section is the
 * one per-step list.
 */
export function DeploymentSummaryStrip({ detail }: { detail: Detail }) {
    const rows = stepRows(detail);

    return (
        <div className="flex flex-col gap-3 border-b px-5 py-4">
            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                <StoryCaption detail={detail} rows={rows} bare />
                <Outcome detail={detail} rows={rows} bare />
            </div>
            <StepBar detail={detail} rows={rows} />
        </div>
    );
}

function Caption({
    state,
    children,
    right,
    bare = false,
}: {
    state: StatusState;
    children: ReactNode;
    right?: ReactNode;
    /** In the summary strip: no padding of its own. */
    bare?: boolean;
}) {
    return (
        <div
            className={cn(
                'flex min-w-0 items-center justify-between gap-3 type-small',
                !bare && 'px-4 pt-3.5',
            )}
        >
            <span className="flex min-w-0 items-center gap-1.5 font-medium text-foreground">
                <StatusIcon state={state} className="size-3" />
                <span className="truncate">{children}</span>
            </span>
            {right && (
                <span className="shrink-0 text-muted-foreground tabular-nums">
                    {right}
                </span>
            )}
        </div>
    );
}

/** The step that decided the outcome: the failed one, else the last that ran. */
function decidingStep(rows: StepRow[]): StepRow | null {
    return (
        rows.find((row) => row.status === 'failed') ??
        [...rows].reverse().find((row) => row.startedAt !== null) ??
        null
    );
}

/** The physical picture, when there is one; null leaves the stage to the timeline. */
function sceneFor(detail: Detail, rows: StepRow[]): ReactNode | null {
    const deployment = detail.deployment;
    const status = deployment.status;
    const failed = rows.find((row) => row.status === 'failed');

    if (detail.servers.length > 0 && status !== 'building') {
        return <ServersScene detail={detail} />;
    }

    if (status === 'queued' || (status === 'building' && !detail.buildServer)) {
        return (
            <div className="flex flex-col">
                <Caption
                    state="progress"
                    right={<Elapsed since={deployment.queuedAt} />}
                >
                    {/* A push held back until its commit's checks pass (D111). */}
                    {detail.checks?.state === 'waiting'
                        ? 'Waiting for checks'
                        : 'Waiting for a build server'}
                </Caption>
                <div className="flex h-[132px] items-center justify-center">
                    <span className="flex h-[76px] w-[124px] items-center justify-center rounded-xl border border-dashed border-border-strong text-muted-foreground">
                        <Hourglass aria-hidden="true" className="size-5" />
                    </span>
                </div>
            </div>
        );
    }

    if (status === 'building' && detail.buildServer) {
        return (
            <BuildServerScene
                server={detail.buildServer}
                failed={false}
                caption={
                    <Caption
                        state="progress"
                        right={
                            deployment.startedAt && (
                                <Elapsed since={deployment.startedAt} />
                            )
                        }
                    >
                        Building on {detail.buildServer.name}
                    </Caption>
                }
            />
        );
    }

    if (
        status === 'failed' &&
        detail.buildServer &&
        (failed?.section === 'build' || failed?.section === 'plan')
    ) {
        return (
            <BuildServerScene
                server={detail.buildServer}
                failed
                caption={
                    <Caption state="failed">
                        {failed.section === 'plan' ? 'Planning' : 'Build'}{' '}
                        failed on {detail.buildServer.name}
                    </Caption>
                }
            />
        );
    }

    return null;
}

/** The headline when the stage is the timeline alone. */
function StoryCaption({
    detail,
    rows,
    bare = false,
}: {
    detail: Detail;
    rows: StepRow[];
    bare?: boolean;
}) {
    const deployment = detail.deployment;
    const step = decidingStep(rows);
    const where = step ? STEP_LABELS[step.section] : null;
    const servers =
        detail.servers.length === 0
            ? null
            : detail.servers.length === 1
              ? detail.servers[0].name
              : plural(detail.servers.length, 'server');

    switch (deployment.status) {
        case 'failed':
            return (
                <Caption state="failed" bare={bare}>
                    {where ? `Stopped at ${where}` : 'Failed before it started'}
                </Caption>
            );
        case 'cancelled':
            return (
                <Caption state="idle" bare={bare}>
                    {where
                        ? `Canceled during ${where}`
                        : 'Canceled before it started'}
                </Caption>
            );
        case 'superseded':
            return (
                <Caption state="idle" bare={bare}>
                    {detail.supersededBy ? (
                        <>
                            Replaced by{' '}
                            <DeploymentLink
                                detail={detail}
                                target={detail.supersededBy}
                                fallback="a newer deployment"
                                sameCommit="a redeploy"
                            />
                        </>
                    ) : (
                        'Superseded'
                    )}
                </Caption>
            );
        default:
            return (
                <Caption
                    bare={bare}
                    state={
                        deployment.stopped
                            ? 'idle'
                            : isServing(deployment) ||
                                deployment.status === 'active'
                              ? 'active'
                              : 'progress'
                    }
                >
                    {servers && !deployment.stopped
                        ? `${deploymentWord(deployment)} on ${servers}`
                        : deploymentWord(deployment)}
                </Caption>
            );
    }
}

/**
 * Another deployment of this service as a link: its short sha, or, when it
 * built the same commit, `sameCommit` words with its time ("a redeploy 13
 * hours ago"), so a redeploy never reads as replacing itself.
 */
function DeploymentLink({
    detail,
    target,
    fallback,
    sameCommit,
}: {
    detail: Detail;
    target: App.Data.Deployments.DeploymentRefData;
    fallback: string;
    sameCommit: string;
}) {
    const name = refName(detail.deployment, target);

    return (
        <Link
            href={
                deploymentShow({
                    project: detail.deployment.projectId,
                    environment: detail.deployment.environmentId,
                    deployment: target.id,
                }).url
            }
            className={cn(
                'text-foreground underline decoration-border-strong underline-offset-4 hover:decoration-foreground',
                name.kind === 'sha' && 'type-mono-small',
            )}
        >
            {name.kind === 'sha'
                ? name.sha
                : name.kind === 'same-commit'
                  ? `${sameCommit} ${relativeTime(name.at)}`
                  : fallback}
        </Link>
    );
}

/** The build server with what it is doing standing on it. */
function BuildServerScene({
    server,
    failed,
    caption,
}: {
    server: App.Data.Deployments.BuildServerData;
    failed: boolean;
    caption: ReactNode;
}) {
    return (
        <div className="flex flex-col">
            {caption}
            <Link
                href={serverPage(server.id)}
                aria-label={`Build server ${server.name}`}
                className="focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-signal"
            >
                <DeviceStage
                    device={server.device.kind}
                    status={failed ? 'online' : 'busy'}
                    size={148}
                    className="h-[132px] bg-transparent"
                >
                    <span
                        className={cn(
                            'flex size-8 items-center justify-center rounded-[9px] border shadow-card',
                            failed
                                ? 'border-status-error bg-status-error-soft text-status-error'
                                : 'border-dashed border-status-progress bg-status-progress-soft text-status-progress',
                        )}
                    >
                        {failed ? (
                            <X aria-hidden="true" className="size-4" />
                        ) : (
                            <Hammer aria-hidden="true" className="size-4" />
                        )}
                    </span>
                </DeviceStage>
            </Link>
        </div>
    );
}

const REPLICA_TONE: Record<string, string> = {
    running: 'shadow-card',
    starting:
        'border-dashed border-status-progress bg-status-progress-soft text-status-progress',
    failed: 'border-status-error bg-status-error-soft text-status-error',
    stopping: 'border-dashed opacity-50',
};

function ReplicaTile({
    replica,
    logo,
    size,
}: {
    replica: Replica;
    logo: string;
    size: 20 | 32;
}) {
    return (
        <span
            className="relative"
            title={`Replica ${replica.number}: ${replica.label}`}
        >
            <Logo
                kind={logo}
                size={size}
                decorative
                className={cn(
                    'motion-safe:animate-in motion-safe:duration-base motion-safe:ease-enter motion-safe:zoom-in-75',
                    REPLICA_TONE[replica.state],
                )}
            />
            {replica.state === 'failed' && (
                <span className="absolute -top-1.5 -right-1.5 flex size-3.5 items-center justify-center rounded-full border-2 border-recessed bg-status-error text-white">
                    <X aria-hidden="true" className="size-2" strokeWidth={3} />
                </span>
            )}
        </span>
    );
}

/** "2 running, 1 starting" for a server's replicas. */
function replicaSummary(replicas: Replica[]): string {
    const words: Record<string, string> = {
        running: 'running',
        starting: 'starting',
        failed: 'failing',
        stopping: 'stopping',
    };
    const counts = new Map<string, number>();

    for (const replica of replicas) {
        counts.set(replica.state, (counts.get(replica.state) ?? 0) + 1);
    }

    return Array.from(
        counts,
        ([state, count]) => `${count} ${words[state]}`,
    ).join(', ');
}

function ServersScene({ detail }: { detail: Detail }) {
    const deployment = detail.deployment;
    const servers = detail.servers;
    const shown = servers.slice(0, MAX_SERVERS);
    const replicas = servers.flatMap((server) => server.replicas);
    const failing = replicas.some((replica) => replica.state === 'failed');
    const starting = replicas.some((replica) => replica.state === 'starting');
    const live =
        deployment.stopped === null &&
        (deployment.isActive || deployment.status === 'active');
    const where =
        servers.length === 1
            ? servers[0].name
            : plural(servers.length, 'server');
    const headline =
        deployment.status === 'ready'
            ? `Ready on ${where}`
            : isInProgress(deployment.status)
              ? `Rolling out on ${where}`
              : `${deploymentWord(deployment)} on ${where}`;
    const most = Math.max(...shown.map((server) => server.replicas.length));
    const tile: 20 | 32 = shown.length <= 2 && most <= 3 ? 32 : 20;
    const size = shown.length === 1 ? 168 : shown.length === 2 ? 136 : 104;

    return (
        <div className="flex flex-col">
            <Caption
                state={
                    failing
                        ? 'failed'
                        : starting || !live
                          ? 'progress'
                          : 'active'
                }
                right={plural(replicas.length, 'replica')}
            >
                {headline}
            </Caption>
            <div
                role="img"
                aria-label={servers
                    .map(
                        (server) =>
                            `${server.name}: ${server.replicas.map((replica) => `replica ${replica.number} ${replica.label.toLowerCase()}`).join(', ')}`,
                    )
                    .join('; ')}
                className="grid items-end gap-2 px-2 pt-2 pb-1"
                style={{
                    gridTemplateColumns: `repeat(${shown.length}, minmax(0, 1fr))`,
                }}
            >
                {shown.map((server) => (
                    <ServerColumn
                        key={server.id}
                        server={server}
                        logo={detail.logo}
                        size={size}
                        tile={tile}
                    />
                ))}
            </div>
            {servers.length > MAX_SERVERS && (
                <p className="px-4 pb-1 text-center type-small text-muted-foreground">
                    and {plural(servers.length - MAX_SERVERS, 'more server')}
                </p>
            )}
        </div>
    );
}

function ServerColumn({
    server,
    logo,
    size,
    tile,
}: {
    server: Server;
    logo: string;
    size: number;
    tile: 20 | 32;
}) {
    return (
        <Link
            href={serverPage(server.id)}
            className="flex min-w-0 flex-col items-center gap-0.5 rounded-lg pb-1 focus-visible:outline-2 focus-visible:outline-signal"
        >
            <DeviceStage
                device={deviceKind(server)}
                status={deviceStatus(server.status)}
                size={size}
                className="h-auto w-full bg-transparent pt-7"
            >
                <span className="flex flex-wrap justify-center gap-1.5">
                    {server.replicas.map((replica) => (
                        <ReplicaTile
                            key={replica.number}
                            replica={replica}
                            logo={logo}
                            size={tile}
                        />
                    ))}
                </span>
            </DeviceStage>
            <span className="max-w-full truncate type-small font-medium">
                {server.name}
            </span>
            <span className="max-w-full truncate type-small text-muted-foreground">
                {replicaSummary(server.replicas)}
            </span>
        </Link>
    );
}

function median(values: number[]): number | null {
    if (values.length === 0) {
        return null;
    }

    const sorted = [...values].sort((a, b) => a - b);
    const middle = Math.floor(sorted.length / 2);

    return sorted.length % 2 === 0
        ? (sorted[middle - 1] + sorted[middle]) / 2
        : sorted[middle];
}

/** The service's typical deploy (median of its finished, successful ones). */
function typicalSeconds(detail: Detail): number | null {
    return median(
        detail.buildHistory
            .filter(
                (entry) =>
                    entry.status === 'active' || entry.status === 'superseded',
            )
            .map((entry) => entry.durationSeconds),
    );
}

const BAR_TONE: Partial<Record<string, string>> = {
    succeeded: 'bg-foreground/35',
    running: 'bg-status-progress',
    failed: 'bg-status-error',
    canceled: 'bg-status-idle',
};

type Segment = { row: StepRow; seconds: number };

/**
 * The steps as one segmented bar (DPL-1): each step that ran is a segment
 * as wide as it took (at least a sliver, so instant steps still show). The
 * failed or running step is named under its segment with its icon, so the
 * state never rests on the colour alone. The Steps section below is the
 * one per-step list; this is the shape of the run at a glance. While it
 * runs, a quiet remainder stands for the rest of the service's typical
 * deploy.
 */
export function StepBar({
    detail,
    rows,
    className,
}: {
    detail: Detail;
    rows: StepRow[];
    className?: string;
}) {
    const deployment = detail.deployment;
    const running =
        isInProgress(deployment.status) && deployment.status !== 'ready';
    const now = useNow(running);
    const segments: Segment[] = rows
        .filter((row) => row.startedAt !== null)
        .map((row) => ({
            row,
            seconds:
                row.status === 'running' && row.startedAt
                    ? Math.max(0, (now - Date.parse(row.startedAt)) / 1000)
                    : (row.seconds ?? 0),
        }));

    if (segments.length === 0) {
        return null;
    }

    const ran = segments.reduce((sum, segment) => sum + segment.seconds, 0);
    const typical = running ? typicalSeconds(detail) : null;
    const remainder =
        typical !== null && typical > ran
            ? typical - ran
            : running
              ? ran * 0.15
              : 0;
    const total = Math.max(1, ran + remainder);
    const grow = (seconds: number) => Math.max(seconds, total * 0.015);
    const marked = segments.find(
        (segment) =>
            segment.row.status === 'failed' || segment.row.status === 'running',
    );
    const label = segments
        .map(
            (segment) =>
                `${STEP_LABELS[segment.row.section]} ${STEP_STATUS_LABEL[segment.row.status].toLowerCase()}, ${formatDuration(Math.round(segment.seconds))}`,
        )
        .join('; ');

    return (
        <div className={cn('flex min-w-0 flex-col gap-1.5', className)}>
            <div
                role="img"
                aria-label={`Steps: ${label}`}
                className="flex h-2 w-full gap-0.5"
            >
                {segments.map((segment) => (
                    <span
                        key={segment.row.section}
                        title={`${STEP_LABELS[segment.row.section]}: ${formatDuration(Math.round(segment.seconds))}`}
                        className={cn(
                            'min-w-1 rounded-full',
                            BAR_TONE[segment.row.status] ?? 'bg-foreground/25',
                        )}
                        style={{
                            flexGrow: grow(segment.seconds),
                            flexBasis: 0,
                        }}
                    />
                ))}
                {remainder > 0 && (
                    <span
                        aria-hidden="true"
                        className="rounded-full bg-hover"
                        style={{ flexGrow: remainder, flexBasis: 0 }}
                    />
                )}
            </div>
            {
                <div aria-hidden="true" className="flex w-full gap-0.5">
                    {segments.map((segment) => {
                        const at =
                            segments
                                .slice(0, segments.indexOf(segment))
                                .reduce(
                                    (sum, item) => sum + grow(item.seconds),
                                    0,
                                ) / total;

                        return (
                            <span
                                key={segment.row.section}
                                className={cn(
                                    'flex min-w-1 type-small whitespace-nowrap',
                                    at > 0.6 && 'justify-end',
                                )}
                                style={{
                                    flexGrow: grow(segment.seconds),
                                    flexBasis: 0,
                                }}
                            >
                                {segment !== marked &&
                                    !marked &&
                                    grow(segment.seconds) / total >= 0.18 && (
                                        <span className="truncate text-muted-foreground tabular-nums">
                                            {STEP_LABELS[segment.row.section]}{' '}
                                            {formatDuration(
                                                Math.round(segment.seconds),
                                            )}
                                        </span>
                                    )}
                                {segment === marked && (
                                    <span
                                        className={cn(
                                            'inline-flex items-center gap-1',
                                            segment.row.status === 'failed'
                                                ? 'text-status-error'
                                                : 'text-foreground',
                                        )}
                                    >
                                        <StepIcon
                                            status={segment.row.status}
                                            size={12}
                                        />
                                        {STEP_LABELS[segment.row.section]}
                                    </span>
                                )}
                            </span>
                        );
                    })}
                    {remainder > 0 && (
                        <span style={{ flexGrow: remainder, flexBasis: 0 }} />
                    )}
                </div>
            }
        </div>
    );
}

/** "Served for 4 days": long spans in days, short ones as durations. */
function servedFor(seconds: number): string {
    const days = Math.floor(seconds / 86_400);

    if (days >= 1) {
        return plural(days, 'day');
    }

    const hours = Math.floor(seconds / 3600);

    return hours >= 1 ? plural(hours, 'hour') : (formatDuration(seconds) ?? '');
}

/** The footer: how long it took, and what serves now. */
function Outcome({
    detail,
    rows,
    bare = false,
}: {
    detail: Detail;
    rows: StepRow[];
    /** In the summary strip: no footer frame. */
    bare?: boolean;
}) {
    const deployment = detail.deployment;
    const now = useNow(deployment.stopped !== null, 60_000);
    const running =
        isInProgress(deployment.status) && deployment.status !== 'ready';
    const typical = typicalSeconds(detail);
    const next = detail.supersededBy;
    const servedUntil =
        deployment.status === 'superseded'
            ? (next?.finishedAt ?? null)
            : (deployment.stopped?.at ?? null);
    const served =
        servedUntil && deployment.finishedAt
            ? Math.floor(
                  (Date.parse(servedUntil) -
                      Date.parse(deployment.finishedAt)) /
                      1000,
              )
            : null;
    const verb =
        deployment.status === 'failed'
            ? 'Failed after'
            : deployment.status === 'cancelled'
              ? 'Canceled after'
              : running
                ? 'Running for'
                : 'Took';
    const hasTime =
        deployment.durationSeconds !== null ||
        (running && deployment.startedAt !== null);

    if (
        !hasTime &&
        rows.length === 0 &&
        !detail.serving &&
        deployment.stopped === null
    ) {
        return null;
    }

    return (
        <MetaLine
            className={cn(
                'type-small text-muted-foreground tabular-nums',
                !bare && 'border-t bg-card px-4 py-2.5',
            )}
        >
            {hasTime && (
                <span>
                    {verb}{' '}
                    <span className="text-foreground">
                        {running && deployment.startedAt ? (
                            <Elapsed since={deployment.startedAt} />
                        ) : (
                            <Duration seconds={deployment.durationSeconds} />
                        )}
                    </span>
                </span>
            )}
            {running && typical !== null && (
                <span>usually {formatDuration(Math.round(typical))}</span>
            )}
            {/* A failure's reassurance is the failure card's first line. */}
            {deployment.status === 'cancelled' && detail.serving && (
                <span className="truncate">
                    Still serving{' '}
                    <DeploymentLink
                        detail={detail}
                        target={detail.serving}
                        fallback="the previous version"
                        sameCommit="the deploy from"
                    />
                </span>
            )}
            {served !== null && served > 0 && (
                <span>Served for {servedFor(served)}</span>
            )}
            {deployment.stopped && (
                <time
                    dateTime={deployment.stopped.at}
                    title={new Date(deployment.stopped.at).toLocaleString()}
                    className="text-foreground"
                >
                    {stoppedLine(deployment.stopped, now)}
                </time>
            )}
        </MetaLine>
    );
}
