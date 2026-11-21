import { Status } from '@/components/status';
import { notServing } from '@/features/service-status/live-status';
import { useServiceStatus } from '@/features/service-status/store';
import { deploymentStatusState, deploymentWord } from './format';

type Summary = App.Data.Deployments.DeploymentSummaryData;

/**
 * A deployment's status word with its cue. The live deployment of a
 * service that should run but doesn't says what the service does ("Not
 * running", "Crashed"), as the service's header does (D108).
 */
export function DeploymentStatus({
    deployment,
    className,
}: {
    deployment: Summary;
    className?: string;
}) {
    const service = notServing(
        useServiceStatus(deployment.environmentId, deployment.serviceId),
    );
    const instead =
        service && deployment.isActive && !deployment.stopped ? service : null;

    return (
        <Status
            state={instead?.state ?? deploymentStatusState(deployment)}
            label={instead?.label ?? deploymentWord(deployment)}
            className={className}
        />
    );
}
