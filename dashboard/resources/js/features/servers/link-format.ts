import { formatLatency } from '@/lib/format';

/** "480 Mbit/s", "12 Mbit/s", "3.4 Mbit/s", "1.2 Gbit/s". */
export function formatLinkSpeed(mbps: number): string {
    if (mbps >= 1000) {
        return `${(mbps / 1000).toFixed(1)} Gbit/s`;
    }

    return mbps < 10
        ? `${mbps.toFixed(1)} Mbit/s`
        : `${Math.round(mbps)} Mbit/s`;
}

/** The slower direction of a link: what the link can carry both ways. */
function slowest(speeds: App.Data.Servers.LinkSpeedData[]): number | null {
    return speeds.length === 0
        ? null
        : Math.min(...speeds.map((speed) => speed.mbps));
}

/** "12 ms, 480 Mbit/s", or null when nothing was measured. */
export function linkLabel(link: App.Data.Servers.MeshLinkData): string | null {
    const rate = slowest(link.speeds);
    const parts = [
        link.latencyMs !== null ? formatLatency(link.latencyMs) : null,
        rate !== null ? formatLinkSpeed(rate) : null,
    ].filter((part): part is string => part !== null);

    return parts.length > 0 ? parts.join(', ') : null;
}
