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
