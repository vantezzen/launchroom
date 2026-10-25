import { Link, router } from '@inertiajs/react';
import { MapPin } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { DeviceImage, DEVICE_LABEL } from '@/components/devices/device-image';
import { MetaLine } from '@/components/patterns/meta-line';
import { StatusPill, serverStatus } from '@/components/status';
import { Button } from '@/components/ui/button';
import {
    deviceKind,
    deviceStatus,
    shortAge,
    shortDuration,
    specParts,
} from '@/features/servers/format';
import { RoleChipLabel } from '@/features/servers/server-status';
import { useNow } from '@/hooks/use-now';
import { tab } from '@/routes/servers';
import { update as updateAppearance } from '@/routes/servers/appearance';

type Server = App.Data.Servers.ServerDetailData;

function Chip({ children }: { children: ReactNode }) {
    return (
        <span className="inline-flex h-6 items-center rounded-full border px-2.5 type-small text-muted-foreground tabular-nums">
            {children}
        </span>
    );
}

/** ["Virtual machine in Berlin", "Runs this dashboard", "Joined 21 days ago"]. */
function describe(server: Server): string[] {
    const what =
        server.device?.model && server.device.model !== 'Container'
            ? server.device.model
            : server.device?.providerName
              ? `${server.device.providerName} server`
              : DEVICE_LABEL[deviceKind(server)];
    const where = server.location ? ` in ${server.location.label}` : '';
    const joined = server.enrolledAt ? shortAge(server.enrolledAt) : null;

    return [
        `${what}${where}`,
        server.isControlPlane ? 'Runs this dashboard' : null,
        joined ? `Joined ${joined}` : null,
    ].filter((part): part is string => part !== null);
}

/** "Is server-2 in Helsinki?" once, for a location we only guessed. */
function LocationPrompt({ server }: { server: Server }) {
    const [busy, setBusy] = useState(false);
    const location = server.location;

    if (!location || location.confirmed || !server.canUpdateAppearance) {
        return null;
    }

    const confirm = () => {
        setBusy(true);
        router.patch(
            updateAppearance(server.id).url,
            {
                location: {
                    label: location.label,
                    latitude: location.latitude,
                    longitude: location.longitude,
                    countryCode: location.countryCode,
                },
            },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    return (
        <div className="flex flex-wrap items-center gap-x-3 gap-y-2 type-small text-muted-foreground">
            <MapPin className="size-3.5" aria-hidden="true" />
            <span>
                Is {server.name} in{' '}
                <span className="text-foreground">{location.label}</span>?
            </span>
            <Button
                size="sm"
                variant="outline"
                onClick={confirm}
                disabled={busy}
            >
                Yes
            </Button>
            <Link
                href={`${tab({ server: server.id, tab: 'settings' }).url}#appearance`}
                className="text-signal underline-offset-4 hover:underline"
            >
                Change
            </Link>
        </div>
    );
}

/**
 * The server page hero (direction §9.2): the device on a
 * recessed stage, the name in `display`, one status pill, one line of
 * who/where/when and the spec chips.
 */
export function ServerHero({ server }: { server: Server }) {
    const { state, label } = serverStatus[server.status];
    const down = server.status === 'unreachable' || server.status === 'offline';
    const now = useNow(down && server.lastSeenAt !== null, 10_000);
    const since = down ? shortDuration(server.lastSeenAt, now) : null;
    const specs = specParts(server.specs);

    return (
        <header className="flex flex-col gap-6 md:flex-row md:items-center md:gap-10">
            <div className="flex h-[200px] shrink-0 items-center justify-center rounded-2xl border bg-recessed md:h-[220px] md:w-[340px]">
                <DeviceImage
                    device={deviceKind(server)}
                    status={deviceStatus(server.status)}
                    size={300}
                    alt={`${server.name}, ${DEVICE_LABEL[deviceKind(server)].toLowerCase()}`}
                />
            </div>
            <div className="flex min-w-0 flex-col gap-4">
                <div className="flex flex-col gap-2">
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="type-display break-words">
                            {server.name}
                        </h1>
                        <StatusPill
                            state={state}
                            label={label}
                            detail={since ? `for ${since}` : undefined}
                        />
                    </div>
                    <MetaLine className="type-body text-muted-foreground">
                        {describe(server)}
                    </MetaLine>
                </div>
                <div className="flex flex-wrap gap-1.5">
                    {server.roles.map((role) => (
                        <span
                            key={role}
                            className="inline-flex h-6 items-center rounded-full bg-hover px-2.5 type-small"
                        >
                            <RoleChipLabel role={role} />
                        </span>
                    ))}
                    {specs.map((spec) => (
                        <Chip key={spec}>{spec}</Chip>
                    ))}
                    {server.specs?.arch && <Chip>{server.specs.arch}</Chip>}
                </div>
                <LocationPrompt server={server} />
            </div>
        </header>
    );
}
