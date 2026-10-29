import { cn } from '@/lib/utils';

/**
 * The one progress spinner (design-system §3.3, §5.4): an amber arc on a
 * faint amber ring that stays visible on a soft amber pill; static under
 * reduced motion. `StatusIcon` (progress) and `StepIcon` (running) both
 * draw it, so a deployment's status and its running step spin alike, and
 * busy buttons (`Spinner`) use its shape in their text colour.
 * Decorative: pair it with a word.
 */
export function ProgressRing({
    size,
    tone = 'progress',
    className,
}: {
    /** Pixels; without it the size comes from `className` (`size-3.5`). */
    size?: number;
    /** `current`: the text colour (busy buttons, hero discs). */
    tone?: 'progress' | 'current';
    className?: string;
}) {
    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 20 20"
            aria-hidden="true"
            className={cn(
                'shrink-0 motion-safe:animate-spin',
                size === undefined && 'size-3.5',
                className,
            )}
        >
            <circle
                cx="10"
                cy="10"
                r="8"
                fill="none"
                strokeWidth="2"
                stroke="currentColor"
                strokeOpacity={0.25}
                style={
                    tone === 'progress'
                        ? { stroke: 'var(--status-progress)' }
                        : undefined
                }
            />
            <path
                d="M10 2a8 8 0 0 1 8 8"
                fill="none"
                strokeWidth="2"
                strokeLinecap="round"
                stroke="currentColor"
                style={
                    tone === 'progress'
                        ? { stroke: 'var(--status-progress)' }
                        : undefined
                }
            />
        </svg>
    );
}
