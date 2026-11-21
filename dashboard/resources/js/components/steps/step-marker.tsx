import { Check, Minus, X } from 'lucide-react';
import { Spinner } from '@/components/ui/spinner';

export type StepMarkerStatus =
    | 'done'
    | 'failed'
    | 'waiting'
    | 'skipped'
    | 'todo';

const circle = 'flex size-8 shrink-0 items-center justify-center rounded-full';

/** The numbered 32 px marker of a guided checklist step (direction §9.12). */
export function StepMarker({
    status,
    number,
}: {
    status: StepMarkerStatus;
    number: number;
}) {
    switch (status) {
        case 'done':
            return (
                <span className={`${circle} bg-foreground text-background`}>
                    <Check aria-hidden="true" className="size-4" />
                    <span className="sr-only">Done</span>
                </span>
            );
        case 'failed':
            return (
                <span
                    className={`${circle} bg-status-error-soft text-status-error`}
                >
                    <X aria-hidden="true" className="size-4" />
                    <span className="sr-only">Needs a fix</span>
                </span>
            );
        case 'waiting':
            return (
                <span className={`${circle} border`}>
                    <Spinner className="size-4 text-status-progress" />
                    <span className="sr-only">In progress</span>
                </span>
            );
        case 'skipped':
            return (
                <span
                    className={`${circle} border border-dashed border-border-strong text-muted-foreground`}
                >
                    <Minus aria-hidden="true" className="size-4" />
                    <span className="sr-only">Skipped</span>
                </span>
            );
        case 'todo':
            return (
                <span
                    className={`${circle} border type-label text-muted-foreground tabular-nums`}
                >
                    {number}
                    <span className="sr-only">To do</span>
                </span>
            );
    }
}
