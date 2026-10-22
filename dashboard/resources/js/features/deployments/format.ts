import type { StatusState } from '@/components/status';

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
};

export function triggerLabel(trigger: DeploymentTrigger): string {
    return triggerLabels[trigger];
}

/**
 * Compact durations (design-system §8): "42s", "1m 42s", "1h 3m".
 */
export function formatDuration(seconds: number | null): string | null {
    if (seconds === null) {
        return null;
    }

    if (seconds < 60) {
        return `${seconds}s`;
    }

    const minutes = Math.floor(seconds / 60);

    if (minutes < 60) {
        return `${minutes}m ${seconds % 60}s`;
    }

    return `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
}

export function elapsedSeconds(
    from: string,
    to: string | null,
    now = Date.now(),
): number {
    const end = to === null ? now : new Date(to).getTime();

    return Math.max(0, Math.round((end - new Date(from).getTime()) / 1000));
}

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

/** The status word of a deployment (design-system §3.3): "Live" for the serving one. */
export function deploymentWord(
    deployment: App.Data.Deployments.DeploymentSummaryData,
): string {
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
