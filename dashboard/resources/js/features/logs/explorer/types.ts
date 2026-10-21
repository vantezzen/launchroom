export type LogLevel = 'error' | 'warning' | 'info' | 'debug';

/** One line in the log explorer (runtime or build); the page maps its data to this. */
export type LogEntry = {
    /** Stable unique id (chunk id + line number, …). */
    id: string;
    /** ISO 8601 with milliseconds. */
    time: string;
    level: LogLevel;
    service: string;
    /** Replica index or container suffix ("2"). */
    replica?: string | null;
    server?: string | null;
    /** Short SHA of the deployment that produced the line. */
    deployment?: string | null;
    stream?: 'stdout' | 'stderr' | 'system' | null;
    /** Environment name (project- and team-wide searches). */
    environment?: string | null;
    /** Project name (team-wide searches). */
    project?: string | null;
    /** Raw line; may contain ANSI escapes. Rendered as text only. */
    message: string;
};

export const LEVEL_ORDER: LogLevel[] = ['error', 'warning', 'info', 'debug'];

export const LEVEL_LABEL: Record<LogLevel, string> = {
    error: 'Error',
    warning: 'Warning',
    info: 'Info',
    debug: 'Debug',
};
