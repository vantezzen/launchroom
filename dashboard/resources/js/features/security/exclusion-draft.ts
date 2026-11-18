import { listNames, plural } from '@/lib/format';

type Draft = App.Data.Security.WafExclusionDraftData;

/** The request body of "Allow requests like this": one exception per rule. */
export function allowBody(draft: Draft): {
    exclusions: { rule: number; path: string | null; target: string | null }[];
} {
    return {
        exclusions: draft.rules.map((r) => ({
            rule: r.rule,
            path: draft.path,
            target: r.target,
        })),
    };
}

/** The button label: "Add exception" or "Add 2 exceptions". */
export function allowLabel(draft: Draft): string {
    return draft.rules.length === 1
        ? 'Add exception'
        : `Add ${plural(draft.rules.length, 'exception')}`;
}

/**
 * Why requests like this can still be blocked after the exceptions, or
 * null when the exceptions cover every rule that scored.
 */
export function uncoveredNote(draft: Draft): string | null {
    if (draft.uncovered.length === 0) {
        return null;
    }

    const rules = listNames(draft.uncovered.map(String));

    return draft.uncovered.length === 1
        ? `Rule ${rules} can't be excepted, so requests like this can still be blocked.`
        : `Rules ${rules} can't be excepted, so requests like this can still be blocked.`;
}

/**
 * The toast after adding: it says requests are allowed only when the
 * exceptions cover every rule that scored.
 */
export function allowedToast(draft: Draft, added: number): string {
    if (draft.uncovered.length > 0) {
        return added === 0
            ? 'These exceptions already exist. Requests like this can still be blocked.'
            : `${plural(added, 'exception')} added. Requests like this can still be blocked.`;
    }

    return added === 0
        ? 'These exceptions already exist'
        : 'Requests like this are allowed now';
}
