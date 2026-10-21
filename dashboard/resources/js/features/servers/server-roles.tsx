import { router, usePage } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useState } from 'react';
import type { ComponentProps } from 'react';
import InputError from '@/components/input-error';
import { ConfirmPopover } from '@/components/patterns/confirm-popover';
import { Section } from '@/components/patterns/section';
import {
    roleDescriptions,
    roleIcons,
    roleTitles,
} from '@/features/servers/server-status';
import { RoleHints } from '@/features/servers/role-hints';
import { cn } from '@/lib/utils';
import { update } from '@/routes/servers/roles';

const editable: App.Enums.ServerRole[] = ['runtime', 'edge', 'build'];

/** What changes when a role goes away, in one sentence. */
function removalConsequence(
    role: App.Enums.ServerRole,
    name: string,
    workloads: number,
): string | null {
    if (role === 'runtime' && workloads > 0) {
        return `${workloads} ${workloads === 1 ? 'replica moves' : 'replicas move'} to other servers with the next deploy.`;
    }

    if (role === 'edge') {
        return `${name} stops receiving public traffic; point DNS at your other edges first.`;
    }

    if (role === 'build') {
        return 'Builds that haven’t started move to another build server; running builds finish here.';
    }

    return null;
}

export function RoleCard({
    role,
    on,
    disabled,
    onToggle,
    note,
    ...props
}: Omit<ComponentProps<'button'>, 'role'> & {
    role: App.Enums.ServerRole;
    on: boolean;
    disabled: boolean;
    onToggle?: () => void;
    /** Why this machine can't take the role (a Mac never serves traffic, D53). */
    note?: string;
}) {
    const Icon = roleIcons[role];

    return (
        <button
            type="button"
            {...props}
            role="switch"
            aria-checked={on}
            disabled={disabled}
            onClick={onToggle ?? props.onClick}
            className={cn(
                'relative flex w-full flex-col gap-1 rounded-xl border bg-card p-4 pr-11 text-left transition-[border-color,box-shadow,color] duration-fast',
                'focus-visible:ring-2 focus-visible:ring-signal focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none',
                on
                    ? 'border-foreground shadow-[0_0_0_0.5px_var(--foreground)]'
                    : 'hover:border-border-strong',
                disabled && 'cursor-default',
            )}
        >
            <span
                aria-hidden="true"
                className={cn(
                    'mb-2 flex size-9 items-center justify-center rounded-lg border bg-recessed transition-colors duration-fast',
                    on ? 'text-foreground' : 'text-muted-foreground',
                )}
            >
                <Icon className="size-[18px]" />
            </span>
            <span className="type-body font-medium">{roleTitles[role]}</span>
            <span className="type-small text-muted-foreground">
                {roleDescriptions[role]}
            </span>
            {note && (
                <span className="mt-1 type-small text-foreground">
                    <span className="sr-only">Not available: </span>
                    {note}
                </span>
            )}
            <span
                aria-hidden="true"
                className={cn(
                    'absolute top-4 right-4 flex size-5 items-center justify-center rounded-full border',
                    on && 'border-foreground bg-foreground text-background',
                )}
            >
                {on && <Check className="size-3" strokeWidth={2.5} />}
            </span>
        </button>
    );
}

/**
 * "What this server does" (direction §9.2): role cards that apply right
 * away. Adding a role is instant; removing one confirms its consequence
 * in a popover. The dashboard still refuses removing the last edge or
 * build server, or runtime while volumes are pinned here.
 */
export function ServerRoles({
    serverId,
    name,
    roles,
    workloads,
    canUpdate,
    hints = [],
    limits = {},
}: {
    serverId: string;
    name: string;
    roles: App.Enums.ServerRole[];
    workloads: number;
    canUpdate: boolean;
    hints?: App.Data.Servers.RoleHintData[];
    /** Roles this machine can't take, with the reason (D53). */
    limits?: Partial<Record<App.Enums.ServerRole, string>>;
}) {
    const errors = usePage().props.errors as Record<string, string> | undefined;
    const [busy, setBusy] = useState(false);
    const current = editable.filter((role) => roles.includes(role));

    const save = (next: App.Enums.ServerRole[]) =>
        new Promise<void>((resolve) => {
            setBusy(true);
            router.put(
                update(serverId).url,
                { roles: next },
                {
                    preserveScroll: true,
                    onFinish: () => {
                        setBusy(false);
                        resolve();
                    },
                },
            );
        });

    return (
        <Section
            id="roles"
            title="What this server does"
            description={canUpdate ? 'Changes apply right away.' : undefined}
        >
            <div className="grid gap-4 sm:grid-cols-3">
                {editable.map((role) => {
                    const on = current.includes(role);

                    if (!canUpdate) {
                        return (
                            <RoleCard key={role} role={role} on={on} disabled />
                        );
                    }

                    if (!on && limits[role]) {
                        return (
                            <RoleCard
                                key={role}
                                role={role}
                                on={false}
                                disabled
                                note={limits[role]}
                            />
                        );
                    }

                    if (!on) {
                        return (
                            <RoleCard
                                key={role}
                                role={role}
                                on={false}
                                disabled={busy}
                                onToggle={() => void save([...current, role])}
                            />
                        );
                    }

                    const consequence = removalConsequence(
                        role,
                        name,
                        workloads,
                    );

                    return (
                        <ConfirmPopover
                            key={role}
                            title={`Stop “${roleTitles[role].toLowerCase()}” on ${name}?`}
                            consequence={
                                consequence ?? 'This applies right away.'
                            }
                            confirmLabel="Remove role"
                            onConfirm={() =>
                                save(current.filter((r) => r !== role))
                            }
                            align="center"
                        >
                            <RoleCard role={role} on disabled={busy} />
                        </ConfirmPopover>
                    );
                })}
            </div>
            {roles.includes('registry') && (
                <p className="type-small text-muted-foreground">
                    Also stores built images (registry).
                </p>
            )}
            <InputError message={errors?.roles} />
            <RoleHints hints={hints} />
        </Section>
    );
}
