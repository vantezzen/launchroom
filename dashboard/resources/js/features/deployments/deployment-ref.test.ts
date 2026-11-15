import { describe, expect, it } from 'vitest';
import { refName } from './deployment-ref';

const ref = (commitSha: string | null) => ({
    commitSha,
    queuedAt: '2026-10-25T08:00:00Z',
    finishedAt: '2026-10-25T08:02:52Z',
});

describe('refName', () => {
    it('names another commit by its short sha', () => {
        expect(refName({ commitSha: 'c48f848aa' }, ref('c663a95bb'))).toEqual({
            kind: 'sha',
            sha: 'c663a95',
        });
    });

    it('names a deploy of the same commit by its time, never the shared sha', () => {
        expect(refName({ commitSha: 'c663a95bb' }, ref('c663a95bb'))).toEqual({
            kind: 'same-commit',
            at: '2026-10-25T08:02:52Z',
        });
        expect(
            refName(
                { commitSha: 'c663a95bb' },
                { ...ref('c663a95bb'), finishedAt: null },
            ),
        ).toEqual({ kind: 'same-commit', at: '2026-10-25T08:00:00Z' });
    });

    it('leaves deployments without a commit to the fallback words', () => {
        expect(refName({ commitSha: null }, ref(null))).toEqual({
            kind: 'unnamed',
        });
    });
});
