import { router, usePage } from '@inertiajs/react';
import { toast } from 'sonner';
import { cancel, show } from '@/routes/deployments';
import { ChannelEvents } from '@/components/channel-events';
import { shortSha } from '@/lib/format';
import { channelName } from '@/lib/realtime';
import { isCancellable, isInProgress } from './format';

type Update = App.Data.Broadcasts.DeploymentUpdatedData;

/** One toast per deployment outcome, however many listeners are mounted. */
const announced = new Set<string>();

/** Services whose progress and "is live" another surface shows (the first-live card). */
const quiet = new Set<string>();

/** Silences progress and "is live" toasts for a service; returns the undo. */
export function quietDeployToasts(serviceId: string): () => void {
    quiet.add(serviceId);

    return () => quiet.delete(serviceId);
}

/** What a running deployment is doing, for its progress toast. */
const DOING: Partial<Record<App.Enums.DeploymentStatus, string>> = {
    queued: 'Waiting for a build server',
    building: 'Building',
    releasing: 'Running the release command',
    rolling_out: 'Rolling out',
    health_checking: 'Checking health',
    routing: 'Switching traffic',
};

function deploymentArgs(project: string, event: Update) {
    return {
        project,
        environment: event.environmentId,
        deployment: event.deploymentId,
    };
}

/**
 * "web is live · Open" and "web failed to deploy · See why". The toast id
 * is the deployment's, so the outcome replaces its progress toast.
 */
function announceOutcome(project: string, name: string, event: Update) {
    const key = `${event.deploymentId}:${event.status}`;

    if (announced.has(key)) {
        return;
    }

    announced.add(key);

    if (event.status === 'active' && quiet.has(event.serviceId)) {
        toast.dismiss(event.deploymentId);

        return;
    }

    const open = () => router.visit(show(deploymentArgs(project, event)).url);
    const options = { id: event.deploymentId, duration: 6_000 };

    if (event.status === 'active') {
        toast.success(`${name} is live`, {
            ...options,
            description: undefined,
            action: { label: 'Open', onClick: open },
            cancel: undefined,
        });
    } else if (event.status === 'failed') {
        toast.error(`${name} failed to deploy`, {
            ...options,
            description: undefined,
            action: { label: 'See why', onClick: open },
            cancel: undefined,
        });
    } else if (event.status === 'ready') {
        toast.info(`${name} is ready to promote`, {
            ...options,
            description: undefined,
            action: { label: 'Open', onClick: open },
            cancel: undefined,
        });
    }
}

function isOutcome(status: App.Enums.DeploymentStatus): boolean {
    return status === 'active' || status === 'failed' || status === 'ready';
}

/**
 * Deploy outcome toasts for the open project (flows §5, design-system §6
 * "async outcomes") when a deployment of these environments finishes.
 * Realtime only (Reverb): without it there is no event to announce, and the
 * pages' polling shows the state. Mounted once, by the project layout.
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
    const onEvent = (event: Update | null) => {
        // Only a status that just changed is an outcome: progress nudges
        // resend the current status of deployments that finished long ago.
        if (!event || !event.statusChanged || !isOutcome(event.status)) {
            return;
        }

        announceOutcome(
            project,
            names[event.serviceId] ?? event.serviceName ?? 'A deployment',
            event,
        );
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
 * The project's deploy outcome toasts: the project layout mounts it. Watches
 * the project's persistent environments (and the open preview) and names
 * the service from whatever the current page already knows.
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

/**
 * The deployments the signed-in member started follow them on every page
 * (NAV-4, DPL-10): a progress toast while it runs ("Deploying web
 * (3b7d5e1)", "Building") with Open and, while it can still stop, Cancel; the
 * outcome replaces it. Their own channel (`members.<id>`) carries only
 * their deployments' status changes. The app shell mounts it.
 */
export function MyDeployToasts() {
    const { realtime } = usePage().props;
    const member = realtime?.member ?? null;

    if (member === null) {
        return null;
    }

    const onEvent = (event: Update | null) => {
        if (!event || !event.statusChanged || event.projectId === null) {
            return;
        }

        const project = event.projectId;
        const name = event.serviceName ?? 'A deployment';

        if (isOutcome(event.status)) {
            announceOutcome(project, name, event);

            return;
        }

        if (event.status === 'cancelled' || event.status === 'superseded') {
            toast.dismiss(event.deploymentId);

            return;
        }

        if (!isInProgress(event.status) || quiet.has(event.serviceId)) {
            return;
        }

        const args = deploymentArgs(project, event);
        const sha = shortSha(event.commitSha);

        toast.loading(
            sha ? `Deploying ${name} (${sha})` : `Deploying ${name}`,
            {
                id: event.deploymentId,
                description: DOING[event.status] ?? undefined,
                duration: Infinity,
                action: {
                    label: 'Open',
                    onClick: () => router.visit(show(args).url),
                },
                cancel: isCancellable(event.status)
                    ? {
                          label: 'Cancel',
                          onClick: () =>
                              router.post(
                                  cancel(args).url,
                                  {},
                                  { preserveScroll: true, preserveState: true },
                              ),
                      }
                    : undefined,
            },
        );
    };

    return (
        <ChannelEvents
            channel={channelName('members', member)}
            event="deployment.updated"
            pollMs={3_600_000}
            onEvent={onEvent}
        />
    );
}
