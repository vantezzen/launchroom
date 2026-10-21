import { cn } from '@/lib/utils';

export type StepStatus =
    | 'succeeded'
    | 'failed'
    | 'running'
    | 'pending'
    | 'skipped'
    | 'canceled';

export const STEP_STATUS_LABEL: Record<StepStatus, string> = {
    succeeded: 'Done',
    failed: 'Failed',
    running: 'Running',
    pending: 'Waiting',
    skipped: 'Skipped',
    canceled: 'Canceled',
};

/**
 * Step status mark (design-system §5.4): filled green check, filled red ✕,
 * amber spinner (static under reduced motion), dashed hollow circle for
 * waiting/skipped, grey slash for canceled. Always paired with a text
 * label (visible or `sr-only`).
 */
export function StepIcon({
    status,
    size = 18,
    className,
}: {
    status: StepStatus;
    size?: number;
    className?: string;
}) {
    const common = {
        width: size,
        height: size,
        viewBox: '0 0 20 20',
        'aria-hidden': true as const,
        className: cn('shrink-0', className),
    };

    switch (status) {
        case 'succeeded':
            return (
                <svg {...common}>
                    <circle
                        cx="10"
                        cy="10"
                        r="9"
                        style={{ fill: 'var(--status-ok)' }}
                    />
                    <path
                        d="M6 10.4l2.6 2.5L14 7.6"
                        fill="none"
                        strokeWidth="1.9"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        style={{ stroke: 'var(--card)' }}
                    />
                </svg>
            );
        case 'failed':
            return (
                <svg {...common}>
                    <circle
                        cx="10"
                        cy="10"
                        r="9"
                        style={{ fill: 'var(--status-error)' }}
                    />
                    <path
                        d="M7.2 7.2l5.6 5.6M12.8 7.2l-5.6 5.6"
                        fill="none"
                        strokeWidth="1.9"
                        strokeLinecap="round"
                        style={{ stroke: 'var(--card)' }}
                    />
                </svg>
            );
        case 'running':
            return (
                <svg
                    {...common}
                    className={cn(common.className, 'motion-safe:animate-spin')}
                >
                    <circle
                        cx="10"
                        cy="10"
                        r="8"
                        fill="none"
                        strokeWidth="2"
                        style={{ stroke: 'var(--status-progress-soft)' }}
                    />
                    <path
                        d="M10 2a8 8 0 0 1 8 8"
                        fill="none"
                        strokeWidth="2"
                        strokeLinecap="round"
                        style={{ stroke: 'var(--status-progress)' }}
                    />
                </svg>
            );
        case 'canceled':
            return (
                <svg {...common}>
                    <circle
                        cx="10"
                        cy="10"
                        r="8.25"
                        fill="none"
                        strokeWidth="1.5"
                        style={{ stroke: 'var(--status-idle)' }}
                    />
                    <path
                        d="M6.5 13.5l7-7"
                        strokeWidth="1.5"
                        strokeLinecap="round"
                        style={{ stroke: 'var(--status-idle)' }}
                    />
                </svg>
            );
        default:
            return (
                <svg {...common}>
                    <circle
                        cx="10"
                        cy="10"
                        r="8.25"
                        fill="none"
                        strokeWidth="1.5"
                        strokeDasharray="2.6 2.6"
                        style={{ stroke: 'var(--faint)' }}
                    />
                </svg>
            );
    }
}
