export function isRecordSame<T extends Record<string, string>>(a: T, b: T): boolean {
    return JSON.stringify(a) === JSON.stringify(b);
}
