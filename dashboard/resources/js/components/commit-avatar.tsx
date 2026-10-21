import { useState } from 'react';
import { cn } from '@/lib/utils';

const sizes = {
    16: 'size-4 text-[9px]',
    20: 'size-5 text-[10px]',
    24: 'size-6 text-[10px]',
} as const;

export function initials(name: string): string {
    const parts = name
        .replace(/[^\p{L}\p{N} _.-]+/gu, '')
        .split(/[\s_.-]+/)
        .filter(Boolean);

    return (
        (parts.length > 1
            ? parts[0][0] + parts[1][0]
            : (parts[0] ?? '?').slice(0, 2)
        ).toUpperCase() || '?'
    );
}

/**
 * The commit author's GitHub avatar (docs/08 §13) next to a commit message.
 * Avatar URLs are built server-side on avatars.githubusercontent.com only
 * (the CSP allows nothing else); no URL or a failed load shows initials.
 * Decorative: the author's name is always written next to it.
 */
export function CommitAvatar({
    name,
    avatarUrl,
    size = 20,
    className,
}: {
    name: string | null;
    avatarUrl?: string | null;
    size?: keyof typeof sizes;
    className?: string;
}) {
    const [failed, setFailed] = useState<string | null>(null);

    if (!name && !avatarUrl) {
        return null;
    }

    const box = cn(
        'inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full',
        sizes[size],
        className,
    );

    if (avatarUrl && failed !== avatarUrl) {
        return (
            <img
                src={avatarUrl}
                alt=""
                aria-hidden="true"
                width={size}
                height={size}
                loading="lazy"
                decoding="async"
                referrerPolicy="no-referrer"
                onError={() => setFailed(avatarUrl)}
                className={cn(box, 'bg-(--hover) object-cover')}
            />
        );
    }

    return (
        <span
            aria-hidden="true"
            className={cn(
                box,
                'bg-(--hover) leading-none font-medium text-muted-foreground',
            )}
        >
            {size < 24
                ? initials(name ?? '?').slice(0, 1)
                : initials(name ?? '?')}
        </span>
    );
}
