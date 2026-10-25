import type { StepStatus } from '@/components/steps/step-icon';
import { STEP_STATUS_LABEL } from '@/components/steps/step-icon';
import { elapsedSeconds, formatDuration } from '@/lib/duration';
import { stripAnsi } from './ansi';
import type { LogSection } from './sections';
import { isSection, SECTION_LABELS, SECTION_ORDER } from './sections';

/**
 * The deployment's Steps log (docs/08 §3.5) as pure functions: lines grouped
 * by step, which steps are open, the failed step's error lines, search
 * matches and the plain-text export. `LogViewer` renders what these return.
 */

export type LogLine = App.Data.Deployments.LogLineData;

export type TimelineStep = {
    section: LogSection;
    status: StepStatus;
    duration: string | null;
    /** A running step's header ticks from here (`finishedAt` null). */
    startedAt?: string | null;
    finishedAt?: string | null;
    message: string | null;
};

export type StepRow =
    | {
          type: 'step';
          step: TimelineStep;
          /** Lines of the step (matches only while filtering). */
          count: number;
          errors: number;
          expanded: boolean;
          expandable: boolean;
      }
    | { type: 'substep'; name: string; section: LogSection }
    | { type: 'line'; line: LogLine }
    | { type: 'empty'; section: LogSection; text: string }
    | { type: 'more' };

/**
 * A first build has no registry cache yet: BuildKit reports its cache import
 * as an error and builds on uncached; any other import failure (e.g. a
 * registry token problem) doesn't fail the build either. launchroomd logs
 * these as notes (daemon/internal/build/solve.go); logs of older servers
 * still carry the raw "ERROR" line.
 */
export const NO_CACHE_YET =
    'No build cache yet for this service: this first build starts from scratch.';
