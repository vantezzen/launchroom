import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
} from '@/components/ui/popover';
import { formatWindow } from '@/lib/observe-scope';
import type { ObserveWindow } from '@/lib/observe-scope';

export type DrillAction = {
    label: string;
    href: string;
    icon?: LucideIcon;
};

/**
 * What a chart offers for one bucket (design-system §13.8 "Drill-down"):
 * the page builds the links from the bucket's window and its scope,
 * usually "Logs from 14:00 to 15:00", "Errors only" and the deployments
 * around that time. Return [] to make a bucket not selectable.
 */
export type ChartDrill = (window: ObserveWindow) => DrillAction[];

/**
 * The popover a chart opens on a selected bucket: one link per action,
 * each naming the time it opens ("Logs from 14:00 to 15:00"). Anchored at the bucket (`x` inside the
 * chart's positioned frame); Esc or a click outside closes it.
 */
export function ChartDrillPopover({
    window: span,
    actions,
    x,
    top = 0,
    height,
    onClose,
}: {
    window: ObserveWindow | null;
    actions: DrillAction[];
    x: number;
    top?: number;
    height: number;
    onClose: () => void;
}) {
    return (
        <Popover
            open={span !== null && actions.length > 0}
            onOpenChange={(open) => !open && onClose()}
        >
            <PopoverAnchor asChild>
                <span
                    aria-hidden="true"
                    className="pointer-events-none absolute w-px"
                    style={{ left: x, top, height }}
                />
            </PopoverAnchor>
            <PopoverContent
                side="right"
                align="start"
                sideOffset={10}
                collisionPadding={12}
                className="w-auto max-w-72 min-w-52 p-1.5"
            >
                {span && (
                    <>
                        <p className="sr-only">{formatWindow(span)}</p>
                        <ul className="flex flex-col">
                            {actions.map((action) => {
                                const Icon = action.icon;

                                return (
                                    <li key={action.href}>
                                        <Link
                                            href={action.href}
                                            className="flex items-center gap-2 rounded-md px-2 py-1.5 type-body hover:bg-(--hover) focus-visible:bg-(--hover) focus-visible:outline-none"
                                        >
                                            {Icon && (
                                                <Icon
                                                    aria-hidden="true"
                                                    className="size-4 shrink-0 text-muted-foreground"
                                                />
                                            )}
                                            <span className="min-w-0 flex-1 truncate">
                                                {action.label}
                                            </span>
                                            <ChevronRight
                                                aria-hidden="true"
                                                className="size-3.5 shrink-0 text-muted-foreground"
                                            />
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    </>
                )}
            </PopoverContent>
        </Popover>
    );
}
