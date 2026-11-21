import { formatWindow } from '@/lib/observe-scope';

/**
 * A stretch of a time chart without any report (design-system §9 "Gaps"):
 * epoch ms from the first empty bucket to the next bucket with data (or the
 * end of the chart), and the bucket indexes it covers (inclusive).
 */
export type ChartGap = {
    from: number;
    to: number;
    first: number;
    last: number;
    /** Runs to the newest bucket: the server isn't reporting now. */
    ongoing: boolean;
};

/**
 * The stretches where no series has a value, on series that share their
 * timestamps. Leading empty buckets are left alone (the server wasn't
 * reporting yet, or the range starts before it joined); a gap between two
 * reports needs `minBuckets` empty buckets, a gap that runs to the end
 * `minTrailing` (the newest bucket is often still being filled).
 */
export function findGaps(
    times: number[],
    series: (number | null)[][],
    { minBuckets = 2, minTrailing = 3 } = {},
): ChartGap[] {
    const step = times.length > 1 ? times[1] - times[0] : 0;
    const present = times.map((_, index) =>
        series.some((values) => values[index] != null),
    );
    const firstData = present.indexOf(true);

    if (firstData < 0) {
        return [];
    }

    const gaps: ChartGap[] = [];
    let start: number | null = null;

    for (let index = firstData; index <= times.length; index++) {
        const hasData = index < times.length && present[index];

        if (!hasData && index < times.length) {
            start ??= index;
            continue;
        }

        if (start !== null) {
            const length = index - start;
            const trailing = index === times.length;

            if (length >= (trailing ? minTrailing : minBuckets)) {
                gaps.push({
                    from: times[start],
                    to: trailing
                        ? times[times.length - 1] + step
                        : times[index],
                    first: start,
                    last: index - 1,
                    ongoing: trailing,
                });
            }

            start = null;
        }
    }

    return gaps;
}

/** The gap a bucket falls in, if any. */
export function gapAt(gaps: ChartGap[], index: number): ChartGap | null {
    return gaps.find((gap) => index >= gap.first && index <= gap.last) ?? null;
}

/**
 * The gap's tooltip and accessible text: "No reports 18:30 to 09:00", or
 * "No reports since 18:30" while it lasts.
 */
export function gapLabel(gap: ChartGap): string {
    if (!gap.ongoing) {
        return `No reports ${formatWindow(gap)}`;
    }

    const since = new Date(gap.from);
    const time = since.toLocaleTimeString('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });
    const today = since.toDateString() === new Date().toDateString();

    return `No reports since ${today ? '' : `${since.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })} `}${time}`;
}
