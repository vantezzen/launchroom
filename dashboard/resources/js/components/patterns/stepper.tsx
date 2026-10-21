import { Minus, Plus } from 'lucide-react';
import { useId } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type StepperProps = {
    label: string;
    value: number;
    onChange: (value: number) => void;
    min?: number;
    max?: number;
    step?: number;
    /** Unit after the value ("replicas", "GB"). */
    unit?: string;
    /** One helper line. */
    helper?: ReactNode;
    /** The live consequence ("fits on 2 servers"). */
    consequence?: ReactNode;
    disabled?: boolean;
    className?: string;
};

/**
 * Numeric quantity with a small range as − value + (design-system §6).
 * Arrow keys on the value work too.
 */
export function Stepper({
    label,
    value,
    onChange,
    min = 0,
    max = Number.POSITIVE_INFINITY,
    step = 1,
    unit,
    helper,
    consequence,
    disabled = false,
    className,
}: StepperProps) {
    const id = useId();
    const clamp = (next: number) => Math.min(max, Math.max(min, next));

    return (
        <div className={cn('flex flex-col gap-1.5', className)}>
            <label htmlFor={id} className="type-label">
                {label}
            </label>
            <div className="flex flex-wrap items-center gap-3">
                <div className="inline-flex h-9 items-center rounded-md border border-border-strong bg-background">
                    <button
                        type="button"
                        aria-label={`Decrease ${label.toLowerCase()}`}
                        disabled={disabled || value <= min}
                        onClick={() => onChange(clamp(value - step))}
                        className="flex h-full w-9 items-center justify-center rounded-l-md text-muted-foreground transition-colors duration-fast hover:bg-hover hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-40"
                    >
                        <Minus aria-hidden="true" className="size-4" />
                    </button>
                    <input
                        id={id}
                        type="number"
                        inputMode="numeric"
                        value={value}
                        min={min}
                        max={Number.isFinite(max) ? max : undefined}
                        step={step}
                        disabled={disabled}
                        onChange={(event) => {
                            const next = Number(event.target.value);

                            if (!Number.isNaN(next)) {
                                onChange(clamp(next));
                            }
                        }}
                        className="h-full w-14 [appearance:textfield] border-x border-border-strong bg-transparent text-center type-body tabular-nums outline-none focus-visible:bg-hover [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                    />
                    <button
                        type="button"
                        aria-label={`Increase ${label.toLowerCase()}`}
                        disabled={disabled || value >= max}
                        onClick={() => onChange(clamp(value + step))}
                        className="flex h-full w-9 items-center justify-center rounded-r-md text-muted-foreground transition-colors duration-fast hover:bg-hover hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-40"
                    >
                        <Plus aria-hidden="true" className="size-4" />
                    </button>
                </div>
                {unit && (
                    <span className="type-body text-muted-foreground">
                        {unit}
                    </span>
                )}
                {consequence && (
                    <span
                        aria-live="polite"
                        className="type-body text-muted-foreground"
                    >
                        {consequence}
                    </span>
                )}
            </div>
            {helper && (
                <p className="type-small text-muted-foreground">{helper}</p>
            )}
        </div>
    );
}
