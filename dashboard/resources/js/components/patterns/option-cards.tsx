import { Check } from 'lucide-react';
import { useId } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type OptionCard<T extends string> = {
    value: T;
    title: string;
    /** One sentence: what this mode means, including its consequence. */
    description: string;
    media?: ReactNode;
    disabled?: boolean;
};

/**
 * Mode choice as two or three large cards with a check in the corner
 * (direction §5.6): database strategy for
 * previews, built-in vs your own registry, restore mode.
 */
export function OptionCards<T extends string>({
    label,
    options,
    value,
    onChange,
    className,
}: {
    label: string;
    options: OptionCard<T>[];
    value: T | null;
    onChange: (value: T) => void;
    className?: string;
}) {
    const name = useId();

    return (
        <div
            role="radiogroup"
            aria-label={label}
            className={cn(
                'grid gap-3',
                options.length >= 3 ? 'sm:grid-cols-3' : 'sm:grid-cols-2',
                className,
            )}
        >
            {options.map((option) => {
                const selected = option.value === value;

                return (
                    <label
                        key={option.value}
                        className={cn(
                            'relative flex cursor-pointer flex-col gap-3 rounded-xl border bg-card p-5 transition-[border-color,box-shadow] duration-fast',
                            'has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring has-[:focus-visible]:ring-offset-2 has-[:focus-visible]:ring-offset-background',
                            selected
                                ? 'border-foreground shadow-[0_0_0_0.5px_var(--foreground)]'
                                : 'hover:border-border-strong',
                            option.disabled && 'cursor-not-allowed opacity-60',
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
                        <span
                            aria-hidden="true"
                            className={cn(
                                'absolute top-4 right-4 flex size-5 items-center justify-center rounded-full border transition-colors duration-fast',
                                selected
                                    ? 'border-foreground bg-foreground text-background'
                                    : 'border-border-strong',
                            )}
                        >
                            {selected && (
                                <Check className="size-3" strokeWidth={3} />
                            )}
                        </span>
                        {option.media}
                        <span className="flex flex-col gap-1 pr-6">
                            <span className="type-heading">{option.title}</span>
                            <span className="type-body text-muted-foreground">
                                {option.description}
                            </span>
                        </span>
                    </label>
                );
            })}
        </div>
    );
}
