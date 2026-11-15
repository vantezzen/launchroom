import { formatBytes, formatMs } from '@/lib/format';

/** "about 3 minutes left" style, compact: "3m left". */
export function timeLeft(seconds: number | null): string | null {
    if (seconds === null) {
        return null;
    }

    return `${formatMs(seconds * 1000)} left`;
}

export function rate(bytesPerSecond: number | null): string | null {
    return bytesPerSecond === null ? null : `${formatBytes(bytesPerSecond)}/s`;
}
