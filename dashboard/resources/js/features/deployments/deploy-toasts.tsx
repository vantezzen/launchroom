import { router, usePage } from '@inertiajs/react';
import type { ProjectShellProps } from '@/features/projects/types';
import { toast } from 'sonner';
import { ChannelEvents } from './realtime';
import type { DeploymentUpdatedEvent } from './realtime';
import { show } from '@/routes/deployments';

/** One toast per deployment outcome, however many listeners are mounted. */
const announced = new Set<string>();

/**
 * Deploy outcome toasts (flows §5, design-system §6 "async outcomes"):
 * "web is live · Open" and "web failed to deploy · See why" when a
 * deployment of these environments finishes. Realtime only (Reverb): without
 * it there is no event to announce, and the pages' polling shows the state.
 * Mounted once, by the project layout (`ProjectDeployToasts`), so every
 * project page announces outcomes the same way.
 */
export function DeployOutcomeToasts({
    project,
    environments,
    names,
}: {
    project: string;
    environments: string[];
    /** Service public id → name. */
    names: Record<string, string>;
}) {
    const onEvent = (event: DeploymentUpdatedEvent | null) => {
        if (
            !event ||
            (event.status !== 'active' && event.status !== 'failed')
        ) {
            return;
        }

        const key = `${event.deploymentId}:${event.status}`;

        if (announced.has(key)) {
            return;
        }

        announced.add(key);
        const name = names[event.serviceId] ?? 'A deployment';
        const open = () =>
            router.visit(
                show({
                    project,
                    environment: event.environmentId,
                    deployment: event.deploymentId,
                }).url,
            );

        if (event.status === 'active') {
            toast.success(`${name} is live`, {
                action: { label: 'Open', onClick: open },
            });
        } else {
            toast.error(`${name} failed to deploy`, {
                action: { label: 'See why', onClick: open },
            });
        }
    };

    return (
        <>
            {environments.map((environment) => (
                <ChannelEvents<DeploymentUpdatedEvent>
                    key={environment}
                    channel={`environments.${environment}`}
                    event=".deployment.updated"
                    pollMs={3_600_000}
                    onEvent={onEvent}
                />
            ))}
        </>
    );
}

type NamedProps = {
    nodes?: { id: string; name: string }[];
    serviceOptions?: { id: string; name: string }[];
    services?: { id: string; name: string }[] | number;
    deployment?: { serviceId: string; serviceName: string };
};

/**
 * The one place deploy outcome toasts live: the project layout. Watches the
 * project's persistent environments (and the open preview) and names the
 * service from whatever the current page already knows.
 */
export function ProjectDeployToasts() {
    const { props } = usePage<ProjectShellProps & NamedProps>();
    const watched = props.environments
        .filter((item) => item.kind !== 'preview')
        .map((item) => item.id);

    if (props.environment && !watched.includes(props.environment.id)) {
        watched.push(props.environment.id);
    }

    const names: Record<string, string> = {};
    const lists = [
        props.nodes,
        props.serviceOptions,
        Array.isArray(props.services) ? props.services : undefined,
    ];

    for (const list of lists) {
        for (const item of list ?? []) {
            names[item.id] = item.name;
        }
    }

    if (props.deployment) {
        names[props.deployment.serviceId] = props.deployment.serviceName;
    }

    return (
        <DeployOutcomeToasts
            project={props.project.id}
            environments={watched}
            names={names}
        />
    );
}
