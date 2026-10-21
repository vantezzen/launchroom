export type LogSection =
    | 'plan'
    | 'build'
    | 'release'
    | 'rollout'
    | 'health'
    | 'route'
    | 'runtime';

export const SECTION_ORDER: LogSection[] = [
    'plan',
    'build',
    'release',
    'rollout',
    'health',
    'route',
    'runtime',
];

export const SECTION_LABELS: Record<LogSection, string> = {
    plan: 'Plan',
    build: 'Build',
    release: 'Release command',
    rollout: 'Roll out',
    health: 'Health check',
    route: 'Switch traffic',
    runtime: 'Runtime logs',
};

export function isSection(value: string): value is LogSection {
    return (SECTION_ORDER as string[]).includes(value);
}

const ERROR_PATTERN =
    /(\berror\b|\bfailed\b|\bfatal\b|\bexception\b|ERR!|ERR_[A-Z_]+|\bpanic:|Traceback \(most recent call last\))/i;

/**
 * The first line that looks like an error in a failed step, for the red
 * gutter and the initial scroll position (docs/08 §3.5).
 */
export function firstErrorLine(
    lines: App.Data.Deployments.LogLineData[],
    plain: (line: App.Data.Deployments.LogLineData) => string,
): number | null {
    const match = lines.find((line) => ERROR_PATTERN.test(plain(line)));

    if (match) {
        return match.number;
    }

    const stderr = lines.find((line) => line.stream === 'stderr');

    return stderr?.number ?? lines.at(-1)?.number ?? null;
}
