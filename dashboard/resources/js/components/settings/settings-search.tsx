import { CornerDownLeft, Search, X } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import type { KeyboardEvent, RefObject } from 'react';
import { cn } from '@/lib/utils';
import { groupResults, searchSettings } from './search';
import type { SettingResult } from './search';

/**
 * The search field at the top of the settings sidebar (and above the phone
 * drill-down), design-system §13.9 "Settings shell": finds single settings ("health", "env",
 * "ssl"), shown grouped by page. ↑/↓ move, Enter opens, Esc clears. While
 * there is a query, the results take the place of the sidebar / drill-down
 * list.
 */
export function SettingsSearch({
    index,
    query,
    onQuery,
    onSelect,
    inputRef,
    emptyHint,
}: {
    index: SettingResult[];
    query: string;
    onQuery: (query: string) => void;
    onSelect: (result: SettingResult) => void;
    inputRef: RefObject<HTMLInputElement | null>;
    /** After "No setting matches …": what to try instead. */
    emptyHint?: string;
}) {
    const id = useId();
    const groups = groupResults(searchSettings(index, query));
    // Keyboard order follows the groups as drawn.
    const flat = groups.flatMap((group) => group.items);
    const [active, setActive] = useState(0);
    const open = query.trim() !== '';
    const activeItem = flat[Math.min(active, flat.length - 1)];

    useEffect(() => setActive(0), [query]);

    const optionId = (result: SettingResult) =>
        `${id}-${result.key.replace(/[^a-z0-9]+/gi, '-')}`;

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        switch (event.key) {
            case 'ArrowDown':
                if (flat.length > 0) {
                    event.preventDefault();
                    setActive((index) => (index + 1) % flat.length);
                }

                break;
            case 'ArrowUp':
                if (flat.length > 0) {
                    event.preventDefault();
                    setActive(
                        (index) => (index - 1 + flat.length) % flat.length,
                    );
                }

                break;
            case 'Enter':
                if (activeItem) {
                    event.preventDefault();
                    onSelect(activeItem);
                }

                break;
            case 'Escape':
                // Keep the panel open: Esc clears first, then leaves the field.
                event.preventDefault();

                if (query !== '') {
                    onQuery('');
                } else {
                    event.currentTarget.blur();
                }

                break;
        }
    };

    return (
        <div className="flex flex-col gap-3">
            <div className="relative flex items-center">
                <Search
                    aria-hidden="true"
                    className="pointer-events-none absolute left-2.5 size-4 text-muted-foreground"
                />
                <input
                    ref={inputRef}
                    type="search"
                    role="combobox"
                    aria-label="Search settings"
                    aria-expanded={open}
                    aria-controls={`${id}-results`}
                    aria-autocomplete="list"
                    aria-activedescendant={
                        open && activeItem ? optionId(activeItem) : undefined
                    }
                    value={query}
                    onChange={(event) => onQuery(event.target.value)}
                    onKeyDown={onKeyDown}
                    placeholder="Search settings"
                    autoComplete="off"
                    spellCheck={false}
                    className="h-9 w-full min-w-0 rounded-lg border bg-background pr-9 pl-8 type-body shadow-card transition-[border-color,box-shadow] duration-fast outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 [&::-webkit-search-cancel-button]:hidden"
                />
                {query === '' ? (
                    <kbd
                        aria-hidden="true"
                        className="pointer-events-none absolute right-2 rounded-sm border px-1.5 font-sans type-small text-muted-foreground"
                    >
                        /
                    </kbd>
                ) : (
                    <button
                        type="button"
                        onClick={() => {
                            onQuery('');
                            inputRef.current?.focus();
                        }}
                        aria-label="Clear search"
                        className="absolute right-1 flex size-7 items-center justify-center rounded-md text-muted-foreground transition-colors duration-fast hover:bg-hover hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <X aria-hidden="true" className="size-3.5" />
                    </button>
                )}
            </div>

            {open && (
                <div
                    id={`${id}-results`}
                    role="listbox"
                    aria-label="Matching settings"
                    className="flex flex-col gap-4 motion-safe:animate-in motion-safe:duration-base motion-safe:ease-enter motion-safe:fade-in-0"
                >
                    {groups.length === 0 ? (
                        <p className="px-2.5 type-small text-muted-foreground">
                            No setting matches “{query.trim()}”.
                            {emptyHint && ` ${emptyHint}`}
                        </p>
                    ) : (
                        groups.map((group) => (
                            <div
                                key={group.group}
                                role="group"
                                aria-labelledby={`${id}-${group.group.replace(/\W+/g, '-')}`}
                                className="flex flex-col gap-0.5 @max-3xl/settings:gap-0 @max-3xl/settings:divide-y @max-3xl/settings:overflow-hidden @max-3xl/settings:rounded-xl @max-3xl/settings:border @max-3xl/settings:bg-card @max-3xl/settings:shadow-card"
                            >
                                <span
                                    id={`${id}-${group.group.replace(/\W+/g, '-')}`}
                                    className="px-2.5 pb-1 type-small text-muted-foreground @max-3xl/settings:border-b @max-3xl/settings:bg-recessed @max-3xl/settings:px-4 @max-3xl/settings:py-2"
                                >
                                    {group.group}
                                </span>
                                {group.items.map((result) => {
                                    const selected = result === activeItem;

                                    return (
                                        <div
                                            key={result.key}
                                            id={optionId(result)}
                                            role="option"
                                            aria-selected={selected}
                                            onMouseEnter={() =>
                                                setActive(flat.indexOf(result))
                                            }
                                            // Keep focus in the field until the click lands.
                                            onMouseDown={(event) =>
                                                event.preventDefault()
                                            }
                                            onClick={() => onSelect(result)}
                                            className={cn(
                                                'group flex cursor-pointer items-center gap-2 rounded-lg px-2.5 py-1.5 transition-colors duration-fast @max-3xl/settings:min-h-14 @max-3xl/settings:rounded-none @max-3xl/settings:px-4 @max-3xl/settings:py-2.5',
                                                selected
                                                    ? 'bg-hover text-foreground'
                                                    : 'text-muted-foreground',
                                            )}
                                        >
                                            <span className="flex min-w-0 flex-1 flex-col">
                                                <span className="truncate type-body text-foreground">
                                                    {result.label}
                                                </span>
                                                <span className="truncate type-small text-muted-foreground">
                                                    {result.hint}
                                                </span>
                                            </span>
                                            <CornerDownLeft
                                                aria-hidden="true"
                                                className={cn(
                                                    'size-3.5 shrink-0 text-faint',
                                                    !selected && 'invisible',
                                                )}
                                            />
                                        </div>
                                    );
                                })}
                            </div>
                        ))
                    )}
                </div>
            )}
        </div>
    );
}
