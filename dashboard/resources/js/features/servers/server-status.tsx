import { labels, vocabulary } from '@/lib/vocabulary';
import { AppWindow, Globe, Hammer, Package } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Status, serverStatus } from '@/components/status';
import { useNow } from '@/hooks/use-now';
import { shortDuration } from '@/lib/format';

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
    const down = vocabulary.ServerStatus[status].isDown;
    const now = useNow(down && lastSeenAt !== null, 10_000);
    const since = down ? shortDuration(lastSeenAt, now) : null;

    return (
        <Status
            state={state}
            label={label}
            since={since}
            className={className}
        />
    );
}

/** Role chips on cards and in the hero. */
export const roleLabels = labels(vocabulary.ServerRole);

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
    build: 'Builds images from your pushes, best on a fast machine.',
    registry: 'Stores built images.',
};

/** What a role needs from the machine, under the role cards in Add server. */
export const roleNeeds: Partial<Record<App.Enums.ServerRole, string>> = {
    runtime: 'Needs to stay on',
    edge: 'Needs a public IP with ports 80 and 443 open',
    build: 'Best with 4 or more cores',
};

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
