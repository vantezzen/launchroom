/**
 * Results out of the grid (docs/08 §15): CSV and JSON built in the
 * browser from the rows on screen. Nothing is stored or sent anywhere.
 */

type Cell = string | null;

function csvCell(value: Cell): string {
    if (value === null) {
        return '';
    }

    return /[",\r\n]/.test(value) || /^\s|\s$/.test(value)
        ? `"${value.replaceAll('"', '""')}"`
        : value;
}

/** RFC 4180 CSV with a header line; NULL is an empty field. */
export function toCsv(columns: string[], rows: Cell[][]): string {
    return [columns, ...rows]
        .map((row) => row.map(csvCell).join(','))
        .join('\r\n');
}

/** An array of objects keyed by column name (duplicates get `_2`, `_3`). */
export function toJson(columns: string[], rows: Cell[][]): string {
    const keys: string[] = [];
    const seen = new Map<string, number>();

    for (const column of columns) {
        const count = (seen.get(column) ?? 0) + 1;
        seen.set(column, count);
        keys.push(count === 1 ? column : `${column}_${count}`);
    }

    return JSON.stringify(
        rows.map((row) =>
            Object.fromEntries(keys.map((key, i) => [key, row[i] ?? null])),
        ),
        null,
        2,
    );
}

/** A safe file name: "orders-rows-1-50.csv". */
export function exportFileName(base: string, from: number, to: number): string {
    const slug =
        base
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .slice(0, 60) || 'result';

    return `${slug}-rows-${from}-${to}.csv`;
}

/** Saves text as a file through a temporary object URL. */
export function downloadText(name: string, text: string, type: string): void {
    const url = URL.createObjectURL(new Blob([text], { type }));
    const link = document.createElement('a');
    link.href = url;
    link.download = name;
    link.click();
    window.setTimeout(() => URL.revokeObjectURL(url), 0);
}
