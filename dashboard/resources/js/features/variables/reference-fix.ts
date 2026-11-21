type Row = App.Data.Variables.VariableRowData;
type Fix = App.Data.Variables.ReferenceFixData;

/**
 * The suggestions for one broken-reference message of a row ("${{x.Y}}
 * refers to …"): the server names each broken expression once, and every
 * message starts with its expression.
 */
export function fixFor(row: Row, message: string): Fix | null {
    return row.fixes.find((fix) => message.startsWith(fix.expression)) ?? null;
}

/** The value with every use of the broken expression replaced. */
export function applyFix(
    value: string,
    expression: string,
    suggestion: string,
): string {
    return value.split(expression).join(suggestion);
}

/**
 * The page's alert: names the variable when there is one, so the sentence
 * says where to look before Fix scrolls there.
 */
export function brokenSummary(rows: Row[]): string | null {
    const broken = rows.filter((row) => row.errors.length > 0);

    if (broken.length === 0) {
        return null;
    }

    return broken.length === 1
        ? `${broken[0].key} has a broken reference. Deploys are blocked until it’s fixed.`
        : `${broken.length} variables have broken references. Deploys are blocked until they’re fixed.`;
}
