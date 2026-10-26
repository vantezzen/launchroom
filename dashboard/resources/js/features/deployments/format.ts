import type { StatusState } from '@/components/status';
import { relativeTime } from '@/components/time-ago';
import { elapsedSeconds } from '@/lib/duration';

type DeploymentStatus = App.Enums.DeploymentStatus;
type DeploymentTrigger = App.Enums.DeploymentTrigger;

/**
 * Status → the shared Status component's state (design-system §3: colour
 * only with an icon and label).
 */
export function deploymentState(status: DeploymentStatus): StatusState {
    switch (status) {
        case 'active':
            return 'active';
        case 'failed':
            return 'failed';
        case 'ready':
            return 'staged';
        case 'cancelled':
        case 'superseded':
            return 'idle';
        default:
            return 'progress';
    }
}

export function isInProgress(status: DeploymentStatus): boolean {
    return (
        deploymentState(status) === 'progress' ||
        status === 'queued' ||
        status === 'ready'
    );
}

const triggerLabels: Record<DeploymentTrigger, string> = {
    push: 'Push',
    pr: 'Pull request',
    tag: 'Tag',
    manual: 'Redeploy',
    rollback: 'Rollback',
    promote: 'Promote',
    config: 'Config change',
    api: 'API',
    restart: 'Restart',
    start: 'Start',
};

export function triggerLabel(trigger: DeploymentTrigger): string {
    return triggerLabels[trigger];
}

export { elapsedSeconds, formatDuration } from '@/lib/duration';

export function shortSha(sha: string | null): string | null {
    return sha === null ? null : sha.slice(0, 7);
}

export function firstLine(text: string | null): string | null {
    if (text === null) {
        return null;
    }

    const line = text.split(/\r?\n/).find((part) => part.trim() !== '');

    return line?.trim() ?? null;
}

/**
 * "fix: order totals" for commits, otherwise what started it.
 */
export function deploymentTitle(
    deployment: App.Data.Deployments.DeploymentSummaryData,
): string {
    return (
        firstLine(deployment.commitMessage) ??
        (deployment.trigger === 'config'
            ? 'Configuration change'
            : deployment.trigger === 'rollback'
              ? 'Rollback'
              : deployment.trigger === 'manual'
                ? 'Redeploy'
                : deployment.trigger === 'restart'
                  ? 'Restart'
                  : deployment.trigger === 'start'
                    ? 'Start'
                    : triggerLabel(deployment.trigger))
    );
}

/** "main" from "refs/heads/main". */
export function branchName(ref: string | null): string | null {
    return ref === null ? null : ref.replace(/^refs\/(heads|tags)\//, '');
}

/** Who or what started it, in words: "pushed by jonas", "redeployed by Ada". */
export function startedBy(
    deployment: App.Data.Deployments.DeploymentSummaryData,
): string {
    const who = deployment.actorName ?? deployment.commitAuthor;
    const verb: Record<DeploymentTrigger, string> = {
        push: 'pushed',
        pr: 'opened',
        tag: 'tagged',
        manual: 'redeployed',
        rollback: 'rolled back',
        promote: 'promoted',
        config: 'deployed',
        api: 'deployed',
        restart: 'restarted',
        start: 'started',
    };

    return who
        ? `${verb[deployment.trigger]} by ${who}`
        : deployment.trigger === 'config'
          ? 'configuration change'
          : verb[deployment.trigger];
}

/** Total seconds so far for a running deployment, else the recorded duration. */
export function deploymentSeconds(
    deployment: App.Data.Deployments.DeploymentSummaryData,
    now = Date.now(),
): number | null {
    if (deployment.durationSeconds !== null) {
        return deployment.durationSeconds;
    }

    return deployment.startedAt
        ? elapsedSeconds(deployment.startedAt, deployment.finishedAt, now)
        : null;
}

/** The live deployment of a running service: its containers serve (a stopped service's don't, D68). */
export function isServing(
    deployment: App.Data.Deployments.DeploymentSummaryData,
): boolean {
    return deployment.isActive && deployment.stopped === null;
}

/** The Status component's state for a row: idle for a stopped service's version, active for the serving one. */
export function deploymentStatusState(
    deployment: App.Data.Deployments.DeploymentSummaryData,
): StatusState {
    if (deployment.stopped !== null) {
        return 'idle';
    }

    return deployment.isActive ? 'active' : deploymentState(deployment.status);
}

/** "Stopped by Ada 5 minutes ago" (D68). */
export function stoppedLine(
    stopped: App.Data.Deployments.ServiceStoppedData,
    now: number = Date.now(),
): string {
    // Under a minute (or a clock slightly ahead of this one): no seconds count.
    const when =
        now - Date.parse(stopped.at) < 60_000
            ? 'a moment ago'
            : relativeTime(stopped.at, now);

    return stopped.by ? `Stopped by ${stopped.by} ${when}` : `Stopped ${when}`;
}

/** The status word of a deployment (design-system §3.3): "Live" for the serving one, "Stopped" when its service is stopped. */
export function deploymentWord(
    deployment: App.Data.Deployments.DeploymentSummaryData,
): string {
    if (deployment.stopped !== null) {
        return 'Stopped';
    }

    if (deployment.isActive) {
        return 'Live';
    }

    switch (deployment.status) {
        case 'queued':
            return 'Queued';
        case 'ready':
            return 'Ready to promote';
        case 'active':
            return 'Live';
        case 'failed':
            return 'Failed';
        case 'cancelled':
            return 'Cancelled';
        case 'superseded':
            return 'Superseded';
        default:
            return 'Deploying';
    }
}
