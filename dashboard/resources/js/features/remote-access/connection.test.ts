import { describe, expect, test } from 'vite-plus/test';
import type { Access } from './api';
import { countdown, durationLabel, hostAlias, snippets } from './connection';

const access = (over: Partial<Access> = {}): Access =>
    ({
        id: 'acc_1',
        kind: 'ssh',
        username: 'lr-abcdef0123',
        password: 'p'.repeat(32),
        authMethod: 'password',
        port: 40150,
        engine: null,
        database: null,
        ...over,
    }) as Access;

describe('snippets', () => {
    test('ssh: shell, sftp, scp and a config block', () => {
        const list = snippets(access(), '203.0.113.10', 'API server');

        expect(list.map((s) => s.id)).toEqual(['ssh', 'sftp', 'scp', 'config']);
        expect(list[0].value).toBe('ssh -p 40150 lr-abcdef0123@203.0.113.10');
        expect(list[3].value).toContain('Host launchroom-api-server\n');
        expect(list.some((s) => s.value.includes('p'.repeat(32)))).toBe(false);
    });

    test('volume: SFTP only, no shell; scp into the top folder; IPv6 in brackets', () => {
        const list = snippets(
            access({ kind: 'volume', authMethod: 'key', password: null }),
            '2001:db8::1',
            'uploads',
        );

        expect(list.map((s) => s.id)).toEqual(['sftp', 'scp', 'config']);
        expect(list[1].value).toBe(
            'scp -P 40150 ./file.txt lr-abcdef0123@[2001:db8::1]:/',
        );
        expect(list[2].value).toContain('Host launchroom-uploads-files');
        expect(list[2].value).toContain('IdentitiesOnly yes');
    });

    test('databases keep the password in secret snippets only', () => {
        const list = snippets(
            access({
                kind: 'database',
                engine: 'postgres',
                database: 'app',
                username: 'lr_tmp_abcdef012345',
            }),
            '203.0.113.10',
            'db',
        );

        expect(
            list
                .filter((s) => s.value.includes('p'.repeat(32)))
                .every((s) => s.secret),
        ).toBe(true);
    });
});

test('helpers', () => {
    expect(hostAlias('  ')).toBe('launchroom-service');
    expect(countdown(3725)).toBe('1:02:05');
    expect(durationLabel(30)).toBe('30 minutes');
    expect(durationLabel(60)).toBe('1 hour');
});
