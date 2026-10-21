export type DiffLine = {
    kind: 'same' | 'added' | 'removed';
    text: string;
    number: number | null;
};

/**
 * A line diff (LCS) of two Dockerfiles for the diff-style view: the new
 * file's line numbers, removed lines without a number. Without a previous
 * version every line is an addition.
 */
export function diffLines(previous: string | null, next: string): DiffLine[] {
    const b = next.replace(/\n$/, '').split('\n');

    if (previous === null) {
        return b.map((text, i) => ({ kind: 'added', text, number: i + 1 }));
    }

    const a = previous.replace(/\n$/, '').split('\n');
    const rows = a.length;
    const cols = b.length;
    const table: number[][] = Array.from({ length: rows + 1 }, () =>
        Array.from({ length: cols + 1 }, () => 0),
    );

    for (let i = rows - 1; i >= 0; i--) {
        for (let j = cols - 1; j >= 0; j--) {
            table[i][j] =
                a[i] === b[j]
                    ? table[i + 1][j + 1] + 1
                    : Math.max(table[i + 1][j], table[i][j + 1]);
        }
    }

    const out: DiffLine[] = [];
    let i = 0;
    let j = 0;

    while (i < rows || j < cols) {
        if (i < rows && j < cols && a[i] === b[j]) {
            out.push({ kind: 'same', text: b[j], number: j + 1 });
            i++;
            j++;
        } else if (
            j < cols &&
            (i >= rows || table[i][j + 1] >= table[i + 1][j])
        ) {
            out.push({ kind: 'added', text: b[j], number: j + 1 });
            j++;
        } else {
            out.push({ kind: 'removed', text: a[i], number: null });
            i++;
        }
    }

    return out;
}
