import type {
    DeviceKind,
    DeviceStatus,
} from '@/components/devices/device-image';

/** Megabytes as "4.1 GB" / "512 MB" (one decimal under 10). */
export function formatMb(mb: number | null | undefined): string | null {
    if (mb === null || mb === undefined || !Number.isFinite(mb)) {
        return null;
    }

    if (mb >= 1024 * 1024) {
        const tb = mb / 1024 / 1024;

        return `${tb < 10 ? tb.toFixed(1) : Math.round(tb)} TB`;
    }

    if (mb >= 1024) {
        const gb = mb / 1024;

        return `${gb < 10 ? gb.toFixed(1) : Math.round(gb)} GB`;
    }

    return `${Math.round(mb)} MB`;
}

/** "used / total GB" with one unit ("5.1 / 8 GB"). */
export function formatUsedOf(usedMb: number, totalMb: number): string {
    const unitGb = totalMb >= 1024;
    const scale = (mb: number) => (unitGb ? mb / 1024 : mb);
    const digits = (value: number) =>
        value < 10 ? value.toFixed(1) : String(Math.round(value));

    return `${digits(scale(usedMb))} / ${digits(scale(totalMb))} ${unitGb ? 'GB' : 'MB'}`;
}

/** "12 cores · 11.7 GB · 366 GB" (the card footer and the hero chips). */
export function specParts(
    specs: App.Data.Servers.ServerSpecsData | null | undefined,
): string[] {
    if (!specs) {
        return [];
    }

    return [
        specs.cpus !== null
            ? `${specs.cpus} ${specs.cpus === 1 ? 'core' : 'cores'}`
            : null,
        formatMb(specs.memoryMb),
        formatMb(specs.diskTotalMb),
    ].filter((part): part is string => part !== null);
}

/** Compact age: "now", "12s ago", "8 min ago", "3 h ago", "2 days ago". */
export function shortAge(
    iso: string | null,
    now: number = Date.now(),
): string | null {
    if (iso === null) {
        return null;
    }

    const seconds = Math.max(
        0,
        Math.round((now - new Date(iso).getTime()) / 1000),
    );

    if (seconds < 5) {
        return 'now';
    }

    if (seconds < 60) {
        return `${seconds}s ago`;
    }

    if (seconds < 3600) {
        return `${Math.round(seconds / 60)} min ago`;
    }

    if (seconds < 86_400) {
        return `${Math.round(seconds / 3600)} h ago`;
    }

    const days = Math.round(seconds / 86_400);

    return `${days} ${days === 1 ? 'day' : 'days'} ago`;
}

/** Duration since a time without "ago": "8 min", "3 h". */
export function shortDuration(
    iso: string | null,
    now: number = Date.now(),
): string | null {
    const age = shortAge(iso, now);

    return age === null ? null : age.replace(/ ago$/, '');
}

/** Device art status from the server status. */
export function deviceStatus(status: App.Enums.ServerStatus): DeviceStatus {
    switch (status) {
        case 'online':
            return 'online';
        case 'unreachable':
            return 'unreachable';
        case 'pending_approval':
            return 'pending';
        default:
            return 'offline';
    }
}

export function deviceKind(server: {
    device?: App.Data.Servers.DeviceData | null;
}): DeviceKind {
    return server.device?.kind ?? 'unknown';
}

/** "Hetzner · Frankfurt", "Berlin", "Hetzner" or null. */
export function placeLabel(server: {
    device?: App.Data.Servers.DeviceData | null;
    location?: App.Data.Servers.LocationData | null;
}): string | null {
    const parts = [
        server.device?.providerName ?? null,
        server.location?.label ?? null,
    ].filter((part): part is string => part !== null && part !== '');

    return parts.length > 0 ? parts.join(' · ') : null;
}

/** The problem servers first, then the dashboard's server, then by name. */
export function fleetOrder(
    a: App.Data.Servers.ServerData,
    b: App.Data.Servers.ServerData,
): number {
    const rank = (server: App.Data.Servers.ServerData) =>
        server.status === 'unreachable' || server.status === 'offline'
            ? 0
            : server.status === 'pending_approval'
              ? 1
              : server.isControlPlane
                ? 2
                : 3;

    return rank(a) - rank(b) || a.name.localeCompare(b.name);
}
