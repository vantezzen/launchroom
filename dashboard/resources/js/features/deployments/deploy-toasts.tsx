import { router, usePage } from '@inertiajs/react';
import { toast } from 'sonner';
import { show } from '@/routes/deployments';
import { ChannelEvents } from '@/components/channel-events';
import { channelName } from '@/lib/realtime';

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
function DeployOutcomeToasts({
    project,
    environments,
    names,
}: {
    project: string;
    environments: string[];
    /** Service public id → name. */
    names: Record<string, string>;
}) {
    const onEvent = (
        event: App.Data.Broadcasts.DeploymentUpdatedData | null,
    ) => {
        // Only a status that just changed is an outcome: progress nudges
        // resend the current status of deployments that finished long ago.
        if (
            !event ||
            !event.statusChanged ||
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
                <ChannelEvents
                    key={environment}
                    channel={channelName('environments', environment)}
                    event="deployment.updated"
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
    const { props } = usePage<
        App.Data.Canvas.ProjectShellPropsData & NamedProps
    >();
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
