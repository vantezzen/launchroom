import { Link } from '@inertiajs/react';
import { CircleX, Info, TriangleAlert } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { MouseEvent } from 'react';
import { cn } from '@/lib/utils';

export type AttentionTone = 'error' | 'warning' | 'info';

const TONE: Record<
    AttentionTone,
    { icon: LucideIcon; iconClass: string; srPrefix: string }
> = {
    error: {
        icon: CircleX,
        iconClass: 'text-status-error',
        srPrefix: 'Critical: ',
    },
    warning: {
        icon: TriangleAlert,
        iconClass: 'text-status-progress',
        srPrefix: 'Warning: ',
    },
    info: { icon: Info, iconClass: 'text-muted-foreground', srPrefix: '' },
};

export type AttentionChipProps = {
    /** The problem in a word or two: "Unhealthy", "Errors 8%", "Disk 93%". */
    label: string;
    tone?: AttentionTone;
    /** How many of this kind ("Action needed 3"), shown after the label when > 1. */
    count?: number;
    /** Other problems folded into this chip, shown as "+2". */
    more?: number;
    /** Where the problem is shown in full (the alert's filtered view). */
    href?: string | null;
    /** Full sentence for the tooltip and screen readers. */
    title?: string;
    icon?: LucideIcon;
    /** Smaller variant for canvas nodes and cards. */
    size?: 'sm' | 'md';
    className?: string;
    /** Stop clicks from reaching a clickable parent (canvas node, card). */
    stopPropagation?: boolean;
};

/**
 * The one chip for "something here needs you" (design-system §13.3):
 * open alerts, Action needed findings, a failing certificate, a stale
 * backup. A calm neutral chip whose icon carries the tone (red ✕ critical,
 * amber ⚠ warning), with a word as the non-colour cue, so a node or card
 * with a problem reads differently without turning the card red. With an
 * `href` the chip is a link to where the problem is shown filtered.
 */
export function AttentionChip({
    label,
    tone = 'warning',
    count,
    more,
    href,
    title,
    icon,
    size = 'md',
    className,
    stopPropagation = false,
}: AttentionChipProps) {
    const config = TONE[tone];
    const Icon = icon ?? config.icon;
    const classes = cn(
        'inline-flex max-w-full min-w-0 shrink-0 items-center gap-1 rounded-md border border-border bg-card font-medium whitespace-nowrap text-foreground',
        size === 'sm' ? 'h-5 px-1.5 text-[11px]' : 'h-6 px-2 type-small',
        href &&
            'hover:border-border-strong hover:bg-(--hover) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--signal)',
        className,
    );
    const content = (
        <>
            <Icon
                aria-hidden="true"
                className={cn(
                    'shrink-0',
                    size === 'sm' ? 'size-3' : 'size-3.5',
                    config.iconClass,
                )}
            />
            <span className="sr-only">{config.srPrefix}</span>
            <span className="truncate">{label}</span>
            {count !== undefined && count > 1 && (
                <span className="text-muted-foreground tabular-nums">
                    {count}
                </span>
            )}
            {more !== undefined && more > 0 && (
                <span className="text-muted-foreground tabular-nums">
                    +{more}
                    <span className="sr-only"> more</span>
                </span>
            )}
        </>
    );
    const stop = (event: MouseEvent) => {
        if (stopPropagation) {
            event.stopPropagation();
        }
    };

    if (href) {
        return (
            <Link
                href={href}
                title={title}
                className={classes}
                onClick={stop}
                onPointerDown={stop}
            >
                {content}
            </Link>
        );
    }

    return (
        <span title={title} className={classes}>
            {content}
        </span>
    );
}

type OpenAlert = {
    severity: string;
    label: string;
    title: string;
    href: string | null;
};

/**
 * Open alerts as one chip: the most severe one's label, the others as
 * "+1", linking to the most severe alert's view.
 */
export function AlertsChip({
    alerts,
    size,
    className,
    stopPropagation,
    linked = true,
}: {
    alerts: OpenAlert[];
    size?: 'sm' | 'md';
    className?: string;
    stopPropagation?: boolean;
    /** False inside another link or button (a whole-card link). */
    linked?: boolean;
}) {
    if (alerts.length === 0) {
        return null;
    }

    const sorted = [...alerts].sort(
        (a, b) =>
            Number(b.severity === 'critical') -
            Number(a.severity === 'critical'),
    );
    const first = sorted[0];

    return (
        <AttentionChip
            label={first.label}
            tone={first.severity === 'critical' ? 'error' : 'warning'}
            more={alerts.length - 1}
            href={linked ? first.href : null}
            title={sorted.map((alert) => alert.title).join('\n')}
            size={size}
            className={className}
            stopPropagation={stopPropagation}
        />
    );
}
