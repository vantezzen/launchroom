import { useRef } from 'react';
import type { KeyboardEvent } from 'react';
import type { TimeRangeOption } from '@/lib/observe-scope';
import { cn } from '@/lib/utils';

/**
 * Segmented range or filter control (design-system §5.3). A radio group:
 * arrow keys move the selection, one tab stop.
 */
export function TimeRangeSelect<T extends string>({
    value,
    onChange,
    options,
    label = 'Time range',
    className,
}: {
    value: T;
    onChange: (value: T) => void;
    options: TimeRangeOption<T>[];
    label?: string;
    className?: string;
}) {
    const refs = useRef<(HTMLButtonElement | null)[]>([]);

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        const index = options.findIndex((option) => option.value === value);
        const delta =
            event.key === 'ArrowRight' || event.key === 'ArrowDown'
                ? 1
                : event.key === 'ArrowLeft' || event.key === 'ArrowUp'
                  ? -1
                  : 0;

        if (delta === 0) {
            return;
        }

        event.preventDefault();
        const next = (index + delta + options.length) % options.length;
        onChange(options[next].value);
        refs.current[next]?.focus();
    };

    return (
        <div
            role="radiogroup"
            aria-label={label}
            onKeyDown={onKeyDown}
            className={cn(
                'inline-flex h-8 items-center gap-0.5 rounded-lg bg-(--hover) p-0.5',
                className,
            )}
        >
            {options.map((option, index) => {
                const selected = option.value === value;

                return (
                    <button
                        key={option.value}
                        ref={(node) => {
                            refs.current[index] = node;
                        }}
                        type="button"
                        role="radio"
                        aria-checked={selected}
                        tabIndex={selected ? 0 : -1}
                        onClick={() => onChange(option.value)}
                        className={cn(
                            'h-7 rounded-md px-3 type-label text-muted-foreground motion-safe:transition-colors motion-safe:duration-[120ms]',
                            'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--signal)',
                            selected
                                ? 'bg-card text-foreground shadow-[0_1px_2px_rgb(0_0_0/0.08)] dark:bg-(--border-strong)'
                                : 'hover:text-foreground',
                        )}
                    >
                        {option.label}
                    </button>
                );
            })}
        </div>
    );
}
