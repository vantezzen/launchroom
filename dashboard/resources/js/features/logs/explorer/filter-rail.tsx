import { useState } from 'react';
import { Checkbox } from '@/components/ui/checkbox';
import { formatCompact } from '@/components/charts/format';
import { cn } from '@/lib/utils';

export type FilterOption = {
    value: string;
    label: string;
    count: number;
    /** Render the label in mono (SHAs, hostnames). */
    mono?: boolean;
};

export type FilterGroup = {
    key: string;
    label: string;
    options: FilterOption[];
};

/**
 * Facet rail (design-system §5.5): titled groups of checkboxes
 * with counts right-aligned. Groups longer than `collapseAfter` show
 * "Show N more". Zero-count options stay visible but quiet, so a filter
 * never disappears from under the pointer.
 */
export function FilterRail({
    groups,
    selected,
    onToggle,
    collapseAfter = 6,
    className,
}: {
    groups: FilterGroup[];
    /** Selected values per group key. */
    selected: Record<string, string[]>;
    onToggle: (group: string, value: string) => void;
    collapseAfter?: number;
    className?: string;
}) {
    const [expanded, setExpanded] = useState<Record<string, boolean>>({});

    return (
        <nav
            aria-label="Filters"
            className={cn('flex flex-col gap-6', className)}
        >
            {groups.map((group) => {
                const all = group.options;
                const open = expanded[group.key] ?? false;
                const shown = open ? all : all.slice(0, collapseAfter);

                return (
                    <fieldset key={group.key} className="flex flex-col gap-0.5">
                        <legend className="mb-1.5 type-label text-muted-foreground">
                            {group.label}
                        </legend>
                        {shown.map((option) => {
                            const id = `filter-${group.key}-${option.value}`;
                            const checked =
                                selected[group.key]?.includes(option.value) ??
                                false;

                            return (
                                <label
                                    key={option.value}
                                    htmlFor={id}
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
                                            onToggle(group.key, option.value)
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
                        {all.length > collapseAfter && (
                            <button
                                type="button"
                                onClick={() =>
                                    setExpanded((state) => ({
                                        ...state,
                                        [group.key]: !open,
                                    }))
                                }
                                className="mt-0.5 self-start rounded-md px-1.5 py-1 type-small text-muted-foreground hover:text-foreground focus-visible:outline-2 focus-visible:outline-(--signal)"
                            >
                                {open
                                    ? 'Show less'
                                    : `Show ${all.length - collapseAfter} more`}
                            </button>
                        )}
                    </fieldset>
                );
            })}
        </nav>
    );
}
