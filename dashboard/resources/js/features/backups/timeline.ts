import { dayLabel } from '@/lib/format';

export type DayGroup<T> = { key: string; label: string; items: T[] };

/**
 * Restore points grouped by the viewer's calendar day, newest day first
 * ("Today", "Yesterday", "Monday 3 November"), keeping the input order
 * inside each day.
 */
export function groupByDay<T>(
    items: T[],
    timeOf: (item: T) => string,
    now: Date = new Date(),
): DayGroup<T>[] {
    const groups: DayGroup<T>[] = [];

    for (const item of items) {
        const at = new Date(timeOf(item));
        const key = `${at.getFullYear()}-${at.getMonth() + 1}-${at.getDate()}`;
        const existing = groups.find((group) => group.key === key);

        if (existing !== undefined) {
            existing.items.push(item);
            continue;
        }

        groups.push({ key, label: dayLabel(timeOf(item), now), items: [item] });
    }

    return groups;
}
