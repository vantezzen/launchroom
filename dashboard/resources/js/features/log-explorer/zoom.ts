import type { ObserveWindow } from '@/lib/observe-scope';

/** Bucket widths the zoomed histogram picks from (ms). */
const STEPS = [
    60_000, 300_000, 900_000, 1_800_000, 3_600_000, 10_800_000, 21_600_000,
    43_200_000, 86_400_000,
];

/** The smallest window a zoom shows: one line still gets some context. */
const MIN_SPAN = 10 * 60_000;

export type HistogramZoom = ObserveWindow & {
    /** Buckets in the window, each `(to - from) / count` wide. */
    count: number;
};

/**
 * Where the histogram zooms to when the lines sit in a small part of the
 * range (design-system §13.8 "Histogram"): the span from the first to the
 * last line, padded by a tenth on each side, snapped to a round bucket
 * width with at most `maxBuckets` buckets and clamped to the range. Null
 * when the lines cover more than `maxShare` of the range (the range is
 * already the right view) or there are none.
 */
export function histogramZoom(
    extent: ObserveWindow | null,
    range: ObserveWindow,
    { maxShare = 1 / 3, maxBuckets = 60 } = {},
): HistogramZoom | null {
    const rangeSpan = range.to - range.from;

    if (extent === null || rangeSpan <= 0) {
        return null;
    }

    const lines = Math.max(0, extent.to - extent.from);

    if (lines > rangeSpan * maxShare) {
        return null;
    }

    const pad = Math.max(lines * 0.1, (MIN_SPAN - lines) / 2, 0);
    const want = lines + 2 * pad;
    const step =
        STEPS.find((width) => want / width <= maxBuckets) ??
        STEPS[STEPS.length - 1];
    const from = Math.max(
        range.from,
        Math.floor((extent.from - pad) / step) * step,
    );
    const to = Math.min(range.to, Math.ceil((extent.to + pad) / step) * step);

    // Clamping can leave most of the range: then there's nothing to zoom.
    if (to - from > rangeSpan / 2 || to <= from) {
        return null;
    }

    return { from, to, count: Math.max(1, Math.round((to - from) / step)) };
}

/** First and last line time (epoch ms) of loaded lines, or null. */
export function linesExtent(times: number[]): ObserveWindow | null {
    const valid = times.filter((t) => Number.isFinite(t));

    if (valid.length === 0) {
        return null;
    }

    return { from: Math.min(...valid), to: Math.max(...valid) };
}

/** First and last bucket with lines, or null. */
export function bucketsExtent(
    buckets: { start: number; end: number; total: number }[],
): ObserveWindow | null {
    const filled = buckets.filter((bucket) => bucket.total > 0);

    if (filled.length === 0) {
        return null;
    }

    return { from: filled[0].start, to: filled[filled.length - 1].end };
}
