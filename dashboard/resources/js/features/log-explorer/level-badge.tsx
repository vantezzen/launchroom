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

/**
 * The level word before a message in the table: only warnings and errors
 * say it (their row also carries the coloured rule), info and debug stay
 * silent.
 */
export function LevelWord({ level }: { level: LogLevel }) {
    if (level !== 'error' && level !== 'warning') {
        return null;
    }

    return (
        <span
            className={cn(
                'mr-2 font-sans text-[11px] font-medium',
                level === 'error'
                    ? 'text-status-error'
                    : 'text-status-progress',
            )}
        >
            {LEVEL_LABEL[level]}
        </span>
    );
}
