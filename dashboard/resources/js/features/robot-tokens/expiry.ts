const DAY = 24 * 60 * 60 * 1000;

/** "Expires Feb 14, 2027", or "Expires in 5 days" (soon: under 7 days). */
export function expiryLine(
    expiresAt: string | null,
    now: number = Date.now(),
): { text: string; soon: boolean } {
    if (expiresAt === null) {
        return { text: 'Never expires', soon: true };
    }

    const end = new Date(expiresAt);
    const left = Math.ceil((end.getTime() - now) / DAY);

    if (left <= 0) {
        return { text: 'Expired', soon: true };
    }

    if (left <= 7) {
        return {
            text: left === 1 ? 'Expires tomorrow' : `Expires in ${left} days`,
            soon: true,
        };
    }

    return {
        text: `Expires ${end.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })}`,
        soon: false,
    };
}
