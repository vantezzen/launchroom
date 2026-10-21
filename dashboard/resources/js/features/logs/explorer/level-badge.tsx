import { cn } from '@/lib/utils';
import type { LogLevel } from './types';
import { LEVEL_LABEL } from './types';

const tone: Record<LogLevel, string> = {
    error: 'bg-(--status-error-soft) text-status-error',
    warning: 'bg-(--status-progress-soft) text-status-progress',
    info: 'bg-(--hover) text-muted-foreground',
    debug: 'text-(--faint)',
};

/** Level chip: the word always, colour only for warning/error. */
export function LevelBadge({
    level,
    className,
}: {
    level: LogLevel;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex h-5 items-center rounded-[5px] px-1.5 text-[11px] leading-none font-medium',
                tone[level],
                className,
            )}
        >
            {LEVEL_LABEL[level]}
        </span>
    );
}
