import { Check, ChevronRight, Minus } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type GuidedStepStatus = 'done' | 'pending' | 'skipped';

export function StepMarker({
    status,
    number,
}: {
    status: GuidedStepStatus;
    number: number;
}) {
    if (status === 'done') {
        return (
            <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-foreground text-background">
                <Check aria-hidden="true" className="size-4" />
                <span className="sr-only">Done</span>
            </span>
        );
    }

    if (status === 'skipped') {
        return (
            <span className="flex size-8 shrink-0 items-center justify-center rounded-full border text-muted-foreground">
                <Minus aria-hidden="true" className="size-4" />
                <span className="sr-only">Skipped</span>
            </span>
        );
    }

    return (
        <span className="flex size-8 shrink-0 items-center justify-center rounded-full border type-label text-muted-foreground tabular-nums">
            {number}
            <span className="sr-only">To do</span>
        </span>
    );
}

/**
 * A step of a guided checklist (get started, recovery; direction §9.12):
 * collapsed it's one row (marker, title, result) that opens on click; the
 * open step is the one card with a ring and its body.
 */
export function StepCard({
    id,
    status,
    number,
    title,
    line,
    aside,
    open,
    onOpen,
    children,
}: {
    id: string;
    status: GuidedStepStatus;
    number: number;
    title: string;
    /** One line under the title: the result when done, the ask when open. */
    line?: ReactNode;
    /** Right side of the collapsed row (a device, logos, a chip). */
    aside?: ReactNode;
    open: boolean;
    onOpen: () => void;
    children: ReactNode;
}) {
    const headingId = `step-${id}`;

    if (!open) {
        return (
            <li>
                <button
                    type="button"
                    onClick={onOpen}
                    aria-labelledby={headingId}
                    aria-expanded={false}
                    className="group flex w-full items-center gap-4 rounded-xl border bg-card px-5 py-4 text-left shadow-card transition-colors duration-fast hover:border-border-strong focus-visible:ring-2 focus-visible:ring-signal focus-visible:ring-offset-2 focus-visible:outline-none"
                >
                    <StepMarker status={status} number={number} />
                    <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span
                            id={headingId}
                            className={cn(
                                'type-body font-medium',
                                status === 'pending' && 'text-muted-foreground',
                            )}
                        >
                            {title}
                        </span>
                        {line && (
                            <span className="truncate type-small text-muted-foreground">
                                {line}
                            </span>
                        )}
                    </span>
                    {aside && (
                        <span className="hidden shrink-0 sm:block">
                            {aside}
                        </span>
                    )}
                    <ChevronRight
                        aria-hidden="true"
                        className="size-4 shrink-0 text-faint transition-transform duration-fast group-hover:translate-x-0.5"
                    />
                </button>
            </li>
        );
    }

    return (
        <li
            aria-labelledby={headingId}
            className="flex flex-col gap-6 rounded-xl border border-foreground bg-card px-5 py-5 shadow-[0_0_0_0.5px_var(--foreground)] motion-safe:animate-enter sm:px-6 sm:py-6"
        >
            <div className="flex items-start gap-4">
                <StepMarker status={status} number={number} />
                <div className="flex min-w-0 flex-col gap-1 pt-1">
                    <h2 id={headingId} className="type-heading">
                        {title}
                    </h2>
                    {line && (
                        <p className="type-body text-muted-foreground">
                            {line}
                        </p>
                    )}
                </div>
            </div>
            <div className="flex min-w-0 flex-col gap-5 sm:pl-12">
                {children}
            </div>
        </li>
    );
}

/** Done/total as a ring, monochrome (get started hero). */
export function ProgressRing({
    done,
    total,
    size = 76,
}: {
    done: number;
    total: number;
    size?: number;
}) {
    const stroke = 6;
    const radius = (size - stroke) / 2;
    const circumference = 2 * Math.PI * radius;
    const share = total > 0 ? done / total : 0;

    return (
        <div
            role="img"
            aria-label={`${done} of ${total} steps done`}
            className="relative shrink-0"
            style={{ width: size, height: size }}
        >
            <svg
                viewBox={`0 0 ${size} ${size}`}
                className="size-full -rotate-90"
                aria-hidden="true"
            >
                <circle
                    cx={size / 2}
                    cy={size / 2}
                    r={radius}
                    fill="none"
                    stroke="var(--border)"
                    strokeWidth={stroke}
                />
                {share > 0 && (
                    <circle
                        cx={size / 2}
                        cy={size / 2}
                        r={radius}
                        fill="none"
                        stroke="var(--foreground)"
                        strokeWidth={stroke}
                        strokeLinecap="round"
                        strokeDasharray={`${share * circumference} ${circumference}`}
                        className="motion-safe:transition-[stroke-dasharray] motion-safe:duration-slow"
                    />
                )}
            </svg>
            <span className="absolute inset-0 flex items-center justify-center type-heading tabular-nums">
                {done}/{total}
            </span>
        </div>
    );
}
