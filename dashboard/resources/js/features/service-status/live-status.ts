import { compactAge, relativeTime, shortAge } from '@/lib/format';
import { vocabulary } from '@/lib/vocabulary';

export type LiveStatus = App.Data.Canvas.EnvironmentLiveStatusData;
export type ServiceLiveStatus = App.Data.Canvas.ServiceLiveStatusData;
export type ServiceStatus = App.Data.Canvas.NodeStatusData;

/**
 * The snapshot to keep (D108): an older one never replaces a newer one,
 * however late it arrives. `version` is the server's clock before it read.
 */
export function newer(
    current: LiveStatus | undefined,
    next: LiveStatus,
): LiveStatus {
    if (
        current === undefined ||
        current.environmentId !== next.environmentId ||
        next.version >= current.version
    ) {
        return next;
    }

    return current;
}

/** One service's status in the snapshot, or null when it isn't in it (yet). */
export function statusOf(
    snapshot: LiveStatus | undefined,
    serviceId: string,
): ServiceStatus | null {
    return (
        snapshot?.services.find((entry) => entry.serviceId === serviceId)
            ?.status ?? null
    );
}

/**
 * Canvas nodes with the snapshot's statuses: page props bring the nodes,
 * the store their current status. The same array when nothing differs.
 */
export function withLiveStatuses<
    T extends { id: string; status: ServiceStatus },
>(nodes: T[], snapshot: LiveStatus | undefined): T[] {
    if (snapshot === undefined) {
        return nodes;
    }

    let changed = false;
    const next = nodes.map((node) => {
        const status = statusOf(snapshot, node.id);

        if (status === null || status === node.status) {
            return node;
        }

        changed = true;

        return { ...node, status };
    });

    return changed ? next : nodes;
}

/**
 * The status an action is about to cause, before the server says so (an
 * optimistic Stop, Start, Restart or Redeploy). Words and tone come from
 * the PHP enum; `label` only where the server words it by step too.
 */
export function optimisticStatus(
    reason: App.Enums.NodeStatusReason,
    label?: string,
    now: Date = new Date(),
): ServiceStatus {
    const word = vocabulary.NodeStatusReason[reason];

    return {
        state: word.tone,
        reason,
        label: label ?? word.label,
        since: now.toISOString(),
        deploymentId: null,
        detail: null,
        cause: null,
        remedy: null,
    };
}

/**
 * The snapshot with one service's status replaced. Its version stays, so
 * the next snapshot from the server replaces it.
 */
export function withStatus(
    snapshot: LiveStatus,
    serviceId: string,
    status: ServiceStatus,
): LiveStatus {
    return {
        ...snapshot,
        services: snapshot.services.map((entry) =>
            entry.serviceId === serviceId
                ? {
                      ...entry,
                      status: {
                          ...status,
                          deploymentId: entry.status.deploymentId,
                      },
                      problem:
                          status.state === 'failed' ||
                          status.state === 'warning'
                              ? entry.problem
                              : null,
                  }
                : entry,
        ),
    };
}

const URGENCY: Partial<Record<App.Enums.StatusTone, number>> = {
    failed: 2,
    warning: 1,
};

/** The services that need the user, most urgent first (stable within a tone). */
export function problems(
    snapshot: LiveStatus | undefined,
): ServiceLiveStatus[] {
    return (snapshot?.services ?? [])
        .filter((entry) => entry.problem !== null)
        .map((entry, index) => ({ entry, index }))
        .sort(
            (a, b) =>
                (URGENCY[b.entry.status.state] ?? 0) -
                    (URGENCY[a.entry.status.state] ?? 0) || a.index - b.index,
        )
        .map(({ entry }) => entry);
}

/**
 * The quiet words after a status: why and since when ("exited 2h ago",
 * "ran out of memory 5m ago"), or only the age ("3d") when nothing needs
 * explaining.
 */
export function statusDetail(
    status: ServiceStatus,
    now: number = Date.now(),
): string | null {
    if (status.cause) {
        const age = status.since ? shortAge(status.since, now) : null;

        return age && age !== 'now' ? `${status.cause} ${age}` : status.cause;
    }

    return status.since ? compactAge(status.since, now) : null;
}

/**
 * The service's status when its live deployment isn't serving, else null:
 * then a deployment row must not say "Live" (D108). Which statuses those
 * are is NodeStatusReason::isNotServing().
 */
export function notServing(status: ServiceStatus | null): ServiceStatus | null {
    return status !== null &&
        vocabulary.NodeStatusReason[status.reason].isNotServing
        ? status
        : null;
}

/**
 * "Live deployment, not running: exited 2 hours ago." for the current
 * deployment card of a service that should run but doesn't.
 */
export function notServingSentence(
    status: ServiceStatus,
    now: number = Date.now(),
): string {
    const word = status.label.charAt(0).toLowerCase() + status.label.slice(1);
    const age =
        status.cause && status.since ? ` ${ago(status.since, now)}` : '';
    const cause = status.cause ? `: ${status.cause}${age}` : '';

    return `Live deployment, ${word}${cause}.`;
}

/** "2 hours ago"; "a moment ago" under a minute or for a server clock ahead of this one. */
function ago(iso: string, now: number): string {
    return now - Date.parse(iso) < 60_000
        ? 'a moment ago'
        : relativeTime(iso, now);
}

/**
 * Whether the store already says "is live" is wrong for this deployment:
 * its service, running this deployment, is stopped or doesn't serve. A
 * status about an older deployment says nothing yet (its snapshot predates
 * the event), so it never silences a fresh outcome.
 */
export function contradictsLive(
    status: ServiceStatus | null,
    deploymentId: string,
): boolean {
    return (
        status !== null &&
        status.deploymentId === deploymentId &&
        (notServing(status) !== null || status.reason === 'stopped')
    );
}
