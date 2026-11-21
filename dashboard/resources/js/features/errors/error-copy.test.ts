import { describe, expect, it } from 'vite-plus/test';
import { errorCopy } from './error-copy';

const base: App.Data.Errors.ErrorPageData = {
    status: 404,
    kind: 'not_found',
    path: '/x',
    missing: null,
    suggestions: [],
    reference: null,
    retryAfter: null,
    reloadTo: null,
};

const KINDS: App.Enums.Http.ErrorPageKind[] = [
    'not_found',
    'forbidden',
    'expired',
    'too_many_requests',
    'server_error',
    'unavailable',
];

describe('errorCopy', () => {
    it('names the missing level of a resource path', () => {
        expect(errorCopy({ ...base, missing: 'service' }, true).title).toBe(
            'No service at this address',
        );
        expect(errorCopy(base, true).title).toBe('This page doesn’t exist');
    });

    it('says how long to wait', () => {
        const busy = { ...base, kind: 'too_many_requests' as const };

        expect(errorCopy({ ...busy, retryAfter: 30 }, true).text).toContain(
            'in 30 seconds',
        );
        expect(errorCopy({ ...busy, retryAfter: 1 }, true).text).toContain(
            'in 1 second.',
        );
        expect(errorCopy({ ...busy, retryAfter: 300 }, true).text).toContain(
            'in 5 minutes',
        );
    });

    it('follows the copy rules in every state', () => {
        for (const kind of KINDS) {
            for (const signedIn of [true, false]) {
                const copy = errorCopy({ ...base, kind }, signedIn);
                const words = `${copy.title} ${copy.text}`;

                expect(words).not.toMatch(
                    /—|·|\b(just|easy|simple|very|really|oops|sorry)\b/i,
                );
                expect(copy.title).not.toMatch(/\?$/);
            }
        }
    });
});
