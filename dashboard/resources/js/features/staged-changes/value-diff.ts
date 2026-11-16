/** One changed leaf of a staged object value: `healthcheck.path` `/` → `/api/health`. */
export type ValueChange = {
    /** Dotted key below the item ("path", "limits.memory"). */
    key: string;
    /** The key in words ("Path", "Timeout seconds"). */
    label: string;
    before: string;
    after: string;
};

function parse(value: string | null): unknown {
    if (value === null) {
        return null;
    }

    try {
        return JSON.parse(value) as unknown;
    } catch {
        return value;
    }
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function leaves(
    value: Record<string, unknown>,
    prefix = '',
): Map<string, unknown> {
    const out = new Map<string, unknown>();

    Object.entries(value).forEach(([key, inner]) => {
        const path = prefix === '' ? key : `${prefix}.${key}`;

        if (isRecord(inner) && Object.keys(inner).length > 0) {
            leaves(inner, path).forEach((leaf, at) => out.set(at, leaf));
        } else {
            out.set(path, inner);
        }
    });

    return out;
}

function show(value: unknown): string {
    if (value === undefined || value === null) {
        return 'default';
    }

    if (typeof value === 'string') {
        return value === '' ? 'empty' : value;
    }

    if (
        Array.isArray(value) &&
        value.every((part) => typeof part === 'string')
    ) {
        return value.length === 0 ? 'none' : value.join(' ');
    }

    return JSON.stringify(value);
}

/** "timeoutSeconds" → "Timeout seconds", "limits.memoryMb" → "Limits memory mb". */
export function keyLabel(key: string): string {
    const words = key
        .replace(/\./g, ' ')
        .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
        .toLowerCase()
        .trim();

    return words.charAt(0).toUpperCase() + words.slice(1);
}

/**
 * The changed leaves of two JSON object values (docs/08 §8, review sheet):
 * only what differs, with unchanged keys left out. Null when either side
 * isn't an object, so scalars keep their one-line before → after.
 */
export function diffValues(
    before: string | null,
    after: string | null,
): ValueChange[] | null {
    const old = parse(before);
    const next = parse(after);

    if (!isRecord(old) || !isRecord(next)) {
        return null;
    }

    const a = leaves(old);
    const b = leaves(next);
    const keys = [...new Set([...a.keys(), ...b.keys()])];

    return keys
        .filter(
            (key) => JSON.stringify(a.get(key)) !== JSON.stringify(b.get(key)),
        )
        .map((key) => ({
            key,
            label: keyLabel(key),
            before: show(a.get(key)),
            after: show(b.get(key)),
        }));
}
