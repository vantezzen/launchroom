import { Check, Copy, Eye, EyeOff } from 'lucide-react';
import { useState } from 'react';
import { DeviceImage } from '@/components/devices/device-image';
import type { DeviceKind } from '@/components/devices/device-image';
import { ChoiceTiles } from '@/components/patterns/choice-tiles';
import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';
import { RoleCard } from '@/features/servers/server-roles';
import { maskCommand } from '@/features/servers/mask-command';
import { roleNeeds, roleTitles } from '@/features/servers/server-status';

export const ADD_SERVER_STEPS = [
    { id: 'machine', title: 'What is it' },
    { id: 'roles', title: 'What it does' },
    { id: 'connect', title: 'Connect' },
];

export type Machine = 'cloud' | 'dedicated' | 'home' | 'mac' | 'board';

const MACHINES: {
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
                        needs={roleNeeds[role]}
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

export type Platform = 'debian' | 'linux' | 'macos';

/** What to have ready, per platform: the command is the same everywhere. */
const PLATFORMS: { value: Platform; label: string; hint: string }[] = [
    {
        value: 'debian',
        label: 'Ubuntu or Debian',
        hint: 'Installs Docker if it’s missing, after asking.',
    },
    {
        value: 'linux',
        label: 'Other Linux',
        hint: 'Install Docker Engine 24 or newer with the compose plugin first.',
    },
    {
        value: 'macos',
        label: 'Mac',
        hint: 'Start OrbStack, Colima or Docker Desktop first. With Docker Desktop the Mac only builds.',
    },
];

/**
 * The one install command (flows §10 step 3): masked until revealed, Copy
 * takes the full command, and a short list of what each platform needs
 * first (a Mac first for a Mac).
 */
export function InstallCommand({
    command,
    machine = null,
}: {
    command: string;
    machine?: Machine | null;
}) {
    const [revealed, setRevealed] = useState(false);
    const [copied, copy] = useClipboard();
    const shown = revealed ? command : maskCommand(command);
    const platforms =
        machine === 'mac'
            ? [...PLATFORMS].sort(
                  (a, b) =>
                      Number(b.value === 'macos') - Number(a.value === 'macos'),
              )
            : PLATFORMS;

    return (
        <div className="flex flex-col gap-3">
            <div className="flex items-start gap-3 rounded-xl bg-foreground px-4 py-3.5 text-background">
                <span
                    aria-hidden="true"
                    className="type-mono opacity-50 select-none"
                >
                    $
                </span>
                <code className="min-w-0 flex-1 type-mono break-all">
                    {shown}
                </code>
                <div className="flex shrink-0 items-center gap-1.5">
                    <Button
                        type="button"
                        size="icon-sm"
                        variant="ghost"
                        className="text-background hover:bg-background/10 hover:text-background"
                        aria-label={
                            revealed
                                ? 'Hide the join token'
                                : 'Show the join token'
                        }
                        aria-pressed={revealed}
                        onClick={() => setRevealed((value) => !value)}
                    >
                        {revealed ? (
                            <EyeOff aria-hidden="true" />
                        ) : (
                            <Eye aria-hidden="true" />
                        )}
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="secondary"
                        onClick={() => void copy(command)}
                    >
                        {copied === command ? (
                            <Check aria-hidden="true" />
                        ) : (
                            <Copy aria-hidden="true" />
                        )}
                        {copied === command ? 'Copied' : 'Copy'}
                    </Button>
                </div>
            </div>
            <ul className="flex flex-col gap-1 type-small text-muted-foreground">
                {platforms.map((p) => (
                    <li key={p.value}>
                        <span className="text-foreground">{p.label}:</span>{' '}
                        {p.hint}
                    </li>
                ))}
            </ul>
        </div>
    );
}
