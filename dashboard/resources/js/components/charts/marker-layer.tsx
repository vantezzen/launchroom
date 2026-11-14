import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { formatTooltipTime } from './format';
import { LABEL_OFFSET } from './marker-groups';
import type { MarkerGroup } from './marker-groups';

/** Entries listed in one marker popover; the rest are counted. */
const MAX_LISTED = 8;
/** Grace period for moving the pointer from a tick into its popover. */
const CLOSE_DELAY_MS = 160;

/**
 * The interactive part of chart markers (design-system §13.8), shared by
 * TimeSeriesChart, StackedBars and Histogram, which draw the ticks in
 * their SVG: one hit area per tick (its label, or 16 px around an
 * unlabelled tick), and a popover listing the tick's events. Hovering
 * previews the list, a click, tap or Enter keeps it open, and every event
 * with an `href` is a link (its deployment), so "did my deploy cause this?"
 * is one click from any chart.
 */
export function MarkerLayer({
    groups,
    offset = 0,
    top = 0,
    span,
    onActiveChange,
}: {
    groups: MarkerGroup[];
    /** Left edge of the plot inside the positioned parent (px). */
    offset?: number;
    /** Top of the label row inside the parent (px). */
    top?: number;
    /** Time span of the chart (ms), for the time format. */
    span: number;
    /** The open tick's index, so the chart can dim the others. */
    onActiveChange?: (index: number | null) => void;
}) {
    const [open, setOpen] = useState<number | null>(null);
    const [pinned, setPinned] = useState(false);
    const closeTimer = useRef<number | null>(null);
    const notify = useRef(onActiveChange);

    useEffect(() => {
        notify.current = onActiveChange;
    });
    useEffect(() => notify.current?.(open), [open]);
    useEffect(
        () => () => {
            if (closeTimer.current !== null) {
                window.clearTimeout(closeTimer.current);
            }
        },
        [],
    );

    const cancelClose = () => {
        if (closeTimer.current !== null) {
            window.clearTimeout(closeTimer.current);
            closeTimer.current = null;
        }
    };
    const scheduleClose = () => {
        cancelClose();

        if (!pinned) {
            closeTimer.current = window.setTimeout(
                () => setOpen(null),
                CLOSE_DELAY_MS,
            );
        }
    };

    return (
        <>
            {groups.map((group, index) => {
                const labelLeft = group.showLabel
                    ? group.anchor === 'start'
                        ? group.x
                        : group.x - LABEL_OFFSET - group.labelWidth
                    : group.x - 8;
                const width = group.showLabel
                    ? group.labelWidth + LABEL_OFFSET + 4
                    : 16;
                const listed = group.markers.slice(0, MAX_LISTED);
                const isOpen = open === index;

                return (
                    <Popover
                        key={`${group.markers[0].t}-${group.label}`}
                        open={isOpen}
                        onOpenChange={(next) => {
                            if (!next) {
                                setOpen(null);
                                setPinned(false);
                            }
                        }}
                    >
                        <PopoverAnchor asChild>
                            <button
                                type="button"
                                aria-label={`${group.markers.length === 1 ? 'Event' : `${group.markers.length} events`}: ${group.markers
                                    .map(
                                        (marker) =>
                                            `${marker.detail ?? marker.label} at ${formatTooltipTime(marker.t, span)}`,
                                    )
                                    .join(', ')}`}
                                aria-expanded={isOpen}
                                aria-haspopup="dialog"
                                className="absolute z-[1] h-4 cursor-pointer rounded-[3px] outline-none focus-visible:ring-2 focus-visible:ring-(--signal)"
                                style={{
                                    left: Math.max(0, offset + labelLeft - 2),
                                    top,
                                    width,
                                }}
                                onPointerEnter={(event) => {
                                    if (event.pointerType === 'mouse') {
                                        cancelClose();
                                        setOpen(index);
                                    }
                                }}
                                onPointerLeave={(event) => {
                                    if (event.pointerType === 'mouse') {
                                        scheduleClose();
                                    }
                                }}
                                onClick={(event) => {
                                    event.stopPropagation();
                                    cancelClose();
                                    setOpen(index);
                                    setPinned(true);
                                }}
                                onKeyDown={(event) => {
                                    if (event.key === 'Escape') {
                                        setOpen(null);
                                        setPinned(false);
                                    }
                                }}
                            />
                        </PopoverAnchor>
                        <PopoverContent
                            side="top"
                            align="start"
                            sideOffset={4}
                            className="w-auto max-w-80 min-w-56 p-1.5"
                            onOpenAutoFocus={(event) => {
                                if (!pinned) {
                                    event.preventDefault();
                                }
                            }}
                            onPointerEnter={cancelClose}
                            onPointerLeave={scheduleClose}
                        >
                            <ul className="flex flex-col">
                                {listed.map((marker) => {
                                    const body = (
                                        <>
                                            <span className="shrink-0 text-muted-foreground tabular-nums">
                                                {formatTooltipTime(
                                                    marker.t,
                                                    span,
                                                )}
                                            </span>
                                            <span
                                                className={cn(
                                                    'min-w-0 flex-1 truncate font-mono',
                                                    marker.tone === 'error' &&
                                                        'text-status-error',
                                                )}
                                            >
                                                {marker.detail ?? marker.label}
                                            </span>
                                            {marker.href && (
                                                <ChevronRight
                                                    aria-hidden="true"
                                                    className="size-3.5 shrink-0 text-muted-foreground"
                                                />
                                            )}
                                        </>
                                    );
                                    const row =
                                        'flex min-w-0 items-center gap-2 rounded-md px-2 py-1.5 type-small whitespace-nowrap';

                                    return (
                                        <li key={`${marker.t}-${marker.label}`}>
                                            {marker.href ? (
                                                <Link
                                                    href={marker.href}
                                                    className={cn(
                                                        row,
                                                        'hover:bg-(--hover) focus-visible:bg-(--hover) focus-visible:outline-none',
                                                    )}
                                                >
                                                    {body}
                                                </Link>
                                            ) : (
                                                <span className={row}>
                                                    {body}
                                                </span>
                                            )}
                                        </li>
                                    );
                                })}
                                {group.markers.length > MAX_LISTED && (
                                    <li className="px-2 py-1 type-small text-muted-foreground">
                                        and {group.markers.length - MAX_LISTED}{' '}
                                        more
                                    </li>
                                )}
                            </ul>
                        </PopoverContent>
                    </Popover>
                );
            })}
        </>
    );
}
