import { formatBytes } from '@/features/backups/format';

export { formatBytes };

/** "1m 13s", "42s", "2h 5m". */
export function formatDuration(ms: number): string {
    const seconds = Math.max(0, Math.round(ms / 1000));

    if (seconds < 60) {
        return `${seconds}s`;
    }

    const minutes = Math.floor(seconds / 60);

    if (minutes < 60) {
        return `${minutes}m ${seconds % 60}s`;
    }

    return `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
}

/** "about 3 minutes left" style, compact: "3m left". */
export function timeLeft(seconds: number | null): string | null {
    if (seconds === null) {
        return null;
    }

    return `${formatDuration(seconds * 1000)} left`;
}

export function rate(bytesPerSecond: number | null): string | null {
    return bytesPerSecond === null ? null : `${formatBytes(bytesPerSecond)}/s`;
}

export const engineFormats: Record<string, string> = {
    postgres:
        'pg_dump -Fc (custom) or plain SQL, optionally gzipped (.dump, .sql, .sql.gz)',
    mysql: 'mysqldump or mariadb-dump SQL, optionally gzipped (.sql, .sql.gz)',
    mongodb: 'mongodump --archive, optionally gzipped (.archive, .gz)',
};

export const urlExamples: Record<string, string> = {
    postgres: 'postgres://user:password@db.example.com:5432/app',
    mysql: 'mysql://user:password@db.example.com:3306/app',
    mongodb: 'mongodb://user:password@db.example.com:27017/app',
};
