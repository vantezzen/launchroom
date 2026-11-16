import { ChevronDown } from 'lucide-react';
import { useState } from 'react';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { formatCompact } from '@/lib/format';

type FilterOption = {
    value: string;
    label: string;
    count: number;
    /** Render the label in mono (SHAs, hostnames). */
    mono?: boolean;
    /** Tooltip when the label is shortened (long deployment ids). */
    title?: string;
};

export type FilterGroup = {
    key: string;
    label: string;
    options: FilterOption[];
};

/** Groups with more values than this get a search field. */
const SEARCH_AFTER = 8;

/**
 * Facet rail (design-system §5.5): titled groups of checkboxes with counts
 * right-aligned. `defaultOpen` groups start open, the others collapsed with
 * their selected count beside the title, so the rail stays short. Long
 * groups get a search field and "Show N more"; "Reset" at the top clears
 * every facet. Zero-count options stay visible but quiet, so a filter
 * never disappears from under the pointer.
 */
export function FilterRail({
    groups,
    selected,
    onToggle,
    onReset,
    defaultOpen = ['level', 'service'],
    collapseAfter = 6,
    className,
}: {
    groups: FilterGroup[];
    /** Selected values per group key. */
    selected: Record<string, string[]>;
    onToggle: (group: string, value: string) => void;
    /** Clears every facet; the link shows while one is set. */
    onReset?: () => void;
    defaultOpen?: string[];
    collapseAfter?: number;
    className?: string;
}) {
    const [expanded, setExpanded] = useState<Record<string, boolean>>({});
    const [open, setOpen] = useState<Record<string, boolean>>({});
    const [search, setSearch] = useState<Record<string, string>>({});
    const anySelected = Object.values(selected).some(
        (values) => values.length > 0,
    );

    return (
        <nav
            aria-label="Filters"
            className={cn('flex flex-col gap-4', className)}
        >
            {onReset && anySelected && (
                <button
                    type="button"
                    onClick={onReset}
                    className="-mb-2 self-end rounded-md px-1.5 py-0.5 type-small text-muted-foreground hover:text-foreground focus-visible:outline-2 focus-visible:outline-(--signal)"
                >
                    Reset
                </button>
            )}
            {groups.map((group) => {
                const picked = selected[group.key]?.length ?? 0;
                const isOpen =
                    open[group.key] ?? defaultOpen.includes(group.key);
                const term = (search[group.key] ?? '').trim().toLowerCase();
                const all =
                    term === ''
                        ? group.options
                        : group.options.filter((option) =>
                              option.value.toLowerCase().includes(term),
                          );
                const more = expanded[group.key] ?? false;
                const shown =
                    more || term !== '' ? all : all.slice(0, collapseAfter);
                const panelId = `filter-group-${group.key}`;

                return (
                    <fieldset key={group.key} className="flex flex-col gap-0.5">
                        <legend className="contents">
                            <button
                                type="button"
                                aria-expanded={isOpen}
                                aria-controls={panelId}
                                onClick={() =>
                                    setOpen((state) => ({
                                        ...state,
                                        [group.key]: !isOpen,
                                    }))
                                }
                                className="mb-1 flex h-7 items-center gap-1 rounded-md px-1.5 text-left type-label text-muted-foreground hover:text-foreground focus-visible:outline-2 focus-visible:outline-(--signal)"
                            >
                                <ChevronDown
                                    aria-hidden="true"
                                    className={cn(
                                        'size-3.5 transition-transform duration-fast',
                                        !isOpen && '-rotate-90',
                                    )}
                                />
                                {group.label}
                                {picked > 0 && (
                                    <span className="text-foreground tabular-nums">
                                        ({picked})
                                    </span>
                                )}
                            </button>
                        </legend>
                        <div
                            id={panelId}
                            hidden={!isOpen}
                            className="flex flex-col gap-0.5"
                        >
                            {group.options.length > SEARCH_AFTER && (
                                <Input
                                    type="search"
                                    value={search[group.key] ?? ''}
                                    onChange={(event) =>
                                        setSearch((state) => ({
                                            ...state,
                                            [group.key]: event.target.value,
                                        }))
                                    }
                                    placeholder={`Search ${group.label.toLowerCase()}`}
                                    aria-label={`Search ${group.label.toLowerCase()}`}
                                    className="mb-1 h-8"
                                />
                            )}
                            {shown.map((option) => {
                                const id = `filter-${group.key}-${option.value}`;
                                const checked =
                                    selected[group.key]?.includes(
                                        option.value,
                                    ) ?? false;

                                return (
                                    <label
                                        key={option.value}
                                        htmlFor={id}
                                        title={option.title}
                                        className={cn(
                                            'flex h-8 cursor-pointer items-center gap-2.5 rounded-md px-1.5 hover:bg-(--hover)',
                                            option.count === 0 &&
                                                !checked &&
                                                'text-muted-foreground',
                                        )}
                                    >
                                        <Checkbox
                                            id={id}
                                            checked={checked}
                                            onCheckedChange={() =>
                                                onToggle(
                                                    group.key,
                                                    option.value,
                                                )
                                            }
                                        />
                                        <span
                                            className={cn(
                                                'min-w-0 flex-1 truncate',
                                                option.mono
                                                    ? 'type-mono-small'
                                                    : 'type-body',
                                            )}
                                        >
                                            {option.label}
                                        </span>
                                        <span className="type-small text-muted-foreground tabular-nums">
                                            {formatCompact(option.count)}
                                        </span>
                                    </label>
                                );
                            })}
                            {term !== '' && all.length === 0 && (
                                <p className="px-1.5 py-1 type-small text-muted-foreground">
                                    No match.
                                </p>
                            )}
                            {term === '' && all.length > collapseAfter && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        setExpanded((state) => ({
                                            ...state,
                                            [group.key]: !more,
                                        }))
                                    }
                                    className="mt-0.5 self-start rounded-md px-1.5 py-1 type-small text-muted-foreground hover:text-foreground focus-visible:outline-2 focus-visible:outline-(--signal)"
                                >
                                    {more
                                        ? 'Show less'
                                        : `Show ${all.length - collapseAfter} more`}
                                </button>
                            )}
                        </div>
                    </fieldset>
                );
            })}
        </nav>
    );
}
