import type { ChartMarker } from './time-series-chart';

export type MarkerGroup = {
    /** Pixel position of the tick: the mean of its markers. */
    x: number;
    /** Oldest first. */
    markers: ChartMarker[];
    tone: 'signal' | 'error';
    /** The marker's own label, or "3 deploys" for a group. */
    label: string;
    /** Whether the label fits without touching another label or the edges. */
    showLabel: boolean;
    /** Label on the right of the tick ('start') or, near the right edge, on its left ('end'). */
    anchor: 'start' | 'end';
    /** Estimated label width in px (monospace, 10 px). */
    labelWidth: number;
};

/** Markers closer than this (px) share one tick. */
export const MERGE_PX = 8;
/** Minimum space between two labels (px). */
const LABEL_GAP = 6;
/** Distance between the tick and its label (px). */
export const LABEL_OFFSET = 4;
/** Average advance of the 10 px monospace label font (px per character). */
const CHAR_PX = 6.1;

/**
 * Groups markers that would sit on top of each other (one deploy of several
 * services, a quick redeploy) into one tick with a count, and keeps only the
 * labels that fit: left to right, a label is shown when it doesn't touch the
 * previous shown label or leave the chart. Hidden labels stay readable on
 * hover or focus.
 */
export function groupMarkers(
    markers: ChartMarker[],
    x: (t: number) => number,
    width: number,
    /** What a group counts: "3 deploys". */
    plural = 'deploys',
): MarkerGroup[] {
    const sorted = [...markers].sort((a, b) => a.t - b.t);
    const clusters: { xs: number[]; markers: ChartMarker[] }[] = [];

    for (const marker of sorted) {
        const px = x(marker.t);
        const last = clusters[clusters.length - 1];

        if (last && px - last.xs[last.xs.length - 1] <= MERGE_PX) {
            last.xs.push(px);
            last.markers.push(marker);
        } else {
            clusters.push({ xs: [px], markers: [marker] });
        }
    }

    const ticks = clusters.map(
        (cluster) =>
            cluster.xs.reduce((sum, value) => sum + value, 0) /
            cluster.xs.length,
    );
    let labelEnd = -Infinity;

    return clusters.map((cluster, index) => {
        const gx = ticks[index];
        const prev = index > 0 ? ticks[index - 1] : -Infinity;
        const next = index < ticks.length - 1 ? ticks[index + 1] : Infinity;
        const count = cluster.markers.length;
        const label =
            count === 1 ? cluster.markers[0].label : `${count} ${plural}`;
        const labelWidth = Math.ceil(label.length * CHAR_PX);
        // A label never crosses another tick or label: right of its tick
        // when there is room, else left of it, else only on hover.
        const fits = (left: number) =>
            left >= 0 &&
            left + labelWidth <= width &&
            left >= labelEnd + LABEL_GAP &&
            left > prev + 2 &&
            left + labelWidth < next - 2;
        const rightLeft = gx + LABEL_OFFSET;
        const leftLeft = gx - LABEL_OFFSET - labelWidth;
        const anchor: 'start' | 'end' =
            fits(rightLeft) || !fits(leftLeft) ? 'start' : 'end';
        const showLabel = fits(anchor === 'start' ? rightLeft : leftLeft);

        if (showLabel) {
            labelEnd = (anchor === 'start' ? rightLeft : leftLeft) + labelWidth;
        }

        return {
            x: gx,
            markers: cluster.markers,
            tone: cluster.markers.some((marker) => marker.tone === 'error')
                ? 'error'
                : 'signal',
            label,
            showLabel,
            anchor,
            labelWidth,
        };
    });
}
