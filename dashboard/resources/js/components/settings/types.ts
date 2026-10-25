import type { LucideIcon } from 'lucide-react';

/** One page of a settings surface, as the sidebar and phone list show it. */
export type SettingsNavPage<Id extends string = string> = {
    id: Id;
    label: string;
    icon: LucideIcon;
    /** One quiet line on the phone list ("2 domains", "Name and email"). */
    summary?: string;
    /** A quiet signal dot; the string is its screen-reader text ("Update ready"). */
    dot?: string;
    /** Extra words that find the page in search. */
    keywords?: string[];
};

/**
 * A group of pages. `label` is the small group title; null for groups that
 * need none (General at the top, Danger zone at the bottom). Every page is
 * listed directly: no sub-menus.
 */
export type SettingsNavGroup<Id extends string = string> = {
    id: string;
    label: string | null;
    pages: SettingsNavPage<Id>[];
};

export function allPages<Id extends string>(
    groups: SettingsNavGroup<Id>[],
): SettingsNavPage<Id>[] {
    return groups.flatMap((group) => group.pages);
}
