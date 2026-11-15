import { vocabulary } from '@/lib/vocabulary';
import type { MapLink, MapLocation } from '@/components/map';
import { plural } from '@/lib/format';

type Server = App.Data.Servers.ServerData;

export function fleetSummary(servers: Server[], places: number): string[] {
    const online = servers.filter((s) => s.status === 'online').length;
    const down = servers.filter(
        (s) => vocabulary.ServerStatus[s.status].isDown,
    ).length;
    const waiting = servers.filter(
        (s) => s.status === 'pending_approval',
    ).length;

    return [
        places > 1
            ? `${plural(servers.length, 'server')} in ${places} places`
            : plural(servers.length, 'server'),
        online > 0 && online < servers.length ? `${online} online` : null,
        online === servers.length && servers.length > 0 ? 'All online' : null,
        down > 0 ? `${down} unreachable` : null,
        waiting > 0 ? `${waiting} waiting for approval` : null,
    ].filter((part): part is string => part !== null);
}

/** One bubble per place; links follow each server's path to the dashboard's server. */
export function fleetMap(servers: Server[]): {
    locations: MapLocation[];
    links: MapLink[];
} {
    const byPlace = new Map<string, MapLocation & { down: number }>();
    const placeOf = new Map<string, string>();

    for (const server of servers) {
        const location = server.location;

        if (!location) {
            continue;
        }

        const id = `${location.latitude.toFixed(1)},${location.longitude.toFixed(1)}`;
        const place = byPlace.get(id) ?? {
            id,
            label: location.label,
            lat: location.latitude,
            lon: location.longitude,
            servers: 0,
            down: 0,
            edge: false,
        };
        place.servers += 1;
        place.down += vocabulary.ServerStatus[server.status].isDown ? 1 : 0;
        place.edge = place.edge || server.roles.includes('edge');
        byPlace.set(id, place);
        placeOf.set(server.id, id);
    }

    const locations = [...byPlace.values()].map(({ down, ...place }) => ({
        ...place,
        status:
            down === place.servers ? ('unreachable' as const) : ('ok' as const),
    }));

    const home = servers.find((server) => server.isControlPlane);
    const homePlace = home ? placeOf.get(home.id) : undefined;
    const links: MapLink[] = [];
    const seen = new Set<string>();

    for (const server of servers) {
        const place = placeOf.get(server.id);
        const path = server.mesh?.pathToControlPlane;

        if (!homePlace || !place || place === homePlace || !path) {
            continue;
        }

        const key = `${place}:${path}`;

        if (!seen.has(key)) {
            seen.add(key);
            links.push({
                from: place,
                to: homePlace,
                relayed: path === 'relay',
            });
        }
    }

    return { locations, links };
}
