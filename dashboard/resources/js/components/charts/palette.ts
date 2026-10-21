/**
 * Data colours (design-system §3.4, §9; maintainer decision §5 #2):
 * - one series → the signal accent (`SIGNAL`), nothing else;
 * - comparing series → the categorical palette in fixed slot order, at most
 *   `MAX_SERIES` coloured items, everything else folded into a grey "other".
 * Colour follows the entity (a project's stored `color_slot`), never its rank.
 */
export const SIGNAL = 'var(--signal)';
export const OTHER = 'var(--chart-other)';
export const TRACK = 'var(--border)';
export const MAX_SERIES = 4;
export const PALETTE_SIZE = 8;

/** CSS colour for a palette slot (1-based; slots past 8 wrap in order). */
export function colorForSlot(slot: number): string {
    const index =
        ((((Math.trunc(slot) - 1) % PALETTE_SIZE) + PALETTE_SIZE) %
            PALETTE_SIZE) +
        1;

    return `var(--chart-${index})`;
}

export type Breakdown = {
    /** Stable key (project id, service id …). */
    key: string;
    label: string;
    value: number;
    /** Palette slot owned by the entity; omitted → assigned by position. */
    slot?: number | null;
    /**
     * Always grey: folded into "other" whatever its size (e.g. the
     * backend's own "Other" row, unattributed usage).
     */
    other?: boolean;
};

export type ColouredBreakdown = Breakdown & {
    color: string;
    /** True for the folded "other" bucket. */
    other: boolean;
};

/**
 * Sorts by value, keeps the top `max` items in their own colour and folds the
 * rest, plus every item marked `other`, into one grey "other" item (omitted
 * when it would be empty).
 */
export function foldToOther(
    items: Breakdown[],
    max = MAX_SERIES,
    otherLabel = 'Other',
): ColouredBreakdown[] {
    const sorted = items
        .filter((item) => item.value > 0 && !item.other)
        .sort((a, b) => b.value - a.value);
    const forced = items.filter((item) => item.value > 0 && item.other);
    const kept = sorted.slice(0, max);
    const rest = [...sorted.slice(max), ...forced];

    const coloured: ColouredBreakdown[] = kept.map((item, index) => ({
        ...item,
        color: colorForSlot(item.slot ?? index + 1),
        other: false,
    }));

    const restValue = rest.reduce((sum, item) => sum + item.value, 0);

    if (restValue > 0) {
        coloured.push({
            key: '__other',
            label:
                rest.length === 1 && forced.length === 1
                    ? forced[0].label
                    : `${otherLabel} (${rest.length})`,
            value: restValue,
            color: OTHER,
            other: true,
        });
    }

    return coloured;
}
