import { describe, expect, it } from 'vitest';
import { formatArgv, parseArgv } from './argv';

describe('parseArgv', () => {
    it('splits words like a shell, quotes and backslashes group', () => {
        expect(parseArgv('  php artisan   migrate:status ')).toEqual({
            ok: true,
            argv: ['php', 'artisan', 'migrate:status'],
        });
        expect(
            parseArgv(
                `php artisan tinker --execute 'echo "hi";' "a b" c\\ d ''`,
            ),
        ).toEqual({
            ok: true,
            argv: [
                'php',
                'artisan',
                'tinker',
                '--execute',
                'echo "hi";',
                'a b',
                'c d',
                '',
            ],
        });
        expect(parseArgv('echo "say \\"hi\\""')).toEqual({
            ok: true,
            argv: ['echo', 'say "hi"'],
        });
        expect(parseArgv('')).toEqual({ ok: true, argv: [] });
    });

    it('refuses what only a shell would do', () => {
        for (const input of [
            'ls | grep a',
            'a && b',
            'a; b',
            'echo > file',
            'cat < file',
        ]) {
            expect(parseArgv(input)).toMatchObject({ ok: false });
        }

        expect(parseArgv('echo $HOME')).toMatchObject({ ok: false });
        expect(parseArgv('echo "$(id)"')).toMatchObject({ ok: false });
        expect(parseArgv("echo 'its")).toEqual({
            ok: false,
            error: 'A quote isn’t closed.',
        });
        // Inside single quotes everything is text.
        expect(parseArgv("echo '$HOME | x'")).toEqual({
            ok: true,
            argv: ['echo', '$HOME | x'],
        });
    });
});

describe('formatArgv', () => {
    it('quotes only what needs it, and round-trips', () => {
        const argv = ['php', 'artisan', 'queue:retry', 'all', 'a b', "it's"];

        expect(formatArgv(argv)).toBe(
            `php artisan queue:retry all 'a b' 'it'\\''s'`,
        );
        expect(parseArgv(formatArgv(argv))).toEqual({ ok: true, argv });
    });
});
