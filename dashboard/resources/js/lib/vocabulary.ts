import { vocabulary } from '@/types/vocabulary.generated';

export { vocabulary };

/** The word for a value that arrives as a plain string (stored rows, free-form payloads). */
export function labelOf(
    table: Readonly<Record<string, { label: string }>>,
    value: string | null | undefined,
): string | undefined {
    return value != null && Object.hasOwn(table, value)
        ? table[value].label
        : undefined;
}

/** `{value: word}` for one enum. */
export function labels<K extends string>(
    table: Readonly<Record<K, { label: string }>>,
): Record<K, string> {
    return Object.fromEntries(
        Object.entries<{ label: string }>(table).map(([key, { label }]) => [
            key,
            label,
        ]),
    ) as Record<K, string>;
}
