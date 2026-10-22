import {
    afterEach,
    beforeEach,
    describe,
    expect,
    test,
    vi,
} from 'vite-plus/test';
import {
    PLAN_POLL_MS,
    PLAN_TIMEOUT_MESSAGE,
    PLAN_TIMEOUT_MS,
    startPlanPreview,
} from './use-plan-preview';
import type { PlanPreviewUpdate } from './use-plan-preview';

type Preview = App.Data.Canvas.PlanPreviewData;

const pending: Preview = {
    status: 'pending',
    plan: null,
    message: 'Inspecting the repository on the server control plane',
    suggestions: null,
};

describe('startPlanPreview', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    test('polls while pending and stops on the answer', async () => {
        const answers: Preview[] = [
            pending,
            pending,
            { status: 'ready', plan: null, message: null, suggestions: null },
        ];
        const fetch = vi.fn((_retry: boolean) =>
            Promise.resolve(answers.shift()!),
        );
        const updates: PlanPreviewUpdate[] = [];

        startPlanPreview({
            fetch,
            onUpdate: (u) => updates.push(u),
            retry: false,
        });
        await vi.advanceTimersByTimeAsync(PLAN_POLL_MS * 5);

        expect(fetch).toHaveBeenCalledTimes(3);
        expect(updates.at(-1)?.preview?.status).toBe('ready');
    });

    test('a task that never finishes turns into an error after the timeout', async () => {
        const fetch = vi.fn((_retry: boolean) => Promise.resolve(pending));
        const updates: PlanPreviewUpdate[] = [];

        startPlanPreview({
            fetch,
            onUpdate: (u) => updates.push(u),
            retry: false,
        });
        await vi.advanceTimersByTimeAsync(PLAN_TIMEOUT_MS + PLAN_POLL_MS * 3);

        expect(updates.at(-1)).toEqual({
            preview: null,
            error: PLAN_TIMEOUT_MESSAGE,
        });
        const calls = fetch.mock.calls.length;
        await vi.advanceTimersByTimeAsync(PLAN_POLL_MS * 10);
        expect(fetch.mock.calls.length).toBe(calls);
    });

    test('a failed plan is shown with its reason', async () => {
        const failed: Preview = {
            status: 'failed',
            plan: null,
            message: 'Detection failed: railpack could not plan',
            suggestions: null,
        };
        const updates: PlanPreviewUpdate[] = [];

        startPlanPreview({
            fetch: () => Promise.resolve(failed),
            onUpdate: (u) => updates.push(u),
            retry: false,
        });
        await vi.advanceTimersByTimeAsync(0);

        expect(updates).toEqual([{ preview: failed, error: null }]);
    });

    test('a retry asks for a new task once, then polls normally', async () => {
        const fetch = vi.fn((_retry: boolean) => Promise.resolve(pending));
        const stop = startPlanPreview({
            fetch,
            onUpdate: () => {},
            retry: true,
        });
        await vi.advanceTimersByTimeAsync(PLAN_POLL_MS * 2);
        stop();

        expect(fetch.mock.calls.map(([retry]) => retry)).toEqual([
            true,
            false,
            false,
        ]);
    });

    test('a request error is reported', async () => {
        const updates: PlanPreviewUpdate[] = [];

        startPlanPreview({
            fetch: () => Promise.reject(new Error('Server error')),
            onUpdate: (u) => updates.push(u),
            retry: false,
        });
        await vi.advanceTimersByTimeAsync(0);

        expect(updates).toEqual([{ preview: null, error: 'Server error' }]);
    });
});
