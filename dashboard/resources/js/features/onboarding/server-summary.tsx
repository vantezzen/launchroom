import { DeviceImage } from '@/components/devices/device-image';
import { deviceStatus } from '@/features/servers/format';

type Server = App.Data.Servers.ServerData;

function memory(mb: number): string {
    const gb = mb / 1024;

    return `${gb < 10 ? Number(gb.toFixed(1)) : Math.round(gb)} GB`;
}

/**
 * ["Hetzner Cloud", "4 cores", "8 GB"]: what a server is, for one line
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
            status={server === null ? 'pending' : deviceStatus(server.status)}
            size={size}
            className={className}
        />
    );
}
