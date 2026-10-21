import { useMemo, useState } from 'react';
import { ErrorExcerpt, StepChip } from '@/components/steps';
import type { Step, StepStatus } from '@/components/steps';
import { elapsedSeconds, formatDuration } from '@/features/deployments/format';
import { stripAnsi } from '@/features/logs/ansi';
import { AnsiText } from '@/features/logs/ansi-text';
import type { LogSection } from '@/features/logs/sections';
import {
    firstErrorLine,
    isSection,
    SECTION_LABELS,
} from '@/features/logs/sections';
import { cn } from '@/lib/utils';

type Detail = App.Data.Deployments.DeploymentDetailData;
type LogLine = App.Data.Deployments.LogLineData;

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
const ERROR =
    /(\berror\b|\bfailed\b|\bfatal\b|\bexception\b|ERR!|ERR_[A-Z_]+|\bpanic:)/i;

const plain = (line: LogLine) => stripAnsi(line.text);

export type StepRow = {
    section: LogSection;
    status: StepStatus;
    seconds: number | null;
    message: string | null;
};

/**
 * The deployment's steps in pipeline order: recorded ones with their
 * durations, then the rest of the pipeline as waiting (in progress) or
 * skipped (failed or cancelled), so a failure reads "Build ✕ · Roll out
 * skipped · …" like the mockup.
 */
export function stepRows(detail: Detail): StepRow[] {
    const status = detail.deployment.status;
    const recorded = detail.steps
        .filter((step) => isSection(step.name))
        .map((step): StepRow => ({
            section: step.name as LogSection,
            status: step.status as StepStatus,
            seconds:
                step.startedAt === null
                    ? null
                    : elapsedSeconds(step.startedAt, step.finishedAt),
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
        message: null,
    }));

    return [...recorded, ...missing];
}

/** Lines of one section, numbered like the full log. */
function SectionLines({ lines }: { lines: LogLine[] }) {
    const width = String(lines.at(-1)?.number ?? 0).length;

    return (
        <ol className="max-h-[420px] overflow-auto bg-(--recessed) py-2 type-mono-small">
            {lines.map((line) => (
                <li key={line.number} className="flex min-w-max pr-4">
                    <a
                        href={`#L${line.number}`}
                        className="shrink-0 pr-4 pl-4 text-right text-(--faint) tabular-nums select-none hover:text-foreground"
                        style={{ width: `${width + 3}ch` }}
                        aria-label={`Line ${line.number}`}
                    >
                        {line.number}
                    </a>
                    <span
                        className={cn(
                            'whitespace-pre',
                            line.stream === 'stderr' &&
                                ERROR.test(plain(line)) &&
                                'text-status-error',
                        )}
                    >
                        <AnsiText text={line.text} />
                    </span>
                </li>
            ))}
        </ol>
    );
}

/** A failed step's body: ±5 lines around the first error, "Show all" expands in place. */
function FailedLines({ lines, error }: { lines: LogLine[]; error: number }) {
    const [all, setAll] = useState(false);

    if (all) {
        return <SectionLines lines={lines} />;
    }

    return (
        <ErrorExcerpt
            lines={lines.map((line) => ({
                number: line.number,
                text: line.text,
            }))}
            errorLine={error}
            totalLines={lines.length}
            onShowAll={lines.length > 11 ? () => setAll(true) : undefined}
        />
    );
}

/**
 * StepList rows for the deployment (design-system §5.4): status mark, name,
 * the step's own message as summary, error chip, duration right. A step's
 * lines are its body; the failed step opens on the lines around its first
 * error.
 */
export function useDeploymentSteps(
    detail: Detail,
    lines: LogLine[],
): { steps: Step[]; errorLine: number | null } {
    return useMemo(() => {
        const bySection = new Map<string, LogLine[]>();

        for (const line of lines) {
            bySection.set(line.section, [
                ...(bySection.get(line.section) ?? []),
                line,
            ]);
        }

        let errorLine: number | null = null;

        const steps = stepRows(detail).map((row): Step => {
            const sectionLines = bySection.get(row.section) ?? [];
            const failed = row.status === 'failed';
            const error =
                failed && sectionLines.length > 0
                    ? firstErrorLine(sectionLines, plain)
                    : null;
            const errors = failed
                ? sectionLines.filter((line) => ERROR.test(plain(line))).length
                : 0;

            if (error !== null && errorLine === null) {
                errorLine = error;
            }

            return {
                id: row.section,
                name: SECTION_LABELS[row.section],
                status: row.status,
                summary: row.message ?? undefined,
                duration: formatDuration(row.seconds),
                chips:
                    errors > 0 ? (
                        <StepChip tone="error">
                            {errors} {errors === 1 ? 'error' : 'errors'}
                        </StepChip>
                    ) : undefined,
                content:
                    sectionLines.length === 0 ? undefined : error !== null ? (
                        <FailedLines lines={sectionLines} error={error} />
                    ) : (
                        <SectionLines lines={sectionLines} />
                    ),
            };
        });

        return { steps, errorLine };
    }, [detail, lines]);
}

/** Runtime lines shipped with the deployment (its workloads), if any. */
export function runtimeLines(lines: LogLine[]): LogLine[] {
    return lines.filter((line) => line.section === 'runtime');
}
