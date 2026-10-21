import { Check, ChevronRight } from 'lucide-react';
import { useId } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type ChoiceTile<T extends string> = {
    value: T;
    label: string;
    /** One short line under the label. */
    description?: string;
    /** Logo tile, device art or icon. */
    media?: ReactNode;
    disabled?: boolean;
    /** Chip on disabled tiles ("Coming soon"). */
    badge?: string;
};

type ChoiceTilesProps<T extends string> = {
    /** Accessible group name ("Database"). */
    label: string;
    options: ChoiceTile<T>[];
    value: T | null;
    onChange: (value: T) => void;
    columns?: 2 | 3 | 4;
    /** The odd-one-out option as a full-width row ("Connect any S3-compatible storage…"). */
    other?: {
        label: string;
        description?: string;
        media?: ReactNode;
        onSelect: () => void;
    };
    className?: string;
};

const grid = {
    2: 'grid-cols-2',
    3: 'grid-cols-2 sm:grid-cols-3',
    4: 'grid-cols-2 sm:grid-cols-4',
} as const;

/**
 * "Choose your X" (direction §5.6): large tiles
 * with a logo or device, one line each. Selected = 1.5 px foreground ring.
 * A radio group: arrow keys move, Space selects.
 */
export function ChoiceTiles<T extends string>({
    label,
    options,
    value,
    onChange,
    columns = 3,
    other,
    className,
}: ChoiceTilesProps<T>) {
    const name = useId();

    return (
        <div className={cn('flex flex-col gap-3', className)}>
            <div
                role="radiogroup"
                aria-label={label}
                className={cn('grid gap-3', grid[columns])}
            >
                {options.map((option) => {
                    const selected = option.value === value;

                    return (
                        <label
                            key={option.value}
                            className={cn(
                                'group relative flex min-h-28 cursor-pointer flex-col items-center justify-center gap-2.5 rounded-xl border bg-card px-3 py-4 text-center transition-[border-color,box-shadow] duration-fast',
                                'has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring has-[:focus-visible]:ring-offset-2 has-[:focus-visible]:ring-offset-background',
                                selected
                                    ? 'border-foreground shadow-[0_0_0_0.5px_var(--foreground)]'
                                    : 'hover:border-border-strong',
                                option.disabled &&
                                    'cursor-not-allowed opacity-60 hover:border-border',
                            )}
                        >
                            <input
                                type="radio"
                                name={name}
                                value={option.value}
                                checked={selected}
                                disabled={option.disabled}
                                onChange={() => onChange(option.value)}
                                className="sr-only"
                            />
                            {option.media}
                            <span className="flex flex-col gap-0.5">
                                <span className="type-label">
                                    {option.label}
                                </span>
                                {option.description && (
                                    <span className="type-small text-muted-foreground">
                                        {option.description}
                                    </span>
                                )}
                            </span>
                            {option.badge && (
                                <span className="rounded-full bg-hover px-2 py-0.5 type-small text-muted-foreground">
                                    {option.badge}
                                </span>
                            )}
                            {selected && (
                                <Check
                                    aria-hidden="true"
                                    className="absolute top-2.5 right-2.5 size-4"
                                />
                            )}
                        </label>
                    );
                })}
            </div>
            {other && (
                <button
                    type="button"
                    onClick={other.onSelect}
                    className="flex items-center gap-3 rounded-xl border bg-card px-4 py-3 text-left transition-colors duration-fast hover:border-border-strong focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                >
                    {other.media}
                    <span className="flex min-w-0 flex-1 flex-col">
                        <span className="type-label">{other.label}</span>
                        {other.description && (
                            <span className="type-small text-muted-foreground">
                                {other.description}
                            </span>
                        )}
                    </span>
                    <ChevronRight
                        aria-hidden="true"
                        className="size-4 text-muted-foreground"
                    />
                </button>
            )}
        </div>
    );
}
