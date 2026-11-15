/**
 * What the service panel's title row shows next to the status (docs/08
 * §13): the open alerts and Action needed findings that say something the
 * status doesn't. An alert that repeats the status ("Deploy failed" beside
 * "Failed", "Unhealthy" beside "Unhealthy") is left out, so one state has
 * one indicator.
 */

type Alert = App.Data.Alerts.OpenAlertData;

export type Signal = {
    severity: string;
    label: string;
    title: string;
    href: string | null;
};

type Reason = App.Enums.NodeStatusReason;

/** Status reasons that already say what an alert of the rule says. */
const SAID_BY_STATUS: Partial<Record<App.Enums.Alerts.AlertRule, Reason[]>> = {
    deploy_failed: ['deploy_failed', 'last_deploy_failed'],
    service_unhealthy: ['unhealthy', 'crashed', 'not_running'],
    server_offline: ['server_down'],
};

export function secondarySignals(
    status: Reason,
    alerts: Alert[],
    actionNeeded: { count: number; href: string } | null,
): Signal[] {
    const rest: Signal[] = alerts.filter(
        (alert) =>
            !(SAID_BY_STATUS[alert.rule] ?? []).includes(status) &&
            // Open vulnerabilities are what Action needed counts.
            !(actionNeeded && alert.rule === 'vulnerability'),
    );

    if (!actionNeeded || actionNeeded.count === 0) {
        return rest;
    }

    return [
        {
            severity: 'critical',
            label:
                actionNeeded.count > 1
                    ? `Action needed ${actionNeeded.count}`
                    : 'Action needed',
            title:
                actionNeeded.count > 1
                    ? `${actionNeeded.count} security findings need you`
                    : 'A security finding needs you',
            href: actionNeeded.href,
        },
        ...rest,
    ];
}

/** Failed deploys link the status to the deployment that says why. */
export function failedDeployment(status: App.Data.Canvas.NodeStatusData) {
    return (status.reason === 'deploy_failed' ||
        status.reason === 'last_deploy_failed') &&
        status.deploymentId
        ? status.deploymentId
        : null;
}
