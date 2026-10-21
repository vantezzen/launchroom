import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * One line of activity (design-system §5.4): avatar, a sentence with the
 * actors and objects in medium weight, and the age. Group with
 * `ActivityGroup` per day.
 */
export function ActivityItem({
    actor,
    avatarUrl,
    children,
    time,
    icon,
    className,
}: {
    /** Display name; initials are the fallback avatar. */
    actor: string;
    avatarUrl?: string | null;
    /** The sentence, e.g. <><b>mara</b> deployed <b>api</b> to production</>. */
    children: ReactNode;
    /** Relative time element (e.g. <TimeAgo />) or text. */
    time: ReactNode;
    /** Optional small mark after the sentence (status icon). */
    icon?: ReactNode;
    className?: string;
}) {
    const initials = actor
        .split(/\s+/)
        .map((part) => part[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();

    return (
        <li className={cn('flex items-center gap-3 py-2.5', className)}>
            {avatarUrl ? (
                <img
                    src={avatarUrl}
                    alt=""
                    className="size-6 shrink-0 rounded-full"
                />
            ) : (
                <span
                    aria-hidden="true"
                    className="flex size-6 shrink-0 items-center justify-center rounded-full bg-(--hover) text-[10px] font-medium text-muted-foreground"
                >
                    {initials}
                </span>
            )}
            <p className="min-w-0 flex-1 truncate type-body text-muted-foreground [&_b]:font-medium [&_b]:text-foreground">
                {children}
            </p>
            {icon}
            <span className="shrink-0 type-small text-muted-foreground tabular-nums">
                {time}
            </span>
        </li>
    );
}

export function ActivityGroup({
    title,
    children,
    className,
}: {
    title: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <section className={cn('flex flex-col', className)} aria-label={title}>
            <h3 className="pb-1 type-label text-muted-foreground">{title}</h3>
            <ul className="divide-y">{children}</ul>
        </section>
    );
}
