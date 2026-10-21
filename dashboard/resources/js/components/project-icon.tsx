import { useState } from 'react';
import { Logo } from '@/components/logo';
import type { LogoSize } from '@/components/logo';
import { cn } from '@/lib/utils';

const tile: Record<LogoSize, string> = {
    20: 'size-5 rounded-[5px] p-[2px]',
    32: 'size-8 rounded-[9px] p-1',
    44: 'size-11 rounded-[11px] p-1.5',
};

/**
 * A project's icon (docs/08 §13): its favicon or uploaded image on the
 * same neutral tile as `Logo`, so icons and logos line up. Without an icon
 * (or when it fails to load) it falls back to the monogram, or renders
 * nothing with `fallback="none"` (project cards keep their calm header).
 */
export function ProjectIcon({
    name,
    iconUrl,
    size = 20,
    fallback = 'monogram',
    className,
}: {
    name: string;
    iconUrl?: string | null;
    size?: LogoSize;
    fallback?: 'monogram' | 'none';
    className?: string;
}) {
    const [failed, setFailed] = useState<string | null>(null);

    if (!iconUrl || failed === iconUrl) {
        return fallback === 'none' ? null : (
            <Logo
                kind="__project"
                name={name}
                size={size}
                decorative
                className={className}
            />
        );
    }

    return (
        <span
            aria-hidden="true"
            className={cn(
                'inline-flex shrink-0 items-center justify-center overflow-hidden border bg-card',
                tile[size],
                className,
            )}
        >
            <img
                src={iconUrl}
                alt=""
                width={size}
                height={size}
                decoding="async"
                draggable={false}
                onError={() => setFailed(iconUrl)}
                className="size-full rounded-[3px] object-contain"
            />
        </span>
    );
}
