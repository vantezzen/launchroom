import { Check } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type WizardStep = {
    id: string;
    /** Short step name for the header ("What is it"). */
    title: string;
    /** One-line result once the step is done ("Mac mini · Runtime"). */
    summary?: ReactNode;
};

type WizardProps = {
    steps: WizardStep[];
    /** Index of the open step. The URL carries it (the page's job). */
    current: number;
    /** Go back to an earlier step (its "Edit" link). */
    onStepSelect?: (index: number) => void;
    /** Content of the open step. */
    children: ReactNode;
    /** Primary/secondary actions under the open step. */
    footer?: ReactNode;
    className?: string;
};

/**
 * Guided setup (direction §5.6): numbered step header, one card for the
 * open step, earlier steps collapsed to their summary line. Used by add
 * server, import repository and get started.
 */
export function Wizard({
    steps,
    current,
    onStepSelect,
    children,
    footer,
    className,
}: WizardProps) {
    return (
        <div className={cn('flex flex-col gap-6', className)}>
            <ol
                aria-label="Steps"
                className="flex flex-wrap items-center gap-x-3 gap-y-2"
            >
                {steps.map((step, index) => {
                    const done = index < current;
                    const open = index === current;

                    return (
                        <li
                            key={step.id}
                            aria-current={open ? 'step' : undefined}
                            className="flex items-center gap-3"
                        >
                            {index > 0 && (
                                <span
                                    aria-hidden="true"
                                    className="h-px w-6 bg-border-strong"
                                />
                            )}
                            <span
                                className={cn(
                                    'flex items-center gap-2 type-label',
                                    open
                                        ? 'text-foreground'
                                        : 'text-muted-foreground',
                                )}
                            >
                                <span
                                    aria-hidden="true"
                                    className={cn(
                                        'flex size-5 items-center justify-center rounded-full border text-[11px] tabular-nums transition-colors duration-base',
                                        done &&
                                            'border-foreground bg-foreground text-background',
                                        open && 'border-foreground',
                                    )}
                                >
                                    {done ? (
                                        <Check
                                            className="size-3"
                                            strokeWidth={3}
                                        />
                                    ) : (
                                        index + 1
                                    )}
                                </span>
                                {step.title}
                                {done && (
                                    <span className="sr-only">(done)</span>
                                )}
                            </span>
                        </li>
                    );
                })}
            </ol>

            <div className="flex flex-col gap-3">
                {steps.slice(0, current).map((step, index) => (
                    <div
                        key={step.id}
                        className="flex items-center gap-3 rounded-xl border bg-card px-4 py-3"
                    >
                        <Check
                            aria-hidden="true"
                            className="size-4 shrink-0 text-status-ok"
                        />
                        <span className="type-label">{step.title}</span>
                        {step.summary && (
                            <span className="min-w-0 flex-1 truncate type-body text-muted-foreground">
                                {step.summary}
                            </span>
                        )}
                        {onStepSelect && (
                            <button
                                type="button"
                                onClick={() => onStepSelect(index)}
                                className="ml-auto rounded-sm type-label text-muted-foreground transition-colors duration-fast hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                Edit
                                <span className="sr-only"> {step.title}</span>
                            </button>
                        )}
                    </div>
                ))}

                <section
                    aria-label={steps[current]?.title}
                    className="flex flex-col gap-6 rounded-xl border bg-card p-5 shadow-card sm:p-6"
                >
                    {children}
                    {footer && (
                        <div className="flex flex-wrap items-center justify-end gap-2 border-t pt-4">
                            {footer}
                        </div>
                    )}
                </section>
            </div>
        </div>
    );
}
