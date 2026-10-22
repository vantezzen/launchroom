import { describe, expect, test } from 'vite-plus/test';
import {
    appendLogPage,
    initialLogState,
    logStateFor,
} from './use-deployment-logs';

type LogPage = App.Data.Deployments.LogPageData;
type LogLine = App.Data.Deployments.LogLineData;

const line = (number: number, text: string): LogLine =>
    ({
        number,
        text,
        time: '2026-10-21T12:00:00.000Z',
        stream: 'stdout',
        section: 'build',
        step: 'setup',
    }) as LogLine;

const page = (cursor: number, ...texts: string[]): LogPage =>
    ({
        lines: texts.map((text, i) => line(cursor * 10 + i + 1, text)),
        hasMore: false,
        cursor,
    }) as LogPage;

describe('deployment log state', () => {
    test('Redeploy: visiting the new deployment starts over from its own first page', () => {
        // The failed deployment's page, after a live append.
        let state = initialLogState(
            'dpl_old',
            page(1, 'Connecting to BuildKit'),
        );
        state = appendLogPage(
            state,
            'dpl_old',
            page(2, 'error: buildkitd: not ready'),
        );
        expect(state.lines.map((l) => l.text)).toEqual([
            'Connecting to BuildKit',
            'error: buildkitd: not ready',
        ]);

        // Inertia keeps the component and passes the new deployment's props.
        const next = logStateFor(state, 'dpl_new', page(0));
        expect(next.deployment).toBe('dpl_new');
        expect(next.lines).toEqual([]);
        expect(next.cursor).toBe(0);

        // Same deployment, new props (a partial reload): the state is kept.
        expect(logStateFor(state, 'dpl_old', page(1, 'ignored'))).toBe(state);
    });

    test('a response of the previous deployment arriving late is dropped', () => {
        const fresh = initialLogState('dpl_new', page(0));
        const late = appendLogPage(fresh, 'dpl_old', page(3, 'old error'));
        expect(late).toBe(fresh);

        const live = appendLogPage(
            fresh,
            'dpl_new',
            page(1, 'Waiting for BuildKit to start…'),
        );
        expect(live.lines.map((l) => l.text)).toEqual([
            'Waiting for BuildKit to start…',
        ]);
    });

    test('pages that do not move the cursor append nothing', () => {
        const state = initialLogState('dpl_a', page(2, 'one'));
        expect(appendLogPage(state, 'dpl_a', page(2, 'one again'))).toBe(state);
        expect(
            appendLogPage(state, 'dpl_a', { ...page(1), hasMore: true })
                .hasMore,
        ).toBe(true);
    });
});
