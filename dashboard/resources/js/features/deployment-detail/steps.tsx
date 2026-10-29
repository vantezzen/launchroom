import type { StepStatus } from '@/components/steps';
import { elapsedSeconds } from '@/features/deployments/format';
import type { LogSection } from '@/features/logs/sections';
import { isSection, SECTION_ORDER } from '@/features/logs/sections';

/** What the rows need: the status and the recorded steps (detail page, list). */
type StepSource = {
    deployment: { status: App.Enums.DeploymentStatus };
    steps: App.Data.Deployments.DeploymentStepData[];
    /**
     * Show "Release" from the start (docs/04 §6): the service has a release
     * command, or its build is likely to bring one (Laravel). Skipped when
     * the deployment moved past it without running it.
     */
    expectsRelease?: boolean;
};

/** What a deployment of this kind still has to do while it runs. */
const PIPELINE: LogSection[] = ['build', 'rollout', 'health', 'route'];
const ORDER: LogSection[] = [
    'plan',
    'build',
    'release',
    'rollout',
    'health',
    'route',
];

export type StepRow = {
    section: LogSection;
    status: StepStatus;
    seconds: number | null;
    /** Set for recorded steps: a running step ticks from `startedAt`. */
    startedAt: string | null;
    finishedAt: string | null;
    message: string | null;
};

/**
 * The deployment's steps in pipeline order: recorded ones with their
 * durations, then the rest of the pipeline as waiting (in progress) or
 * skipped (failed or cancelled), so a failure reads "Build ✕ · Roll out
 * skipped · …" like the mockup. An expected Release step is listed from
 * the start, so no step appears out of nowhere after the build; when the
 * deployment went past it without one, it reads skipped.
 */
export function stepRows(detail: StepSource, now = Date.now()): StepRow[] {
    const status = detail.deployment.status;
    const recorded = detail.steps
        .filter((step) => isSection(step.name))
        .map((step): StepRow => ({
            section: step.name as LogSection,
            status: step.status as StepStatus,
            seconds:
                step.startedAt === null
                    ? null
                    : elapsedSeconds(step.startedAt, step.finishedAt, now),
            startedAt: step.startedAt,
            finishedAt: step.finishedAt,
            message: step.message,
        }));
    const last = Math.max(
        -1,
        ...recorded.map((step) => ORDER.indexOf(step.section)),
    );
    const rest: StepStatus | null =
        status === 'failed'
            ? 'skipped'
            : status === 'cancelled'
              ? 'canceled'
              : status === 'active' ||
                  status === 'superseded' ||
                  status === 'ready'
                ? null
                : 'pending';

    const pipeline: LogSection[] = detail.expectsRelease
        ? ['build', 'release', 'rollout', 'health', 'route']
        : PIPELINE;
    const missing = pipeline
        .filter((section) => !recorded.some((step) => step.section === section))
        .flatMap((section): StepRow[] => {
            const passed = ORDER.indexOf(section) < last;

            // Steps before the last recorded one didn't run: only an
            // expected Release shows, as skipped (the build had none).
            if (passed && section !== 'release') {
                return [];
            }

            const state = passed ? 'skipped' : rest;

            return state === null ? [] : [placeholder(section, state)];
        });

    return [...recorded, ...missing].sort(
        (a, b) =>
            SECTION_ORDER.indexOf(a.section) - SECTION_ORDER.indexOf(b.section),
    );
}

function placeholder(section: LogSection, status: StepStatus): StepRow {
    return {
        section,
        status,
        seconds: null,
        startedAt: null,
        finishedAt: null,
        message: null,
    };
}
