import { describe, expect, test } from 'vite-plus/test';
import type { LogLine, TimelineStep } from './step-log';
import {
    buildRows,
    expandedAfterChange,
    failedStepErrors,
    findMatches,
    groupBySection,
    initialExpanded,
    logText,
    NO_CACHE_YET,
    normalizeLine,
    withLoggedSteps,
} from './step-log';
import { followAfterScroll } from './use-follow-scroll';

const line = (
    number: number,
    text: string,
    section: string,
    extra: Partial<LogLine> = {},
): LogLine =>
    ({
        number,
        text,
        section,
        time: '2026-10-21T12:00:00.000Z',
        stream: 'stdout',
        step: null,
        ...extra,
    }) as LogLine;

const step = (
    section: TimelineStep['section'],
    status: TimelineStep['status'],
): TimelineStep => ({ section, status, duration: null, message: null });

const lines = [
    line(1, 'Cloning', 'build', { step: 'clone' }),
    line(2, 'npm ci', 'build', { step: 'install' }),
    line(3, 'added 3 packages', 'build', { step: 'install' }),
    line(4, 'Starting', 'rollout'),
    line(5, 'web-1 listening', 'runtime'),
];

describe('step grouping', () => {
    test('lines go under their step, with sub-step headers', () => {
        const bySection = groupBySection(lines);
        const steps = withLoggedSteps(
            [step('build', 'succeeded'), step('rollout', 'running')],
            bySection,
            true,
        );
        expect(steps.map((item) => item.section)).toEqual([
            'build',
            'rollout',
            'runtime',
        ]);
        expect(steps[2].status).toBe('running');

        const rows = buildRows({
            steps,
            bySection,
            expanded: new Set(['build']),
            errors: failedStepErrors(steps, bySection),
            hasMore: true,
        });
        expect(
            rows.map((row) =>
                row.type === 'step'
                    ? `step:${row.step.section}:${row.expanded}`
                    : row.type === 'substep'
                      ? `sub:${row.name}`
                      : row.type === 'line'
                        ? `line:${row.line.number}`
                        : row.type,
            ),
        ).toEqual([
            'step:build:true',
            'sub:clone',
            'line:1',
            'sub:install',
            'line:2',
            'line:3',
            'step:rollout:false',
            'step:runtime:false',
            'more',
        ]);
    });

    test('a waiting step has nothing to open; a running one waits for output', () => {
        const steps = [step('build', 'running'), step('rollout', 'pending')];
        const rows = buildRows({
            steps,
            bySection: new Map(),
            expanded: new Set(['build', 'rollout']),
            errors: failedStepErrors(steps, new Map()),
            hasMore: false,
        });
        expect(rows).toMatchObject([
            { type: 'step', expandable: true, expanded: true },
            { type: 'empty', text: 'Waiting for output…' },
            { type: 'step', expandable: false, expanded: false },
        ]);
    });
});

describe('follow the running step', () => {
    test('the running and the failed step start open, a success starts closed', () => {
        expect([
            ...initialExpanded([
                step('build', 'succeeded'),
                step('rollout', 'running'),
            ]),
        ]).toEqual(['rollout']);
        expect([...initialExpanded([step('build', 'succeeded')])]).toEqual([]);
    });

    test('the next step opens and the finished one closes while following', () => {
        const before = [step('build', 'running'), step('rollout', 'pending')];
        const after = [step('build', 'succeeded'), step('rollout', 'running')];
        const open = expandedAfterChange(
            new Set(['build']),
            before,
            after,
            new Set(),
            true,
        );
        expect([...open]).toEqual(['rollout']);
    });

    test('paused, or opened by the reader, the finished step stays open', () => {
        const before = [step('build', 'running')];
        const after = [step('build', 'succeeded')];
        expect([
            ...expandedAfterChange(
                new Set(['build']),
                before,
                after,
                new Set(),
                false,
            ),
        ]).toEqual(['build']);
        expect([
            ...expandedAfterChange(
                new Set(['build']),
                before,
                after,
                new Set(['build']),
                true,
            ),
        ]).toEqual(['build']);
    });

    test('scrolling up pauses, back at the bottom resumes', () => {
        const box = (scrollTop: number) => ({
            scrollTop,
            scrollHeight: 2000,
            clientHeight: 500,
        });
        expect(followAfterScroll(true, 1500, box(1000), 'end').follow).toBe(
            false,
        );
        expect(followAfterScroll(false, 1000, box(1500), 'end').follow).toBe(
            true,
        );
    });
});