const CACHE_IMPORT_ERROR =
    /^(#\d+ )?ERROR: failed to configure registry cache importer: (.*)$/s;
const CACHE_NOTE =
    /^(#\d+ )?(No build cache yet for this service|WARNING: Couldn't load the build cache)/;

const normalized = new WeakMap<LogLine, LogLine>();

/** The line as shown: a failed cache import is a note, never an error. */
export function normalizeLine(line: LogLine): LogLine {
    const cached = normalized.get(line);

    if (cached) {
        return cached;
    }

    const match = CACHE_IMPORT_ERROR.exec(plain(line));
    let result = line;

    if (match) {
        const reason = match[2].trim();
        result = {
            ...line,
            stream: 'stdout',
            text:
                (match[1] ?? '') +
                (reason.includes('not found')
                    ? NO_CACHE_YET
                    : `WARNING: Couldn't load the build cache, building without it (${reason})`),
        };
    }

    normalized.set(line, result);

    return result;
}

const plainText = new WeakMap<LogLine, string>();

/** The line without ANSI escapes (memoised per line object). */
export function plain(line: LogLine): string {
    let text = plainText.get(line);

    if (text === undefined) {
        text = stripAnsi(line.text);
        plainText.set(line, text);
    }

    return text;
}

export function sectionOf(line: LogLine): LogSection {
    return isSection(line.section) ? line.section : 'rollout';
}

export function groupBySection(lines: LogLine[]): Map<LogSection, LogLine[]> {
    const map = new Map<LogSection, LogLine[]>();

    for (const line of lines) {
        const section = sectionOf(line);
        const list = map.get(section);

        if (list) {
            list.push(line);
        } else {
            map.set(section, [line]);
        }
    }

    return map;
}

/**
 * The timeline plus steps that only have lines (runtime logs, unknown
 * steps), in pipeline order.
 */
export function withLoggedSteps(
    steps: TimelineStep[],
    bySection: Map<LogSection, LogLine[]>,
    live: boolean,
): TimelineStep[] {
    const known = new Set(steps.map((step) => step.section));
    const extra = SECTION_ORDER.filter(
        (section) => !known.has(section) && bySection.has(section),
    ).map((section): TimelineStep => ({
        section,
        status: section === 'runtime' && live ? 'running' : 'succeeded',
        duration: null,
        message: null,
    }));

    return [...steps, ...extra].sort(
        (a, b) =>
            SECTION_ORDER.indexOf(a.section) - SECTION_ORDER.indexOf(b.section),
    );
}

const ERROR_PATTERN =
    /(\berror\b|\bfailed\b|\bfatal\b|\bexception\b|ERR!|ERR_[A-Z_]+|\bpanic:|Traceback \(most recent call last\))/i;

export function looksLikeError(line: LogLine): boolean {
    const text = plain(line);

    return !CACHE_NOTE.test(text) && ERROR_PATTERN.test(text);
}

export type FailedStepErrors = {
    section: LogSection | null;
    /** The first error line: where the failed step opens. */
    first: number | null;
    /** Every line of the failed step that reads like an error. */
    lines: Set<number>;
};

/**
 * The failed step's error lines. Without a line that reads like an error,
 * the first stderr line (else the last line) is where it failed.
 */
export function failedStepErrors(
    steps: TimelineStep[],
    bySection: Map<LogSection, LogLine[]>,
): FailedStepErrors {
    const section =
        steps.find((step) => step.status === 'failed')?.section ?? null;
    const lines = (section ? (bySection.get(section) ?? []) : []).filter(
        (line) => !CACHE_NOTE.test(plain(line)),
    );
    const errors = new Set(
        lines.filter(looksLikeError).map((line) => line.number),
    );
    const first =
        lines.find((line) => errors.has(line.number))?.number ??
        lines.find((line) => line.stream === 'stderr')?.number ??
        lines.at(-1)?.number ??
        null;

    if (first !== null) {
        errors.add(first);
    }

    return { section, first, lines: errors };
}

/** A step opens when it has something to show. */
export function isExpandable(
    step: TimelineStep,
    bySection: Map<LogSection, LogLine[]>,
): boolean {
    return (
        (bySection.get(step.section)?.length ?? 0) > 0 ||
        step.status === 'running' ||
        (step.status === 'failed' && step.message !== null)
    );
}

/**
 * Open at first: the running and the failed step. A finished, successful
 * deployment starts collapsed, so it reads as a list of steps and times.
 */
export function initialExpanded(steps: TimelineStep[]): Set<LogSection> {
    return new Set(
        steps
            .filter(
                (step) => step.status === 'running' || step.status === 'failed',
            )
            .map((step) => step.section),
    );
}

/**
 * Open steps after a status change: a step that starts running or fails
 * opens; while following, a step that finished fine closes again unless
 * the reader opened or closed it themselves (`touched`).
 */
export function expandedAfterChange(
    current: Set<LogSection>,
    previous: TimelineStep[],
    steps: TimelineStep[],
    touched: Set<LogSection>,
    following: boolean,
): Set<LogSection> {
    const before = new Map(previous.map((step) => [step.section, step.status]));
    const next = new Set(current);

    for (const step of steps) {
        if (before.get(step.section) === step.status) {
            continue;
        }

        if (step.status === 'running' || step.status === 'failed') {
            next.add(step.section);
        } else if (
            step.status === 'succeeded' &&
            following &&
            !touched.has(step.section)
        ) {
            next.delete(step.section);
        }
    }

    return next;
}

/** Line numbers whose plain text contains `needle` (lowercase). */
export function findMatches(lines: LogLine[], needle: string): number[] {
    if (needle === '') {
        return [];
    }

    return lines
        .filter((line) => plain(line).toLowerCase().includes(needle))
        .map((line) => line.number);
}

/**
 * The virtual rows: a header per step, then the lines of the open ones
 * (only matches when `only` is set; a step with matches is open then).
 */
export function buildRows({
    steps,
    bySection,
    expanded,
    errors,
    hasMore,
    only = null,
}: {
    steps: TimelineStep[];
    bySection: Map<LogSection, LogLine[]>;
    expanded: Set<LogSection>;
    errors: FailedStepErrors;
    hasMore: boolean;
    only?: Set<number> | null;
}): StepRow[] {
    const rows: StepRow[] = [];

    for (const step of steps) {
        const all = bySection.get(step.section) ?? [];
        const lines = only ? all.filter((line) => only.has(line.number)) : all;
        const expandable = only
            ? lines.length > 0
            : isExpandable(step, bySection);
        const open =
            expandable && (only !== null || expanded.has(step.section));
        rows.push({
            type: 'step',
            step,
            count: lines.length,
            errors: step.section === errors.section ? errors.lines.size : 0,
            expanded: open,
            expandable,
        });

        if (!open) {
            continue;
        }

        if (lines.length === 0) {
            rows.push({
                type: 'empty',
                section: step.section,
                text:
                    step.message ??
                    (step.status === 'running'
                        ? 'Waiting for output…'
                        : 'No output for this step.'),
            });

            continue;
        }

        let sub: string | null = null;

        for (const line of lines) {
            if (line.step && line.step !== sub && line.step !== step.section) {
                sub = line.step;
                rows.push({
                    type: 'substep',
                    name: line.step,
                    section: step.section,
                });
            }

            rows.push({ type: 'line', line });
        }
    }

    if (hasMore) {
        rows.push({ type: 'more' });
    }

    return rows;
}

/** Plain text of the whole log, one heading per step, for copy and download. */
export function logText(
    steps: TimelineStep[],
    bySection: Map<LogSection, LogLine[]>,
): string {
    const out: string[] = [];

    for (const step of steps) {
        const lines = bySection.get(step.section) ?? [];
        const duration =
            step.startedAt && step.status === 'running'
                ? formatDuration(
                      elapsedSeconds(step.startedAt, step.finishedAt ?? null),
                  )
                : step.duration;
        const facts = [STEP_STATUS_LABEL[step.status], duration]
            .filter(Boolean)
            .join(', ');
        out.push(`== ${SECTION_LABELS[step.section]} (${facts}) ==`);

        if (step.message) {
            out.push(step.message);
        }

        for (const line of lines) {
            out.push(line.time ? `${line.time} ${plain(line)}` : plain(line));
        }

        out.push('');
    }

    return out.join('\n');
}
