import { Info } from 'lucide-react';
import type { CSSProperties } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { formatMicros, ttfb } from './trace-model';
import type { RequestTrace } from './trace-model';

const COLOR = {
    edge: 'var(--chart-other)',
    app: 'var(--signal)',
} as const;

/** Each box as wide as its share, never narrower than its words. */
function share(us: number, total: number): CSSProperties {
    return {
        flexGrow: Math.max(us, total / 1000),
        flexBasis: 0,
        minWidth: 'max-content',
    };
}

/**
 * Time to first byte (docs/23 §7): one bar split into the edge's share and
 * the app's, each segment named with its duration above it and the
 * moments between them as ticks below.
 */
export function TtfbBar({ trace }: { trace: RequestTrace }) {
    const data = ttfb(trace);

    if (data === null) {
        return null;
    }

    const { totalUs, segments, milestones } = data;
    const total = Math.max(totalUs, 1);
    const middle = milestones.slice(1, -1);
    const last = milestones[milestones.length - 1];

    return (
        <div className="flex flex-col gap-3 px-5 pt-4 pb-5">
            <div className="flex items-baseline justify-between gap-4">
                <span className="inline-flex items-center gap-1.5 type-label">
                    TTFB
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <button
                                type="button"
                                aria-label="About TTFB"
                                className="inline-flex size-4 items-center justify-center rounded-sm text-faint outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-signal"
                            >
                                <Info aria-hidden="true" className="size-3.5" />
                            </button>
                        </TooltipTrigger>
                        <TooltipContent className="max-w-64">
                            Time to first byte: from the edge receiving the
                            request until it sent the response headers.
                        </TooltipContent>
                    </Tooltip>
                </span>
                <span className="type-stat tabular-nums">
                    {formatMicros(totalUs)}
                </span>
            </div>

            <div className="flex flex-col gap-1.5">
                <div className="flex gap-3 type-small">
                    {segments.map((segment) => (
                        <span
                            key={segment.key}
                            className="flex items-baseline gap-1.5"
                            style={share(segment.us, total)}
                        >
                            <span
                                aria-hidden="true"
                                className="size-2 shrink-0 self-center rounded-[2px]"
                                style={{ backgroundColor: COLOR[segment.key] }}
                            />
                            <span className="text-muted-foreground">
                                {segment.label}
                            </span>
                            <span className="type-mono-small text-foreground tabular-nums">
                                {formatMicros(segment.us)}
                            </span>
                            {segment.note && (
                                <span className="text-muted-foreground">
                                    {segment.note}
                                </span>
                            )}
                        </span>
                    ))}
                </div>

                <div className="relative">
                    <div
                        role="img"
                        aria-label={segments
                            .map(
                                (segment) =>
                                    `${segment.label} ${formatMicros(segment.us)}`,
                            )
                            .join(', ')}
                        className="flex h-2 w-full gap-0.5"
                    >
                        {segments.map((segment) => (
                            <span
                                key={segment.key}
                                className="h-full rounded-full"
                                style={
                                    {
                                        flexGrow: Math.max(segment.us, 1),
                                        flexBasis: 0,
                                        minWidth: 3,
                                        backgroundColor: COLOR[segment.key],
                                    } as CSSProperties
                                }
                            />
                        ))}
                    </div>
                    <div aria-hidden="true" className="relative h-1.5">
                        {milestones.map((milestone, i) => (
                            <span
                                key={milestone.key}
                                className="absolute top-0.5 h-1.5 w-px bg-border-strong"
                                style={
                                    i === 0
                                        ? { left: 0 }
                                        : i === milestones.length - 1
                                          ? { right: 0 }
                                          : {
                                                left: `${(milestone.us / total) * 100}%`,
                                            }
                                }
                            />
                        ))}
                    </div>
                </div>

                <div className="flex gap-3 type-small text-muted-foreground">
                    <span style={share(segments[0].us, total)}>
                        {milestones[0].label}
                    </span>
                    <span
                        className="flex justify-between gap-3"
                        style={
                            middle.length > 0
                                ? share(segments[1]?.us ?? 0, total)
                                : { flexGrow: 0 }
                        }
                    >
                        {middle.map((milestone) => (
                            <span key={milestone.key}>{milestone.label}</span>
                        ))}
                        <span className="ml-auto text-right">{last.label}</span>
                    </span>
                </div>
            </div>
        </div>
    );
}
