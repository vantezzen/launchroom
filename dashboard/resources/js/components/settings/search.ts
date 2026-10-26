import type { SettingsNavGroup } from './types';

/**
 * One searchable setting (design-system §13.9 "Settings shell"): its row
 * title, one quiet hint, and the words people type for it. `target` is the
 * page it lives on (or another place the surface handles in `onSelectOther`).
 * `anchor` is the `data-setting` of the row or card on that page
 * (`SettingsRow`/`SettingsCard` `setting` prop); when that row isn't drawn
 * right now, `fallback` is tried, then the page's top.
 */
export type SettingEntry<Target extends string = string> = {
    key: string;
    target: Target;
    label: string;
    hint: string;
    keywords: string[];
    anchor?: string;
    fallback?: string;
};

export type SettingResult<Target extends string = string> =
    SettingEntry<Target> & {
        /** The page (or tab) name the result is grouped under. */
        group: string;
    };

/** An index row before filtering: `when: false` leaves it out. */
export type SettingDraft<Target extends string = string> = Omit<
    SettingEntry<Target>,
    'key'
> & { when?: boolean };

/**
 * Turns drafts into the search index of one settings surface. A draft
 * whose target is neither a page in `groups` nor named in `extra` (a
 * target outside the settings) is left out, so
 * the index follows the same visibility rules as the sidebar.
 * `pages: true` adds one entry per page first (its label, its summary as
 * the hint), for surfaces whose pages hold few rows.
 */
export function buildSettingsIndex<Target extends string>(
    drafts: SettingDraft<Target>[],
    groups: SettingsNavGroup<Target>[],
    options: {
        extra?: Partial<Record<string, string>>;
        pages?: boolean;
    } = {},
): SettingResult<Target>[] {
    const labels = new Map<string, string>(
        groups.flatMap((group) =>
            group.pages.map((page) => [page.id, page.label] as const),
        ),
    );
    Object.entries(options.extra ?? {}).forEach(([target, label]) => {
        if (label !== undefined) {
            labels.set(target, label);
        }
    });

    const pageEntries: SettingDraft<Target>[] = options.pages
        ? groups.flatMap((group) =>
              group.pages.map((page) => ({
                  target: page.id,
                  label: page.label,
                  hint: page.summary ?? group.label ?? 'Settings',
                  keywords: page.keywords ?? [],
              })),
          )
        : [];

    return [...pageEntries, ...drafts]
        .filter((draft) => draft.when !== false)
        .flatMap(({ when: _when, ...entry }) => {
            const group = labels.get(entry.target);

            return group === undefined
                ? []
                : [{ ...entry, key: `${entry.target}:${entry.label}`, group }];
        });
}

function normalize(value: string): string {
    return value
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9./]+/g, ' ')
        .trim();
}

/**
 * Every word of the query must start a word of the label, page name or
 * keywords (prefix match, so "health" finds "Health check" and "env"
 * finds Variables). Ranked: label matches first, then page, then keywords;
 * the index order breaks ties.
 */
export function searchSettings<Target extends string>(
    index: SettingResult<Target>[],
    query: string,
): SettingResult<Target>[] {
    const terms = normalize(query).split(' ').filter(Boolean);

    if (terms.length === 0) {
        return [];
    }

    const score = (entry: SettingResult<Target>): number => {
        const label = normalize(entry.label);
        const group = normalize(entry.group);
        const keywords = entry.keywords.map(normalize).join(' ');
        const words = (text: string) => text.split(' ');
        const hit = (text: string, term: string) =>
            words(text).some((word) => word.startsWith(term)) ||
            (term.length > 2 && text.includes(term));
        let total = 0;

        for (const term of terms) {
            if (hit(label, term)) {
                total += label.startsWith(term) ? 4 : 3;
            } else if (hit(group, term)) {
                total += 2;
            } else if (hit(keywords, term)) {
                total += 1;
            } else {
                return 0;
            }
        }

        return total;
    };

    return index
        .map((entry, order) => ({ entry, order, score: score(entry) }))
        .filter((item) => item.score > 0)
        .sort((a, b) => b.score - a.score || a.order - b.order)
        .map((item) => item.entry);
}

/** Results grouped by page, groups in the order of their best result. */
export function groupResults<Target extends string>(
    results: SettingResult<Target>[],
): { group: string; items: SettingResult<Target>[] }[] {
    const groups = new Map<string, SettingResult<Target>[]>();

    results.forEach((result) => {
        groups.set(result.group, [...(groups.get(result.group) ?? []), result]);
    });

    return Array.from(groups, ([group, items]) => ({ group, items }));
}