describe('failed step focus', () => {
    const failing = [
        line(1, 'npm run build', 'build'),
        line(2, 'compiling', 'build'),
        line(3, "\u001b[31mError\u001b[0m: Cannot find module 'x'", 'build', {
            stream: 'stderr',
        }),
        line(4, 'npm ERR! code 1', 'build', { stream: 'stderr' }),
        line(5, 'done', 'build'),
    ];

    test('opens on the first error and marks every error line', () => {
        const bySection = groupBySection(failing);
        const steps = [step('build', 'failed'), step('rollout', 'skipped')];
        const errors = failedStepErrors(steps, bySection);
        expect(errors.section).toBe('build');
        expect(errors.first).toBe(3);
        expect([...errors.lines]).toEqual([3, 4]);
        expect([...initialExpanded(steps)]).toEqual(['build']);

        const rows = buildRows({
            steps,
            bySection,
            expanded: initialExpanded(steps),
            errors,
            hasMore: false,
        });
        expect(rows[0]).toMatchObject({ type: 'step', errors: 2 });
    });

    test('without an error-looking line, the first stderr line', () => {
        const bySection = groupBySection([
            line(1, 'ok', 'health'),
            line(2, 'connection refused', 'health', { stream: 'stderr' }),
        ]);
        expect(
            failedStepErrors([step('health', 'failed')], bySection).first,
        ).toBe(2);
    });

    test('a missing build cache is a note, not the error', () => {
        const raw = line(
            41,
            '#41 ERROR: failed to configure registry cache importer: registry.launchroom.internal:5000/cache/svc_01m5fje8dayqrkeg0y2gqsap2k:buildcache: not found',
            'build',
            { stream: 'stderr', step: 'cache' },
        );
        const shown = normalizeLine(raw);
        expect(shown.text).toBe(`#41 ${NO_CACHE_YET}`);
        expect(shown.stream).toBe('stdout');
        expect(normalizeLine(raw)).toBe(shown);

        const bySection = groupBySection(
            [
                raw,
                ...failing.map((item) => ({
                    ...item,
                    number: item.number + 41,
                })),
            ].map(normalizeLine),
        );
        expect(
            failedStepErrors([step('build', 'failed')], bySection).first,
        ).toBe(44);
    });
});

test('any other failed cache import is a warning, not the error', () => {
    const raw = line(
        4,
        '#4 ERROR: failed to configure registry cache importer: failed to authorize: rpc error: code = PermissionDenied',
        'build',
        { stream: 'stderr', step: 'cache' },
    );
    const shown = normalizeLine(raw);
    expect(shown.text).toBe(
        "#4 WARNING: Couldn't load the build cache, building without it (failed to authorize: rpc error: code = PermissionDenied)",
    );
    const bySection = groupBySection([shown, line(5, 'boom', 'build')]);
    const errors = failedStepErrors([step('build', 'failed')], bySection);
    expect(errors.first).toBe(5);
    expect([...errors.lines]).toEqual([5]);
});

test('a cache note alone never becomes a failure highlight', () => {
    const lines = [
        normalizeLine(
            line(
                1,
                '#1 ERROR: failed to configure registry cache importer: cache: not found',
                'build',
                { stream: 'stderr' },
            ),
        ),
    ];
    const errors = failedStepErrors(
        [step('build', 'failed')],
        groupBySection(lines),
    );
    expect(errors.first).toBeNull();
    expect(errors.lines.size).toBe(0);
});

describe('search across steps', () => {
    test('matches plain text in every step, case-insensitive', () => {
        const colored = [
            ...lines,
            line(6, '\u001b[32mListening\u001b[0m on :3000', 'health'),
        ];
        expect(findMatches(colored, 'listening')).toEqual([5, 6]);
        expect(findMatches(colored, '')).toEqual([]);
    });

    test('only matches: steps with matches open, the rest closed', () => {
        const bySection = groupBySection(lines);
        const steps = withLoggedSteps(
            [step('build', 'succeeded'), step('rollout', 'succeeded')],
            bySection,
            false,
        );
        const rows = buildRows({
            steps,
            bySection,
            expanded: new Set(),
            errors: failedStepErrors(steps, bySection),
            hasMore: false,
            only: new Set(findMatches(lines, 'packages')),
        });
        expect(rows).toMatchObject([
            { type: 'step', count: 1, expanded: true },
            { type: 'substep', name: 'install' },
            { type: 'line', line: { number: 3 } },
            { type: 'step', count: 0, expanded: false, expandable: false },
            { type: 'step', count: 0, expanded: false },
        ]);
    });
});

test('the full log as text, one heading per step', () => {
    const bySection = groupBySection(lines);
    const text = logText(
        [
            { ...step('build', 'succeeded'), duration: '41s' },
            step('rollout', 'failed'),
        ],
        bySection,
    );
    expect(text).toContain('== Build (Done, 41s) ==');
    expect(text).toContain('2026-10-21T12:00:00.000Z npm ci');
    expect(text).toContain('== Roll out (Failed) ==');
});
