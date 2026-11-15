import { describe, expect, test } from 'vite-plus/test';
import { fleetMap, fleetSummary } from './fleet-map';

type Server = App.Data.Servers.ServerData;

const server = (fields: Partial<Server> & { id: string }): Server =>
    ({
        status: 'online',
        roles: ['apps'],
        isControlPlane: false,
        location: null,
        mesh: null,
        ...fields,
    }) as unknown as Server;

const place = (label: string, latitude: number, longitude: number) =>
    ({ label, latitude, longitude }) as Server['location'];
const berlin = place('Berlin', 52.52, 13.4);
const paris = place('Paris', 48.86, 2.35);

describe('fleetMap', () => {
    test('one bubble per place, unreachable only when every server there is down', () => {
        const { locations } = fleetMap([
            server({ id: 'a', location: berlin, isControlPlane: true }),
            server({ id: 'b', location: berlin, status: 'offline' }),
            server({
                id: 'c',
                location: paris,
                status: 'unreachable',
                roles: ['edge'],
            }),
        ]);

        expect(locations).toEqual([
            expect.objectContaining({
                label: 'Berlin',
                servers: 2,
                status: 'ok',
                edge: false,
            }),
            expect.objectContaining({
                label: 'Paris',
                servers: 1,
                status: 'unreachable',
                edge: true,
            }),
        ]);
    });

    test('links each other place to the control plane once per path', () => {
        const mesh = (path: string) =>
            ({ pathToControlPlane: path }) as unknown as Server['mesh'];
        const { links } = fleetMap([
            server({ id: 'a', location: berlin, isControlPlane: true }),
            server({ id: 'b', location: paris, mesh: mesh('direct') }),
            server({ id: 'c', location: paris, mesh: mesh('direct') }),
            server({ id: 'd', location: paris, mesh: mesh('relay') }),
        ]);

        expect(links).toEqual([
            { from: '48.9,2.4', to: '52.5,13.4', relayed: false },
            { from: '48.9,2.4', to: '52.5,13.4', relayed: true },
        ]);
    });
});

describe('fleetSummary', () => {
    test('counts places and states', () => {
        expect(
            fleetSummary(
                [
                    server({ id: 'a' }),
                    server({ id: 'b', status: 'offline' }),
                    server({ id: 'c', status: 'pending_approval' }),
                ],
                2,
            ),
        ).toEqual([
            '3 servers in 2 places',
            '1 online',
            '1 unreachable',
            '1 waiting for approval',
        ]);
    });
});
