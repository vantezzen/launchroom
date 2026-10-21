/** "4:12" or "1:02:03". */
export function clock(seconds: number): string {
    const s = Math.max(0, Math.floor(seconds));
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const rest = String(s % 60).padStart(2, '0');

    return h > 0
        ? `${h}:${String(m).padStart(2, '0')}:${rest}`
        : `${m}:${rest}`;
}

/** "12 s", "4 min", "1 h 5 min". */
export function duration(seconds: number | null): string | null {
    if (seconds === null) {
        return null;
    }

    if (seconds < 60) {
        return `${seconds} s`;
    }

    const minutes = Math.round(seconds / 60);

    return minutes < 60
        ? `${minutes} min`
        : `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
}

export function shellLabel(shell: string | null | undefined): string {
    if (!shell) {
        return 'Automatic';
    }

    return shell.replace('/bin/', '');
}

/** Human words for the end reasons launchroomd sends in its `end` frame. */
export function endSentence(reason: string): string {
    const known: Record<string, string> = {
        closed: 'You ended the session.',
        exited: 'The shell exited.',
        idle: 'Ended after a while without input.',
        deadline: 'Reached the session’s time limit.',
        disconnected: 'The connection was lost.',
        ended: 'The session was ended from the dashboard.',
        never_connected: 'The browser didn’t connect in time.',
        refused: 'This server doesn’t allow sessions of this kind.',
        error: 'Something went wrong on the server.',
    };

    return known[reason] ?? reason;
}
