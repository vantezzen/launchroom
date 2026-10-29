import { isInProgress } from './format';

type Summary = App.Data.Deployments.DeploymentSummaryData;

export type ServiceDeploymentList = {
    active: Summary | null;
    deployments: Summary[];
    current: { id: string } | null;
};

/**
 * The expanded first row of a service's Deployments tab (docs/08 §13): the
 * deployment in progress, else the live one, else the newest. A failed or
 * cancelled first deploy is that newest row, so its error, steps and
 * "Try again" stay in the list instead of an empty state.
 */
export function listHead(data: ServiceDeploymentList): Summary | null {
    const current = data.current?.id;

    return (
        data.deployments.find((deployment) => deployment.id === current) ??
        (data.active !== null && data.active.id === current
            ? data.active
            : null) ??
        data.deployments.find((deployment) =>
            isInProgress(deployment.status),
        ) ??
        data.active ??
        data.deployments[0] ??
        null
    );
}

/** A head that never went live: Deploy again is its main action. */
export function needsRetry(head: Summary, active: Summary | null): boolean {
    return (
        active === null &&
        (head.status === 'failed' || head.status === 'cancelled')
    );
}
