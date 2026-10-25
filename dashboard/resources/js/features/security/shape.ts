import type { StatusState } from '@/components/status';

type Finding = App.Data.Security.FindingCardData;

/** One vulnerability with every service it affects (docs/22 §9.2 part 2). */
export type ActionGroup = {
    key: string;
    advisory: string;
    title: string;
    severity: string;
    cvss: number | null;
    knownExploited: boolean;
    knownExploitedSince: string | null;
    ecosystem: string;
    package: string;
    /** Installed versions, in the order first seen. */
    versions: string[];
    fixedVersion: string | null;
    public: boolean;
    findings: Finding[];
};

const SEVERITY_RANK: Record<string, number> = {
    critical: 0,
    high: 1,
    medium: 2,
    low: 3,
    unknown: 4,
};

export function severityRank(severity: string): number {
    return SEVERITY_RANK[severity] ?? 5;
}

/**
 * "Action needed" grouped by advisory: one item per vulnerability with its
 * services, known exploited first, then severity, then the number of
 * services, then the advisory id (stable order across refetches).
 */
export function groupActionNeeded(findings: Finding[]): ActionGroup[] {
    const groups = new Map<string, ActionGroup>();

    for (const finding of findings) {
        const existing = groups.get(finding.advisory);

        if (!existing) {
            groups.set(finding.advisory, {
                key: finding.advisory,
                advisory: finding.advisory,
                title: finding.title ?? finding.advisory,
                severity: finding.severity,
                cvss: finding.cvss,
                knownExploited: finding.knownExploited,
                knownExploitedSince: finding.knownExploitedSince,
                ecosystem: finding.ecosystem,
                package: finding.package,
                versions: [finding.version],
                fixedVersion: finding.fixedVersion,
                public: finding.public,
                findings: [finding],
            });
            continue;
        }

        existing.findings.push(finding);

        if (!existing.versions.includes(finding.version)) {
            existing.versions.push(finding.version);
        }

        if (severityRank(finding.severity) < severityRank(existing.severity)) {
            existing.severity = finding.severity;
        }

        if (
            finding.cvss !== null &&
            (existing.cvss === null || finding.cvss > existing.cvss)
        ) {
            existing.cvss = finding.cvss;
        }

        existing.knownExploited ||= finding.knownExploited;
        existing.knownExploitedSince ??= finding.knownExploitedSince;
        existing.fixedVersion ??= finding.fixedVersion;
        existing.public ||= finding.public;
    }

    return [...groups.values()].sort(
        (a, b) =>
            Number(b.knownExploited) - Number(a.knownExploited) ||
            severityRank(a.severity) - severityRank(b.severity) ||
            b.findings.length - a.findings.length ||
            a.advisory.localeCompare(b.advisory),
    );
}

export type Verdict = {
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
export function verdictOf(investigation: Finding['investigation']): Verdict {
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
