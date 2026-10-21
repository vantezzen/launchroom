import { useMemo, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

type Scope = App.Data.Variables.ReferenceScopeData;

type Suggestion = {
    insert: string;
    label: string;
    detail: string;
    partial: boolean;
};

const OPEN = /\$\{\{\s*([A-Za-z0-9_.-]*)$/;

/** launchroom's own LAUNCHROOM_* keys last: app variables are what people reference. */
function byRelevance(a: string, b: string): number {
    return (
        Number(a.startsWith('LAUNCHROOM')) - Number(b.startsWith('LAUNCHROOM'))
    );
}

function suggestionsFor(token: string, scopes: Scope[]): Suggestion[] {
    const dot = token.indexOf('.');

    if (dot === -1) {
        const own = scopes.find((scope) => scope.scope === null);
        const needle = token.toLowerCase();
        const scopeItems = scopes
            .filter(
                (scope) =>
                    scope.scope !== null &&
                    scope.scope.toLowerCase().startsWith(needle),
            )
            .map((scope): Suggestion => ({
                insert: `${scope.scope}.`,
                label: `${scope.scope}.`,
                detail: scope.label,
                partial: true,
            }));
        const keyItems = [...(own?.keys ?? [])]
            .sort(byRelevance)
            .filter((key) => key.toLowerCase().startsWith(needle))
            .map((key): Suggestion => ({
                insert: key,
                label: key,
                detail: 'this service',
                partial: false,
            }));

        return [...scopeItems, ...keyItems].slice(0, 50);
    }

    const name = token.slice(0, dot);
    const rest = token.slice(dot + 1).toLowerCase();
    const scope = scopes.find((item) => item.scope === name);

    return [...(scope?.keys ?? [])]
        .sort(byRelevance)
        .filter((key) => key.toLowerCase().startsWith(rest))
        .map((key): Suggestion => ({
            insert: `${name}.${key}`,
            label: `${name}.${key}`,
            detail: scope?.label ?? name,
            partial: false,
        }))
        .slice(0, 50);
}

/**
 * A value field with `${{` autocomplete (docs/06 §3.1): services and shared
 * variables of this environment, then their variable names. Monospace,
 * multiline (values are stored verbatim).
 */
export function ReferenceInput({
    id,
    value,
    onChange,
    scopes,
    placeholder,
    invalid,
    describedBy,
    onPaste,
}: {
    id: string;
    value: string;
    onChange: (value: string) => void;
    scopes: Scope[];
    placeholder?: string;
    invalid?: boolean;
    describedBy?: string;
    onPaste?: React.ClipboardEventHandler<HTMLTextAreaElement>;
}) {
    const ref = useRef<HTMLTextAreaElement>(null);
    const [caret, setCaret] = useState<number | null>(null);
    const [active, setActive] = useState(0);
    const [dismissed, setDismissed] = useState(false);

    const token = useMemo(() => {
        if (caret === null) {
            return null;
        }

        const match = OPEN.exec(value.slice(0, caret));

        return match ? match[1] : null;
    }, [caret, value]);

    const suggestions = useMemo(
        () => (token === null ? [] : suggestionsFor(token, scopes)),
        [token, scopes],
    );
    const open = !dismissed && token !== null && suggestions.length > 0;
    const listId = `${id}-references`;

    const accept = (suggestion: Suggestion) => {
        if (caret === null || token === null) {
            return;
        }

        const start = caret - token.length;
        const after = value.slice(caret);
        const closing =
            suggestion.partial || after.trimStart().startsWith('}}')
                ? ''
                : '}}';
        const next =
            value.slice(0, start) + suggestion.insert + closing + after;
        const position = start + suggestion.insert.length + closing.length;
        onChange(next);
        setActive(0);
        requestAnimationFrame(() => {
            ref.current?.setSelectionRange(position, position);
            setCaret(position);
        });
    };

    return (
        <div className="relative">
            <textarea
                ref={ref}
                id={id}
                value={value}
                rows={Math.min(8, Math.max(2, value.split('\n').length))}
                placeholder={placeholder}
                spellCheck={false}
                autoComplete="off"
                aria-invalid={invalid || undefined}
                aria-describedby={describedBy}
                role="combobox"
                aria-expanded={open}
                aria-controls={listId}
                aria-autocomplete="list"
                onPaste={onPaste}
                onChange={(event) => {
                    onChange(event.target.value);
                    setCaret(event.target.selectionStart);
                    setDismissed(false);
                    setActive(0);
                }}
                onSelect={(event) =>
                    setCaret(event.currentTarget.selectionStart)
                }
                onBlur={() => window.setTimeout(() => setCaret(null), 120)}
                onKeyDown={(event) => {
                    if (!open) {
                        return;
                    }

                    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                        event.preventDefault();
                        const delta = event.key === 'ArrowDown' ? 1 : -1;
                        setActive(
                            (current) =>
                                (current + delta + suggestions.length) %
                                suggestions.length,
                        );
                    } else if (event.key === 'Enter' || event.key === 'Tab') {
                        event.preventDefault();
                        accept(suggestions[active] ?? suggestions[0]);
                    } else if (event.key === 'Escape') {
                        event.preventDefault();
                        event.stopPropagation();
                        setDismissed(true);
                    }
                }}
                className={cn(
                    'flex min-h-9 w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 type-mono shadow-xs outline-none placeholder:text-muted-foreground',
                    'focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
                    'aria-invalid:border-destructive',
                )}
            />
            {open && (
                <ul
                    id={listId}
                    role="listbox"
                    aria-label="References"
                    className="absolute top-full left-0 z-50 mt-1 max-h-60 w-full overflow-y-auto rounded-lg border bg-popover p-1 shadow-md"
                >
                    {suggestions.map((suggestion, index) => (
                        <li
                            key={suggestion.insert}
                            role="option"
                            aria-selected={index === active}
                            onMouseDown={(event) => {
                                event.preventDefault();
                                accept(suggestion);
                            }}
                            className={cn(
                                'flex cursor-default items-center justify-between gap-3 rounded-md px-2 py-1.5',
                                index === active && 'bg-accent',
                            )}
                        >
                            <span className="truncate type-mono-small">
                                {suggestion.label}
                            </span>
                            <span className="shrink-0 type-small text-muted-foreground">
                                {suggestion.detail}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
