import { ChevronRight } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { StepIcon, STEP_STATUS_LABEL } from './step-icon';
import type { StepStatus } from './step-icon';

export type Step = {
    id: string;
    name: string;
    status: StepStatus;
    /** Quiet text right of the name ("Next.js 16.1 · pnpm 9.12"). */
    summary?: ReactNode;
    /** Compact duration ("41s"); right-aligned, tabular. */
    duration?: string | null;
    /** Small chips before the duration ("1 error"). */
    chips?: ReactNode;
    /** Expanded body (an ErrorExcerpt, log lines, facts). No body → not expandable. */
    content?: ReactNode;
};

/**
 * Deployment steps (design-system §5.4): one row
 * per step with a chevron, status mark, name, summary, chips and the
 * duration right-aligned. The failing step opens by default; everything
 * else starts collapsed so a successful deploy reads as a list of times.
 * Rows are buttons (Enter/Space); the panel is labelled by its row.
 */
export function StepList({
    steps,
    defaultOpen,
    className,
}: {
    steps: Step[];
    /** Step ids open at first render; default: the failed step(s). */
    defaultOpen?: string[];
    className?: string;
}) {
    const [open, setOpen] = useState<Set<string>>(
        () =>
            new Set(
                defaultOpen ??
                    steps
                        .filter(
                            (step) => step.status === 'failed' && step.content,
                        )
                        .map((step) => step.id),
            ),
    );

    const toggle = (id: string) =>
        setOpen((current) => {
            const next = new Set(current);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });

    return (
        <ol
            className={cn(
                'overflow-hidden rounded-[14px] border bg-card shadow-[0_1px_2px_rgb(0_0_0/0.04)] dark:shadow-none',
                className,
            )}
        >
            {steps.map((step) => {
                const expandable = Boolean(step.content);
                const expanded = expandable && open.has(step.id);
                const quiet =
                    step.status === 'skipped' ||
                    step.status === 'pending' ||
                    step.status === 'canceled';
                const panelId = `step-${step.id}-panel`;

                const row = (
                    <>
                        <ChevronRight
                            aria-hidden="true"
                            className={cn(
                                'size-4 shrink-0 text-(--faint) motion-safe:transition-transform motion-safe:duration-[120ms]',
                                expanded && 'rotate-90',
                                !expandable && 'invisible',
                            )}
                        />
                        <StepIcon status={step.status} />
                        <span
                            className={cn(
                                'type-body font-medium',
                                quiet && 'text-muted-foreground',
                            )}
                        >
                            {step.name}
                            <span className="sr-only">
                                : {STEP_STATUS_LABEL[step.status]}
                            </span>
                        </span>
                        {step.summary && (
                            <span className="hidden min-w-0 truncate type-small text-muted-foreground sm:inline">
                                {step.summary}
                            </span>
                        )}
                        <span className="ml-auto flex shrink-0 items-center gap-3">
                            {step.chips}
                            <span className="min-w-12 text-right type-small text-muted-foreground tabular-nums">
                                {step.duration ??
                                    (quiet
                                        ? STEP_STATUS_LABEL[step.status]
                                        : '')}
                            </span>
                        </span>
                    </>
                );

                return (
                    <li key={step.id} className="border-b last:border-b-0">
                        {expandable ? (
                            <button
                                type="button"
                                aria-expanded={expanded}
                                aria-controls={panelId}
                                onClick={() => toggle(step.id)}
                                className="flex min-h-12 w-full items-center gap-3 px-4 text-left hover:bg-(--hover)/60 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--signal)"
                            >
                                {row}
                            </button>
                        ) : (
                            <div className="flex min-h-12 items-center gap-3 px-4">
                                {row}
                            </div>
                        )}
                        {expanded && (
                            <div id={panelId} className="border-t">
                                {step.content}
                            </div>
                        )}
                    </li>
                );
            })}
        </ol>
    );
}

/** Small count chip for a step row ("1 error", "2 warnings"). */
export function StepChip({
    children,
    tone = 'neutral',
}: {
    children: ReactNode;
    tone?: 'neutral' | 'error' | 'warning';
}) {
    return (
        <span
            className={cn(
                'rounded-full px-2 py-px type-small tabular-nums',
                tone === 'error' &&
                    'bg-(--status-error-soft) text-status-error',
                tone === 'warning' &&
                    'bg-(--status-progress-soft) text-status-progress',
                tone === 'neutral' && 'bg-(--hover) text-muted-foreground',
            )}
        >
            {children}
        </span>
    );
}
