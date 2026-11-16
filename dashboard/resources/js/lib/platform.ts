/** Apple keyboards: ⌘ instead of Ctrl. False outside a browser. */
export function isMac(): boolean {
    return (
        typeof navigator !== 'undefined' &&
        /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent)
    );
}

/** The modifier's label: "⌘" on Apple keyboards, "Ctrl" elsewhere. */
export function modKey(): string {
    return isMac() ? '⌘' : 'Ctrl';
}

/** A shortcut with the modifier, as one label: "⌘K" or "Ctrl K". */
export function modShortcut(key: string): string {
    return isMac() ? `⌘${key}` : `Ctrl ${key}`;
}
