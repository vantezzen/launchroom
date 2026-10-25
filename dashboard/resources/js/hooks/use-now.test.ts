import {
    afterEach,
    beforeEach,
    describe,
    expect,
    test,
    vi,
} from 'vite-plus/test';
import { subscribe, tickerState } from './use-now';

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
    vi.stubGlobal('document', doc);
    vi.stubGlobal('window', globalThis);
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

describe('shared clock', () => {
    test('every timer on the page shares one interval', () => {
        const a = vi.fn();
        const b = vi.fn();
        const offA = subscribe(a);
        const offB = subscribe(b);

        expect(tickerState()).toEqual({ listeners: 2, running: true });
        a.mockClear();
        b.mockClear();

        vi.advanceTimersByTime(3000);
        expect(a).toHaveBeenCalledTimes(3);
        expect(b).toHaveBeenCalledTimes(3);
        expect(vi.getTimerCount()).toBe(1);

        offA();
        offB();
        expect(tickerState()).toEqual({ listeners: 0, running: false });
        expect(vi.getTimerCount()).toBe(0);
    });

    test('pauses while the tab is hidden and ticks once on return', () => {
        const listener = vi.fn();
        const off = subscribe(listener);
        listener.mockClear();

        doc.setVisibility('hidden');
        expect(tickerState().running).toBe(false);
        vi.advanceTimersByTime(10_000);
        expect(listener).not.toHaveBeenCalled();

        doc.setVisibility('visible');
        expect(listener).toHaveBeenCalledTimes(1);
        expect(tickerState().running).toBe(true);

        off();
    });
});
