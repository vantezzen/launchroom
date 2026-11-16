import { vocabulary } from '@/lib/vocabulary';
import { Link, router } from '@inertiajs/react';
import { MapPin, X } from 'lucide-react';
import { useState } from 'react';
import { AlertsChip } from '@/components/attention-chip';
import { DeviceImage, DEVICE_LABEL } from '@/components/devices/device-image';
import { MetaLine } from '@/components/patterns/meta-line';
import { StatusPill, serverStatus } from '@/components/status';
import { Button } from '@/components/ui/button';
import {
    deviceKind,
    deviceStatus,
    placeLabel,
    serverAlerts,
} from '@/features/servers/format';
import { SpecLine } from '@/features/servers/spec-line';
import { RoleChipLabel } from '@/features/servers/server-status';
import { useNow } from '@/hooks/use-now';
import { tab } from '@/routes/servers';
import { update as updateAppearance } from '@/routes/servers/appearance';
import { shortAge, shortDuration } from '@/lib/format';

type Server = App.Data.Servers.ServerDetailData;

/** ["Virtual machine", "Runs this dashboard", "Joined 21 days ago"]; the place is on the spec line. */
function describe(server: Server): string[] {
    const what =
        server.device?.model && server.device.model !== 'Container'
            ? server.device.model
            : server.device?.providerName
              ? `${server.device.providerName} server`
              : DEVICE_LABEL[deviceKind(server)];
    const joined = server.enrolledAt ? shortAge(server.enrolledAt) : null;

    return [
        what,
        server.isControlPlane ? 'Runs this dashboard' : null,
        joined ? `Joined ${joined}` : null,
    ].filter((part): part is string => part !== null);
}

function dismissKey(server: Server): string {
    return `launchroom.locationPrompt.${server.id}`;
}

function wasDismissed(server: Server): boolean {
    try {
        return window.localStorage.getItem(dismissKey(server)) === '1';
    } catch {
        return false;
    }
}

/**
 * "Is server-2 in Helsinki?" under the hero, only while the location is a
 * guess: "Yes" confirms it, "Change" opens Settings → Appearance, and the
 * row can be dismissed (per browser).
 */
function LocationPrompt({ server }: { server: Server }) {
    const [busy, setBusy] = useState(false);
    const [dismissed, setDismissed] = useState(() => wasDismissed(server));
    const location = server.location;

    if (
        !location ||
        location.confirmed ||
        !server.canUpdateAppearance ||
        dismissed
    ) {
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

    const dismiss = () => {
        setDismissed(true);

        try {
            window.localStorage.setItem(dismissKey(server), '1');
        } catch {
            // Private mode: it stays hidden until the page reloads.
        }
    };

    return (
        <div className="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border bg-card px-4 py-2.5 type-small text-muted-foreground">
            <MapPin className="size-3.5 shrink-0" aria-hidden="true" />
            <span className="min-w-0 flex-1">
                Going by its address, {server.name} is in{' '}
                <span className="text-foreground">{location.label}</span>. Is
                that right?
            </span>
            <span className="flex items-center gap-1">
                <Button
                    size="sm"
                    variant="outline"
                    onClick={confirm}
                    disabled={busy}
                >
                    Yes
                </Button>
                <Button asChild size="sm" variant="ghost">
                    <Link
                        href={`${tab({ server: server.id, tab: 'settings' }).url}#appearance`}
                    >
                        Change
                    </Link>
                </Button>
                <Button
                    size="icon-sm"
                    variant="ghost"
                    onClick={dismiss}
                    aria-label="Dismiss"
                    className="text-muted-foreground"
                >
                    <X aria-hidden="true" />
                </Button>
            </span>
        </div>
    );
}

/**
 * The server page hero (direction §9.2): the device on a
 * recessed stage, the name in `display`, one status pill, one line of
 * what/who/when, the role chips and the spec line with the place.
 */
export function ServerHero({ server }: { server: Server }) {
    const { state, label } = serverStatus[server.status];
    const down = vocabulary.ServerStatus[server.status].isDown;
    const now = useNow(down && server.lastSeenAt !== null, 10_000);
    const since = down ? shortDuration(server.lastSeenAt, now) : null;

    return (
        <>
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
                                since={since}
                            />
                            <AlertsChip
                                alerts={serverAlerts(server.openAlerts)}
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
                    </div>
                    <SpecLine
                        specs={server.specs}
                        place={placeLabel(server)}
                        arch={server.specs?.arch ?? null}
                        className="type-small text-muted-foreground"
                    />
                </div>
            </header>
            <LocationPrompt server={server} />
        </>
    );
}
