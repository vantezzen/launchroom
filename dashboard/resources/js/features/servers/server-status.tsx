import { AppWindow, Globe, Hammer, Package } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Status, serverStatus } from '@/components/status';
import { shortDuration } from '@/features/servers/format';

export function ServerStatus({
    status,
    lastSeenAt = null,
    className,
}: {
    status: App.Enums.ServerStatus;
    /** Adds "for 8 min" to Unreachable/Offline (design-system §3.3). */
    lastSeenAt?: string | null;
    className?: string;
}) {
    const { state, label } = serverStatus[status];
    const since =
        status === 'unreachable' || status === 'offline'
            ? shortDuration(lastSeenAt)
            : null;

    return (
        <Status
            state={state}
            label={label}
            detail={since ? `for ${since}` : undefined}
            className={className}
        />
    );
}

/** Role chips on cards and in the hero. */
export const roleLabels: Record<App.Enums.ServerRole, string> = {
    edge: 'Edge',
    runtime: 'Runtime',
    build: 'Build',
    registry: 'Registry',
};

/** Role cards ("What should it do?", "What this server does"). */
export const roleTitles: Record<App.Enums.ServerRole, string> = {
    edge: 'Serve traffic',
    runtime: 'Run apps',
    build: 'Build',
    registry: 'Store images',
};

/** One monochrome icon per role (role cards, topology, chips). */
export const roleIcons: Record<App.Enums.ServerRole, LucideIcon> = {
    runtime: AppWindow,
    edge: Globe,
    build: Hammer,
    registry: Package,
};

export const roleDescriptions: Record<App.Enums.ServerRole, string> = {
    edge: 'Receives public traffic and serves HTTPS for every app.',
    runtime: 'Runs your apps, workers and databases.',
    build: 'Turns pushes into images. Best on a fast machine.',
    registry: 'Stores built images.',
};

export function RoleList({ roles }: { roles: App.Enums.ServerRole[] }) {
    return (
        <span className="type-body text-muted-foreground">
            {roles.map((role) => roleLabels[role]).join(', ')}
        </span>
    );
}

/** A role chip's content: the role icon (12 px, muted) and its label. */
export function RoleChipLabel({ role }: { role: App.Enums.ServerRole }) {
    const Icon = roleIcons[role];

    return (
        <span className="inline-flex items-center gap-1.5">
            <Icon
                aria-hidden="true"
                className="size-3 shrink-0 text-muted-foreground"
            />
            {roleLabels[role]}
        </span>
    );
}
