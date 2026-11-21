import type { StatusState } from '@/components/status';

type Investigation = App.Data.Security.FindingInvestigationData | null;
type FixGroup = App.Data.Security.FixGroupData;
type FixService = App.Data.Security.FixGroupServiceData;

/**
 * The card's title, the fix as an instruction (docs/22 §9.2 part 2):
 * "Update next to 15.5.24", or "No fixed version of next yet".
 */
export function fixTitle(group: FixGroup): string {
    return group.fixedVersion
        ? `Update ${group.package} to ${group.fixedVersion}`
        : `No fixed version of ${group.package} yet`;
}

/**
 * Groups in the order the server ranked them, split into the ones that
 * touch production and the ones only in other environments (staging and
 * the like), which the page shows quieter and later.
 */
export function splitByProduction(groups: FixGroup[]): {
    production: FixGroup[];
    other: FixGroup[];
} {
    return {
        production: groups.filter((g) => g.production),
        other: groups.filter((g) => !g.production),
    };
}

/**
 * The one investigation result worth putting on the card: concerning
 * findings first, then things worth a look, naming the service ("2
 * concerning findings in node-hello"). Null when every check came back
 * clean, couldn't run or hasn't run: those stay in the service rows.
 */
export function worstVerdict(
    services: FixService[],
): { state: StatusState; text: string; more: number } | null {
    for (const state of ['failed', 'warning'] as const) {
        const hits = services.filter((s) => {
            const verdict = verdictOf(s.investigation);

            return (
                verdict.state === state &&
                !/couldn.t be checked/i.test(verdict.title)
            );
        });

        if (hits.length > 0) {
            const first = hits[0];
            const verdict = verdictOf(first.investigation);
            const title =
                state === 'failed'
                    ? verdict.title.replace(/ in the container$/, '')
                    : verdict.title;

            return {
                state,
                text: `${title} in ${first.service.name}`,
                more: hits.length - 1,
            };
        }
    }

    return null;
}

type Verdict = {
    state: StatusState;
    /** Short headline: "Nothing unusual found". */
    title: string;
    /** The other summary sentences ("37 requests matched …"). */
    detail: string | null;
    at: string | null;
};

/**
 * An investigation's card summary as a readable verdict (docs/22 §7.5).
 * The sentences come from `InvestigationReport::summary()`; the first one
 * says what the container check found.
 */
export function verdictOf(investigation: Investigation): Verdict {
    if (!investigation) {
        return {
            state: 'idle',
            title: 'Not investigated yet',
            detail: null,
            at: null,
        };
    }

    if (investigation.status === 'running') {
        return {
            state: 'progress',
            title: 'Checking the container',
            detail: null,
            at: investigation.startedAt,
        };
    }

    const at = investigation.finishedAt ?? investigation.startedAt;
    const [first = '', ...rest] = investigation.summary;
    const others = (sentences: string[]) =>
        sentences.length > 0 ? sentences.join(' ') : null;
    const concerning = /(\d[\d,]*) concerning (findings?)/.exec(first);
    const notable = /(\d[\d,]*) (things?) worth a look/.exec(first);

    if (concerning) {
        return {
            state: 'failed',
            title: `${concerning[1]} concerning ${concerning[2]} in the container`,
            detail: others(rest),
            at,
        };
    }

    if (notable) {
        return {
            state: 'warning',
            title: `${notable[1]} ${notable[2]} worth a look`,
            detail: others(rest),
            at,
        };
    }

    if (/nothing unusual/i.test(first)) {
        return {
            state: 'active',
            title: 'Nothing unusual found',
            detail: others(rest),
            at,
        };
    }

    if (/couldn.t be checked/i.test(first)) {
        return {
            state: 'warning',
            title: 'The container couldn’t be checked',
            detail: others(rest),
            at,
        };
    }

    return {
        state: 'idle',
        title: 'Checked',
        detail: others(investigation.summary),
        at,
    };
}

/**
 * The investigation result most of a group's services share ("The
 * container couldn't be checked" in 12 of 13), said once above the rows
 * instead of on each one. Null under three services or without a majority.
 */
export function commonVerdict(
    services: FixService[],
): { state: StatusState; title: string; count: number } | null {
    if (services.length < 3) {
        return null;
    }

    const counts = new Map<string, number>();

    for (const s of services) {
        const { title } = verdictOf(s.investigation);
        counts.set(title, (counts.get(title) ?? 0) + 1);
    }

    const [title, count] = [...counts.entries()].sort((a, b) => b[1] - a[1])[0];

    if (count * 2 <= services.length) {
        return null;
    }

    const sample = services.find(
        (s) => verdictOf(s.investigation).title === title,
    );

    return {
        state: verdictOf(sample?.investigation ?? null).state,
        title,
        count,
    };
}
