import { vocabulary } from '@/lib/vocabulary';
import type {
    DeviceKind,
    DeviceStatus,
} from '@/components/devices/device-image';
import { formatCores, formatMemory } from '@/lib/format';

/** "used / total GB" with one unit ("5.1 / 8 GB"). */
export function formatUsedOf(usedMb: number, totalMb: number): string {
    const unitGb = totalMb >= 1024;
    const scale = (mb: number) => (unitGb ? mb / 1024 : mb);
    const digits = (value: number) =>
        value < 10 ? value.toFixed(1) : String(Math.round(value));

    return `${digits(scale(usedMb))} / ${digits(scale(totalMb))} ${unitGb ? 'GB' : 'MB'}`;
}

/** ["12 cores", "11.7 GB", "366 GB"] (the card footer and the hero chips). */
export function specParts(
    specs: App.Data.Servers.ServerSpecsData | null | undefined,
): string[] {
    if (!specs) {
        return [];
    }

    return [
        specs.cpus !== null ? formatCores(specs.cpus) : null,
        specs.memoryMb !== null ? formatMemory(specs.memoryMb) : null,
        specs.diskTotalMb !== null ? formatMemory(specs.diskTotalMb) : null,
    ].filter((part): part is string => part !== null);
}

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

/** "Hetzner, Frankfurt", "Berlin", "Hetzner" or null. */
export function placeLabel(server: {
    device?: App.Data.Servers.DeviceData | null;
    location?: App.Data.Servers.LocationData | null;
}): string | null {
    const parts = [
        server.device?.providerName ?? null,
        server.location?.label ?? null,
    ].filter((part): part is string => part !== null && part !== '');

    return parts.length > 0 ? parts.join(', ') : null;
}

/** The problem servers first, then the dashboard's server, then by name. */
export function fleetOrder(
    a: App.Data.Servers.ServerData,
    b: App.Data.Servers.ServerData,
): number {
    const rank = (server: App.Data.Servers.ServerData) =>
        vocabulary.ServerStatus[server.status].isDown
            ? 0
            : server.status === 'pending_approval'
              ? 1
              : server.isControlPlane
                ? 2
                : 3;

    return rank(a) - rank(b) || a.name.localeCompare(b.name);
}

/**
 * A server's open alerts as its card and hero show them (docs/15 §3.6):
 * "offline" is left out because the status already says so.
 */
export function serverAlerts(
    alerts: App.Data.Alerts.OpenAlertData[],
): App.Data.Alerts.OpenAlertData[] {
    return alerts.filter((alert) => alert.rule !== 'server_offline');
}
