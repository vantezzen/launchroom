import { ArrowRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { DeviceImage } from '@/components/devices/device-image';
import type {
    DeviceKind,
    DeviceStatus,
} from '@/components/devices/device-image';
import { cn } from '@/lib/utils';

export type TransferSide = {
    name: string;
    device: DeviceKind;
    status: DeviceStatus;
    /** "3 of 5 services here". */
    detail?: string | null;
    /** Takes public traffic right now: the tag people look for first. */
    serving?: boolean;
    /** A dead server (Replace): drawn greyed. */
    gone?: boolean;
    /** Under the card (Retire {source} once everything moved). */
    action?: ReactNode;
};

function Side({ side, label }: { side: TransferSide; label: string }) {
    return (
        <div className="flex min-w-0 flex-col items-center gap-2 text-center">
            <div
                className={cn(
                    'flex w-full flex-col items-center gap-2 rounded-xl border bg-card px-3 pt-2 pb-3',
                    side.gone && 'opacity-60',
                )}
            >
                <span className="sr-only">{label}: </span>
                <DeviceImage
                    device={side.device}
                    status={side.status}
                    size={96}
                    className={cn(side.gone && 'grayscale')}
                />
                <span className="max-w-full truncate type-label">
                    {side.name}
                </span>
                {side.detail && (
                    <span className="type-small text-muted-foreground tabular-nums">
                        {side.detail}
                    </span>
                )}
                {side.serving && (
                    <span className="inline-flex h-5 items-center rounded-full bg-hover px-2 type-small">
                        Serving traffic
                    </span>
                )}
            </div>
            {side.action}
        </div>
    );
}

/**
 * A move or replacement drawn as source → target (docs/08 §20): both
 * servers, which one serves traffic now, and the current step on the
 * arrow ("Copying postgres, 1.2 of 3.4 GB"). Stacks on a phone.
 */
export function TransferStrip({
    source,
    target,
    step,
}: {
    source: TransferSide;
    target: TransferSide;
    step: string | null;
}) {
    return (
        <div
            role="group"
            aria-label={`${source.name} to ${target.name}${step ? `: ${step}` : ''}`}
            className="grid items-center gap-3 rounded-xl bg-recessed p-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)_minmax(0,1fr)]"
        >
            <Side side={source} label="From" />
            <div className="flex flex-col items-center gap-1.5 px-2 text-center">
                <span className="flex w-full items-center text-muted-foreground">
                    <span
                        aria-hidden="true"
                        className="h-0 flex-1 border-t-[1.5px] border-dashed border-(--signal)"
                    />
                    <ArrowRight
                        aria-hidden="true"
                        className="size-4 shrink-0 text-(--signal) max-sm:rotate-90"
                    />
                </span>
                {step && (
                    <span className="type-small text-foreground">{step}</span>
                )}
            </div>
            <Side side={target} label="To" />
        </div>
    );
}
