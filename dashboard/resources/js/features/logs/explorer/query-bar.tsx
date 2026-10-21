import { Search, X } from 'lucide-react';
import { useEffect, useRef } from 'react';
import { cn } from '@/lib/utils';
import { parseLogQuery, serializeLogQuery } from './query';

/**
 * Log query input (design-system §5.5): free text plus `service:`, `level:`,
 * `deployment:`, `server:`, `stream:` filters (prefix `-` to exclude, quote
 * phrases). Parsed filters show as removable chips under the field. `/`
 * focuses it from anywhere on the page; Escape clears.
 */
export function QueryBar({
    value,
    onChange,
    placeholder = 'Search logs   service:api level:error "timeout"',
    className,
}: {
    value: string;
    onChange: (value: string) => void;
    placeholder?: string;
    className?: string;
}) {
    const input = useRef<HTMLInputElement>(null);
    const query = parseLogQuery(value);

    useEffect(() => {
        const onKey = (event: globalThis.KeyboardEvent) => {
            const target = event.target as HTMLElement | null;

            if (
                event.key === '/' &&
                !event.metaKey &&
                !event.ctrlKey &&
                !(
                    target &&
                    (target.isContentEditable ||
                        ['INPUT', 'TEXTAREA', 'SELECT'].includes(
                            target.tagName,
                        ))
                )
            ) {
                event.preventDefault();
                input.current?.focus();
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

    return (
        <div className={cn('flex flex-col gap-2', className)}>
            <div className="relative flex items-center">
                <Search
                    aria-hidden="true"
                    className="pointer-events-none absolute left-3 size-4 text-muted-foreground"
                />
                <input
                    ref={input}
                    type="search"
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Escape' && value !== '') {
                            event.preventDefault();
                            onChange('');
                        }
                    }}
                    aria-label="Search logs"
                    placeholder={placeholder}
                    spellCheck={false}
                    autoComplete="off"
                    className="h-9 w-full rounded-lg border border-(--border-strong) bg-background pr-10 pl-9 type-mono text-foreground placeholder:font-sans placeholder:text-muted-foreground focus-visible:border-(--signal) focus-visible:ring-2 focus-visible:ring-(--signal)/25 focus-visible:outline-none [&::-webkit-search-cancel-button]:hidden"
                />
                <kbd className="pointer-events-none absolute right-3 rounded border px-1 type-mono-small text-muted-foreground max-sm:hidden">
                    /
                </kbd>
            </div>
            {query.filters.length > 0 && (
                <ul
                    className="flex flex-wrap gap-1.5"
                    aria-label="Active filters"
                >
                    {query.filters.map((filter, index) => (
                        <li key={`${filter.key}:${filter.value}:${index}`}>
                            <button
                                type="button"
                                onClick={() =>
                                    onChange(
                                        serializeLogQuery({
                                            ...query,
                                            filters: query.filters.filter(
                                                (_, i) => i !== index,
                                            ),
                                        }),
                                    )
                                }
                                className="inline-flex h-6 items-center gap-1 rounded-full border bg-card pr-1.5 pl-2.5 type-small hover:border-(--border-strong) focus-visible:outline-2 focus-visible:outline-(--signal)"
                                // Name starts with the visible text (axe
                                // label-content-name-mismatch).
                                aria-label={`${filter.negated ? 'not ' : ''}${filter.key} ${filter.value}: remove filter`}
                            >
                                <span className="text-muted-foreground">
                                    {filter.negated ? 'not ' : ''}
                                    {filter.key}
                                </span>{' '}
                                <span className="font-medium">
                                    {filter.value}
                                </span>
                                <X
                                    aria-hidden="true"
                                    className="size-3 text-muted-foreground"
                                />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
