import { expect, test } from 'vitest';
import { stepRows } from './steps';

type Step = App.Data.Deployments.DeploymentStepData;

function step(name: string, status: Step['status'], finished = true): Step {
    return {
        name,
        status,
        startedAt: '2026-10-25T10:00:00Z',
        finishedAt: finished ? '2026-10-25T10:00:30Z' : null,
        message: null,
    } as Step;
}

const shape = (rows: ReturnType<typeof stepRows>) =>
    rows.map((row) => `${row.section}:${row.status}`);

test('an expected Release shows from the start of the build', () => {
    expect(
        shape(
            stepRows({
                deployment: { status: 'building' },
                steps: [step('build', 'running', false)],
                expectsRelease: true,
            }),
        ),
    ).toEqual([
        'build:running',
        'release:pending',
        'rollout:pending',
        'health:pending',
        'route:pending',
    ]);
});

test('without an expected release the track has no Release step', () => {
    expect(
        shape(
            stepRows({
                deployment: { status: 'building' },
                steps: [step('build', 'running', false)],
            }),
        ),
    ).toEqual([
        'build:running',
        'rollout:pending',
        'health:pending',
        'route:pending',
    ]);
});

test('Release reads skipped when the build brought no release command', () => {
    expect(
        shape(
            stepRows({
                deployment: { status: 'rolling_out' },
                steps: [
                    step('build', 'succeeded'),
                    step('rollout', 'running', false),
                ],
                expectsRelease: true,
            }),
        ),
    ).toEqual([
        'build:succeeded',
        'release:skipped',
        'rollout:running',
        'health:pending',
        'route:pending',
    ]);
});

test('a live deployment keeps its skipped Release in place', () => {
    expect(
        shape(
            stepRows({
                deployment: { status: 'active' },
                steps: [
                    step('build', 'succeeded'),
                    step('rollout', 'succeeded'),
                    step('health', 'succeeded'),
                    step('route', 'succeeded'),
                ],
                expectsRelease: true,
            }),
        ),
    ).toEqual([
        'build:succeeded',
        'release:skipped',
        'rollout:succeeded',
        'health:succeeded',
        'route:succeeded',
    ]);
});

test('a failed build skips the rest, Release included', () => {
    expect(
        shape(
            stepRows({
                deployment: { status: 'failed' },
                steps: [step('build', 'failed')],
                expectsRelease: true,
            }),
        ),
    ).toEqual([
        'build:failed',
        'release:skipped',
        'rollout:skipped',
        'health:skipped',
        'route:skipped',
    ]);
});

test('a release that ran keeps its recorded status', () => {
    expect(
        shape(
            stepRows({
                deployment: { status: 'releasing' },
                steps: [
                    step('build', 'succeeded'),
                    step('release', 'running', false),
                ],
                expectsRelease: true,
            }),
        ),
    ).toEqual([
        'build:succeeded',
        'release:running',
        'rollout:pending',
        'health:pending',
        'route:pending',
    ]);
});
