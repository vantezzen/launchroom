import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type ObjectCardProps = {
    /** Card title (the object's name). */
    title: ReactNode;
    /** Whole card links here; the title is the single tab stop. */
    href?: NonNullable<InertiaLinkProps['href']>;
    /** Prefetch the linked page on hover, kept for `cacheFor` (Inertia). */
    prefetch?: InertiaLinkProps['prefetch'];
    cacheFor?: InertiaLinkProps['cacheFor'];
    /** 150 px recessed stage on top (device, mini canvas, preview). */
    media?: ReactNode;
    /** Small chip at the top-left of the stage ("Hetzner, Frankfurt"). */
    mediaLabel?: ReactNode;
    /** Quiet text at the top-right of the stage ("12s ago"). */
    mediaMeta?: ReactNode;
    /** Small mark before the title (a project icon). */
    titleMedia?: ReactNode;
    /** Status next to the title (StatusPill / Status). */
    status?: ReactNode;
    /** Quiet text at the right of the title row. */
    meta?: ReactNode;
    /** Chips row (roles, environments). */
    chips?: ReactNode;
    /** Body (metrics, last deploy …). */
    children?: ReactNode;
    /** Facts row at the bottom, separated by a hairline. */
    footer?: ReactNode;
    /** Staged change: dashed signal outline (design-system §5). */
    staged?: boolean;
    /** Unreachable/failed objects tint their stage, nothing else. */
    tone?: 'default' | 'error';
    className?: string;
};

/**
 * Base for everything the user owns (design-system §1 "objects are cards",
 * §6): media stage → header → chips → body → facts footer. Level 1 surface,
 * 14 px radius, hover lifts the border only. With `href` the whole card is
 * clickable through a stretched title link (one tab stop); give inner
 * actions `relative z-10` to keep them clickable.
 */
export function ObjectCard({
    title,
    href,
    prefetch,
    cacheFor,
    media,
    mediaLabel,
    mediaMeta,
    titleMedia,
    status,
    meta,
    chips,
    children,
    footer,
    staged = false,
    tone = 'default',
    className,
}: ObjectCardProps) {
    return (
        <article
            className={cn(
                'group relative flex min-w-0 flex-col overflow-hidden rounded-[14px] border bg-card shadow-[0_1px_2px_rgb(0_0_0/0.04)] motion-safe:transition-colors motion-safe:duration-[120ms] dark:shadow-none',
                href &&
                    'hover:border-(--border-strong) has-[a[data-card-link]:focus-visible]:ring-2 has-[a[data-card-link]:focus-visible]:ring-(--signal)',
                staged &&
                    'outline-2 outline-offset-3 outline-(--signal) outline-dashed',
                className,
            )}
        >
            {media !== undefined && (
                <div
                    className={cn(
                        'relative flex h-[150px] items-center justify-center border-b bg-(--recessed)',
                        tone === 'error' && 'bg-(--status-error-soft)/40',
                    )}
                >
                    {media}
                    {mediaLabel && (
                        <span className="absolute top-3 left-3 rounded-full border bg-card/80 px-2 py-0.5 type-small text-muted-foreground backdrop-blur-none">
                            {mediaLabel}
                        </span>
                    )}
                    {mediaMeta && (
                        <span className="absolute top-3.5 right-3.5 type-small text-muted-foreground tabular-nums">
                            {mediaMeta}
                        </span>
                    )}
                </div>
            )}
            <div className="flex flex-1 flex-col gap-4 p-5">
                <div className="flex flex-col gap-2.5">
                    <div className="flex min-w-0 items-center gap-2.5">
                        {titleMedia}
                        <h3 className="min-w-0 truncate type-heading">
                            {href ? (
                                <Link
                                    href={href}
                                    prefetch={prefetch}
                                    cacheFor={cacheFor}
                                    data-card-link
                                    className="outline-none after:absolute after:inset-0 after:content-['']"
                                >
                                    {title}
                                </Link>
                            ) : (
                                title
                            )}
                        </h3>
                        {status}
                        {meta && (
                            <span className="ml-auto shrink-0 type-small text-muted-foreground">
                                {meta}
                            </span>
                        )}
                    </div>
                    {chips && (
                        <div className="flex flex-wrap gap-1.5">{chips}</div>
                    )}
                </div>
                {children}
                {footer && (
                    <div className="mt-auto flex items-center justify-between gap-3 border-t pt-3.5 type-small text-muted-foreground tabular-nums">
                        {footer}
                    </div>
                )}
            </div>
        </article>
    );
}

/** Neutral chip for roles/environments inside cards. */
export function CardChip({
    children,
    outline = false,
}: {
    children: ReactNode;
    outline?: boolean;
}) {
    return (
        <span
            className={cn(
                'inline-flex h-6 items-center rounded-full px-2.5 type-small',
                outline
                    ? 'border text-muted-foreground'
                    : 'bg-(--hover) text-foreground',
            )}
        >
            {children}
        </span>
    );
}

/** Loading placeholder with the card's shape (fades in after 300 ms, no shimmer). */
export function ObjectCardSkeleton({
    media = true,
    className,
}: {
    media?: boolean;
    className?: string;
}) {
    return (
        <div
            aria-hidden="true"
            className={cn(
                'flex flex-col overflow-hidden rounded-[14px] border bg-card motion-safe:animate-in motion-safe:delay-300 motion-safe:duration-[180ms] motion-safe:fill-mode-both motion-safe:fade-in',
                className,
            )}
        >
            {media && <div className="h-[150px] border-b bg-(--recessed)" />}
            <div className="flex flex-col gap-3 p-5">
                <div className="h-5 w-1/3 rounded-md bg-(--hover)" />
                <div className="h-4 w-1/2 rounded-md bg-(--hover)" />
                <div className="mt-3 h-8 rounded-md bg-(--hover)" />
            </div>
        </div>
    );
}
