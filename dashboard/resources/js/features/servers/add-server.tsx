import { Check, Copy } from 'lucide-react';
import { useState } from 'react';
import { DeviceImage } from '@/components/devices/device-image';
import type { DeviceKind } from '@/components/devices/device-image';
import { ChoiceTiles } from '@/components/patterns/choice-tiles';
import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';
import { cn } from '@/lib/utils';
import { RoleCard } from '@/features/servers/server-roles';
import { roleTitles } from '@/features/servers/server-status';

export const ADD_SERVER_STEPS = [
    { id: 'machine', title: 'What is it' },
    { id: 'roles', title: 'What it does' },
    { id: 'connect', title: 'Connect' },
];

export type Machine = 'cloud' | 'dedicated' | 'home' | 'mac' | 'board';

export const MACHINES: {
    value: Machine;
    label: string;
    description: string;
    device: DeviceKind;
    roles: App.Enums.ServerRole[];
    /** One line under "What should it do?" for this machine. */
    hint: string;
    /** Roles it can't take, with the reason (D53). */
    limits?: Partial<Record<App.Enums.ServerRole, string>>;
}[] = [
    {
        value: 'cloud',
        label: 'Cloud server',
        description: 'A VPS from any provider',
        device: 'cloud',
        roles: ['runtime', 'edge'],
        hint: 'Suggested for a cloud server. You can change this any time.',
    },
    {
        value: 'dedicated',
        label: 'Dedicated server',
        description: 'Bare metal, rented',
        device: 'dedicated',
        roles: ['runtime', 'edge'],
        hint: 'Suggested for a dedicated server. You can change this any time.',
    },
    {
        value: 'home',
        label: 'Machine at home',
        description: 'Desktop, mini PC, NAS',
        device: 'minipc',
        roles: ['build'],
        hint: 'A home machine makes a fast build server. It connects out, so it works behind your router.',
    },
    {
        value: 'mac',
        label: 'Mac',
        description: 'Mac mini, MacBook, iMac',
        device: 'compact',
        roles: ['build'],
        hint: 'A Mac makes a fast build server, also for your x86 servers. With OrbStack or Colima it can run apps too.',
        limits: {
            edge: 'Docker on a Mac runs in a Linux VM, so it can’t receive public traffic. Use a public server for that.',
        },
    },
    {
        value: 'board',
        label: 'Small board',
        description: 'Raspberry Pi and similar',
        device: 'sbc',
        roles: ['runtime'],
        hint: 'Good for small apps and databases. It connects out, so it works behind your router.',
    },
];

export function machine(value: string | null | undefined) {
    return MACHINES.find((m) => m.value === value) ?? null;
}

export function MachineTiles({
    value,
    onChange,
}: {
    value: Machine | null;
    onChange: (value: Machine) => void;
}) {
    return (
        <ChoiceTiles
            label="What kind of machine is it?"
            columns={3}
            value={value}
            onChange={onChange}
            options={MACHINES.map((m) => ({
                value: m.value,
                label: m.label,
                description: m.description,
                media: <DeviceImage device={m.device} size={120} />,
            }))}
        />
    );
}

const ROLE_ORDER: App.Enums.ServerRole[] = ['runtime', 'edge', 'build'];

export function RolePicker({
    value,
    onChange,
    limits = {},
}: {
    value: App.Enums.ServerRole[];
    onChange: (roles: App.Enums.ServerRole[]) => void;
    /** Roles the machine can't take, with the reason (D53). */
    limits?: Partial<Record<App.Enums.ServerRole, string>>;
}) {
    return (
        <div
            role="group"
            aria-label="What should it do?"
            className="grid gap-4 sm:grid-cols-3"
        >
            {ROLE_ORDER.map((role) => {
                const on = value.includes(role);
                const limit = limits[role];

                if (limit) {
                    return (
                        <RoleCard
                            key={role}
                            role={role}
                            on={false}
                            disabled
                            note={limit}
                            aria-label={roleTitles[role]}
                        />
                    );
                }

                return (
                    <RoleCard
                        key={role}
                        role={role}
                        on={on}
                        disabled={false}
                        aria-label={roleTitles[role]}
                        onToggle={() =>
                            onChange(
                                ROLE_ORDER.filter((r) =>
                                    r === role ? !on : value.includes(r),
                                ),
                            )
                        }
                    />
                );
            })}
        </div>
    );
}

export type Platform = 'debian' | 'linux' | 'docker' | 'macos';

const PLATFORMS: { value: Platform; label: string; hint: string }[] = [
    {
        value: 'debian',
        label: 'Ubuntu / Debian',
        hint: 'Offers to install Docker if it’s missing.',
    },
    {
        value: 'docker',
        label: 'Docker already installed',
        hint: 'Needs Docker Engine 24 or newer with the compose plugin.',
    },
    {
        value: 'linux',
        label: 'Other Linux',
        hint: 'Install Docker Engine 24 or newer first, then run this.',
    },
    {
        value: 'macos',
        label: 'macOS',
        hint: 'Start OrbStack, Docker Desktop or Colima first. It installs into ~/launchroom. With Docker Desktop the Mac only builds, with OrbStack or Colima it can run apps too.',
    },
];

/** The one command, with a platform switcher (macOS first for a Mac). */
export function InstallCommand({
    command,
    machine = null,
}: {
    command: string;
    machine?: Machine | null;
}) {
    const [platform, setPlatform] = useState<Platform>(
        machine === 'mac' ? 'macos' : 'debian',
    );
    const [copied, copy] = useClipboard();
    // server.sh offers to install Docker itself when it's missing (it asks first).
    const full = command;
    const current = PLATFORMS.find((p) => p.value === platform)!;

    return (
        <div className="flex flex-col gap-3">
            <div
                role="radiogroup"
                aria-label="Platform"
                className="inline-flex w-fit flex-wrap gap-0.5 rounded-lg bg-hover p-0.5"
            >
                {PLATFORMS.map((p) => (
                    <button
                        key={p.value}
                        type="button"
                        role="radio"
                        aria-checked={platform === p.value}
                        onClick={() => setPlatform(p.value)}
                        className={cn(
                            'h-7 rounded-md px-3 type-label transition-colors duration-fast focus-visible:ring-2 focus-visible:ring-signal focus-visible:outline-none',
                            platform === p.value
                                ? 'bg-card text-foreground shadow-card'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {p.label}
                    </button>
                ))}
            </div>
            <div className="flex items-start gap-3 rounded-xl bg-foreground px-4 py-3.5 text-background">
                <span
                    aria-hidden="true"
                    className="type-mono opacity-50 select-none"
                >
                    $
                </span>
                <code className="min-w-0 flex-1 type-mono break-all">
                    {full}
                </code>
                <Button
                    type="button"
                    size="sm"
                    variant="secondary"
                    className="shrink-0"
                    onClick={() => void copy(full)}
                >
                    {copied === full ? (
                        <Check aria-hidden="true" />
                    ) : (
                        <Copy aria-hidden="true" />
                    )}
                    {copied === full ? 'Copied' : 'Copy'}
                </Button>
            </div>
            <p className="type-small text-muted-foreground">{current.hint}</p>
        </div>
    );
}
