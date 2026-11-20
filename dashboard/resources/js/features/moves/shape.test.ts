import { describe, expect, test } from 'vite-plus/test';
import {
    dnsRecordState,
    explainMessage,
    fromDnsSwitch,
    fromMoveRecords,
    groupByScope,
    groupItems,
    stopConsequence,
} from './shape';
import type { DnsSwitchData, MoveItem } from './types';

function item(
    id: string,
    status: App.Enums.Moves.MoveItemStatus,
    extra: Partial<MoveItem> = {},
): MoveItem {
    return {
        id,
        instanceId: id,
        name: `${id} (shop / production)`,
        service: id,
        scope: 'shop / production',
        kind: 'stateless',
        status,
        failedStep: null,
        message: null,
        expectedBytes: null,
        copiedBytes: null,
        attempts: 1,
        stoppedAt: null,
        startedAt: null,
        finishedAt: null,
        canRetry: false,
        canStop: false,
        processes: [],
        href: null,
        ...extra,
    };
}

function check(
    status: App.Enums.Domains.DnsCheckStatus,
    addresses: string[],
): App.Data.Domains.DnsCheckData {
    return {
        status,
        hostname: 'dash.acme.dev',
        addresses,
        expected: ['203.0.113.7'],
        cname: null,
        message: 'message',
        checkedAt: '2026-11-19T09:30:00Z',
        proxy: null,
        reachedOverHttp: false,
    };
}

describe('groupItems', () => {
    test('orders a move as needs you, now, next, not moving, moved', () => {
        const groups = groupItems([
            item('done', 'done'),
            item('next', 'pending'),
            item('copying', 'exporting'),
            item('failed', 'failed'),
            item('skipped', 'skipped'),
            item('back', 'rolling_back'),
        ]);

        expect(groups.failed.map((entry) => entry.id)).toEqual(['failed']);
        expect(groups.active.map((entry) => entry.id)).toEqual([
            'copying',
            'back',
        ]);
        expect(groups.waiting.map((entry) => entry.id)).toEqual(['next']);
        expect(groups.other.map((entry) => entry.id)).toEqual(['skipped']);
        expect(groups.done.map((entry) => entry.id)).toEqual(['done']);
    });
});

describe('groupByScope', () => {
    test('keeps services of one project and environment together', () => {
        const groups = groupByScope([
            item('web', 'done'),
            item('site', 'done', {
                name: 'site (blog / production)',
                scope: 'blog / production',
            }),
            item('api', 'done'),
            item('kuma', 'done', { scope: 'tools / production' }),
            item('docs', 'done', {
                name: 'docs (blog / production)',
                scope: 'blog / production',
            }),
        ]);

        expect(
            groups.map((group) => [
                group.scope,
                group.items.map((entry) => entry.id),
            ]),
        ).toEqual([
            ['shop / production', ['web', 'api']],
            ['blog / production', ['site', 'docs']],
            ['tools / production', ['kuma']],
        ]);
    });
});

describe('explainMessage', () => {
    test('folds the raw cause and keeps where the service runs', () => {
        expect(
            explainMessage(
                'The build failed: next build exited with code 1 (Cannot find module ‘sharp’). It still runs on hel-1.',
                'It couldn’t move.',
            ),
        ).toEqual({
            summary: 'The build failed. It still runs on hel-1.',
            detail: 'next build exited with code 1 (Cannot find module ‘sharp’).',
        });
    });

    test('splits a parenthesised cause', () => {
        expect(
            explainMessage(
                'Cancelled. It couldn’t be started on hel-1 again (exit status 137). Deploy it from its page.',
                'It couldn’t move.',
            ),
        ).toEqual({
            summary:
                'Cancelled. It couldn’t be started on hel-1 again. Deploy it from its page.',
            detail: 'exit status 137',
        });
    });

    test('leaves human messages whole', () => {
        const stalled =
            'The new server hasn’t used its join token for 5 minutes. If its terminal says it joined, it was already connected to this dashboard: the continue command below picks the move up with that server. Otherwise check its terminal for errors, or cancel the move.';

        for (const message of [
            'Already runs on fsn-2.',
            'Build failed: pnpm lockfile is out of date. It still runs on hel-1.',
            stalled,
        ]) {
            expect(explainMessage(message, 'fallback')).toEqual({
                summary: message,
                detail: null,
            });
        }
    });

    test('a raw message without a human lead gets the fallback', () => {
        const raw =
            'SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "placements_pkey"\nDETAIL:  Key (id)=(42) already exists.';

        expect(explainMessage(raw, 'It couldn’t move.')).toEqual({
            summary: 'It couldn’t move.',
            detail: raw,
        });
    });
});

describe('stopConsequence', () => {
    test('names the workers and crons that stop along', () => {
        expect(
            stopConsequence(
                item('web', 'failed', { processes: ['queue', 'nightly'] }),
            ),
        ).toBe(
            'web (shop / production), with queue and nightly, stops and doesn’t move. Start it again later from its page.',
        );
    });

    test('reads without processes', () => {
        expect(stopConsequence(item('web', 'failed'))).toBe(
            'web (shop / production) stops and doesn’t move. Start it again later from its page.',
        );
    });
});

describe('dnsRecordState', () => {
    test('is a word with a non-colour cue for every check', () => {
        expect(dnsRecordState(null)).toEqual(['idle', 'Not checked']);
        expect(dnsRecordState(check('ok', ['203.0.113.7']))).toEqual([
            'active',
            'Switched',
        ]);
        expect(dnsRecordState(check('missing', []))).toEqual([
            'warning',
            'No record yet',
        ]);
    });

    test('tells the old address from another one', () => {
        expect(
            dnsRecordState(check('mismatch', ['198.51.100.2']), [
                '198.51.100.2',
            ]),
        ).toEqual(['warning', 'Still the old address']);
        expect(
            dnsRecordState(check('mismatch', ['192.0.2.9']), ['198.51.100.2']),
        ).toEqual(['warning', 'Points elsewhere']);
    });
});

describe('DNS rows', () => {
    test('plan records get A and AAAA values and stay loading without live checks', () => {
        const [row] = fromMoveRecords([
            {
                kind: 'apps',
                host: '*.apps.acme.dev',
                addresses: ['203.0.113.7', '2001:db8::7'],
                remove: ['198.51.100.2'],
                check: null,
            },
        ]);

        expect(row.kind).toBe('Apps');
        expect(row.values).toEqual([
            { type: 'A', value: '203.0.113.7' },
            { type: 'AAAA', value: '2001:db8::7' },
        ]);
        expect(row.check).toBeUndefined();
    });

    test('the switch keeps labels, values and the service a domain serves', () => {
        const data: DnsSwitchData = {
            newUrl: 'https://dash.acme.dev',
            addresses: [{ type: 'A', value: '203.0.113.7' }],
            oldAddresses: ['198.51.100.2'],
            records: [
                {
                    kind: 'domain',
                    label: 'Custom domain',
                    name: 'shop.acme.com',
                    values: [{ type: 'A', value: '203.0.113.7' }],
                    remove: ['198.51.100.2'],
                    usedBy: 'web (shop / production)',
                    check: null,
                },
            ],
            reachBeforeDns: null,
            checked: false,
            pointed: 0,
            total: 1,
        };

        expect(fromDnsSwitch(data)).toEqual([
            {
                kind: 'Custom domain',
                host: 'shop.acme.com',
                values: [{ type: 'A', value: '203.0.113.7' }],
                previous: ['198.51.100.2'],
                usedBy: 'web (shop / production)',
                check: null,
            },
        ]);
    });
});
