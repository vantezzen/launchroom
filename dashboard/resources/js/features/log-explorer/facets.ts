import type { HistogramBucket } from '@/components/charts/histogram';
import type { FilterGroup } from './filter-rail';
import type { LogEntry } from './types';
import { LEVEL_LABEL, LEVEL_ORDER } from './types';

/** Facet counts for the filter rail from loaded entries (live tails, demos). */
export function countFacets(entries: LogEntry[]): FilterGroup[] {
    const count = (pick: (entry: LogEntry) => string | null | undefined) => {
        const counts = new Map<string, number>();

        for (const entry of entries) {
            const value = pick(entry);

            if (value) {
                counts.set(value, (counts.get(value) ?? 0) + 1);
            }
        }

        return [...counts.entries()].sort((a, b) => b[1] - a[1]);
    };

    const levels = new Map(count((entry) => entry.level));

    return [
        {
            key: 'level',
            label: 'Level',
            options: LEVEL_ORDER.map((level) => ({
                value: level,
                label: LEVEL_LABEL[level],
                count: levels.get(level) ?? 0,
            })),
        },
        {
            key: 'project',
            label: 'Project',
            options: count((entry) => entry.project).map(([value, n]) => ({
                value,
                label: value,
                count: n,
            })),
        },
        {
            key: 'environment',
            label: 'Environment',
            options: count((entry) => entry.environment).map(([value, n]) => ({
                value,
                label: value,
                count: n,
            })),
        },
        {
            key: 'service',
            label: 'Service',
            options: count((entry) => entry.service).map(([value, n]) => ({
                value,
                label: value,
                count: n,
            })),
        },
        {
            key: 'deployment',
            label: 'Deployment',
            options: count((entry) => entry.deployment).map(([value, n]) => ({
                value,
                label: value,
                count: n,
                mono: true,
            })),
        },
        {
            key: 'server',
            label: 'Server',
            options: count((entry) => entry.server).map(([value, n]) => ({
                value,
                label: value,
                count: n,
            })),
        },
    ].filter((group) => group.options.length > 0);
}

/** Buckets entries into `count` equal slots between `from` and `to` (epoch ms). */
export function bucketEntries(
    entries: LogEntry[],
    from: number,
    to: number,
    count = 60,
): HistogramBucket[] {
    const size = Math.max(1, (to - from) / count);
    const buckets: HistogramBucket[] = Array.from(
        { length: count },
        (_, index) => ({
            start: from + index * size,
            end: from + (index + 1) * size,
            total: 0,
            errors: 0,
        }),
    );

    for (const entry of entries) {
        const t = Date.parse(entry.time);
        const index = Math.floor((t - from) / size);

        if (index >= 0 && index < count) {
            buckets[index].total++;

            if (entry.level === 'error') {
                buckets[index].errors = (buckets[index].errors ?? 0) + 1;
            }
        }
    }

    return buckets;
}
