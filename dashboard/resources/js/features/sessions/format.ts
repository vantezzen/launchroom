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

/**
 * A table's row count from the schema list: exact counts as they are
 * ("6", "1,234", "48K" when long), catalog estimates marked: "~1.2M", or
 * "about 1.2M" in running text.
 */
export function rowCount(
    rows: number | undefined,
    estimate: boolean | undefined,
    inText = false,
): string | null {
    if (rows === undefined) {
        return null;
    }

    const compact = new Intl.NumberFormat('en', { notation: 'compact' });

    if (!estimate) {
        return rows < 10000 || inText
            ? new Intl.NumberFormat('en').format(rows)
            : compact.format(rows);
    }

    return `${inText ? 'about ' : '~'}${compact.format(rows)}`;
}
