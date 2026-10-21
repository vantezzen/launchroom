import { DeviceImage } from '@/components/devices/device-image';
import type { DeviceStatus } from '@/components/devices/device-image';

type Server = App.Data.Servers.ServerData;

function memory(mb: number): string {
    const gb = mb / 1024;

    return `${gb < 10 ? Number(gb.toFixed(1)) : Math.round(gb)} GB`;
}

/**
 * "fra-1 · Hetzner Cloud · 4 cores · 8 GB": what a server is, in one line
 * (setup, get started). Unknown parts are left out.
 */
export function serverFacts(server: Server): string[] {
    const facts: string[] = [];
    const where = server.device?.model ?? server.device?.providerName;

    if (where) {
        facts.push(where);
    }

    if (server.specs?.cpus) {
        facts.push(
            `${server.specs.cpus} ${server.specs.cpus === 1 ? 'core' : 'cores'}`,
        );
    }

    if (server.specs?.memoryMb) {
        facts.push(memory(server.specs.memoryMb));
    }

    return facts;
}

export function deviceStatus(server: Server | null): DeviceStatus {
    if (server === null) {
        return 'pending';
    }

    switch (server.status) {
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

/** The server's render with its status LED (pending silhouette without a server). */
export function ServerDevice({
    server,
    size,
    className,
}: {
    server: Server | null;
    size: number;
    className?: string;
}) {
    return (
        <DeviceImage
            device={server?.device?.kind ?? 'unknown'}
            status={deviceStatus(server)}
            size={size}
            className={className}
        />
    );
}
