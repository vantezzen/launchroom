import {
    afterEach,
    beforeEach,
    describe,
    expect,
    test,
    vi,
} from 'vite-plus/test';
import { schedulePoll } from './use-live-props';

class FakeDocument extends EventTarget {
    visibilityState: 'visible' | 'hidden' = 'visible';

    setVisibility(state: 'visible' | 'hidden') {
        this.visibilityState = state;
        this.dispatchEvent(new Event('visibilitychange'));
    }
}

let doc: FakeDocument;

beforeEach(() => {
    vi.useFakeTimers();
    doc = new FakeDocument();
    vi.stubGlobal('window', globalThis);
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

describe('live props polling', () => {
    test('ticks on the interval while visible', () => {
        const tick = vi.fn();
        const stop = schedulePoll(tick, 4000, doc);

        vi.advanceTimersByTime(12_000);
        expect(tick).toHaveBeenCalledTimes(3);

        stop();
        vi.advanceTimersByTime(12_000);
        expect(tick).toHaveBeenCalledTimes(3);
    });

    test('pauses while hidden and catches up once when shown', () => {
        const tick = vi.fn();
        const stop = schedulePoll(tick, 4000, doc);

        doc.setVisibility('hidden');
        vi.advanceTimersByTime(20_000);
        expect(tick).not.toHaveBeenCalled();

        doc.setVisibility('visible');
        expect(tick).toHaveBeenCalledTimes(1);

        stop();
        doc.setVisibility('hidden');
        doc.setVisibility('visible');
        expect(tick).toHaveBeenCalledTimes(1);
    });
});
