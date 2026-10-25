import type { StepStatus } from '@/components/steps';
import { elapsedSeconds } from '@/features/deployments/format';
import type { LogSection } from '@/features/logs/sections';
import { isSection } from '@/features/logs/sections';

type Detail = App.Data.Deployments.DeploymentDetailData;

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
 * skipped · …" like the mockup.
 */
export function stepRows(detail: Detail, now = Date.now()): StepRow[] {
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

    if (rest === null) {
        return recorded;
    }

    const missing = PIPELINE.filter(
        (section) =>
            ORDER.indexOf(section) > last &&
            !recorded.some((step) => step.section === section),
    ).map((section): StepRow => ({
        section,
        status: rest,
        seconds: null,
        startedAt: null,
        finishedAt: null,
        message: null,
    }));

    return [...recorded, ...missing];
}
