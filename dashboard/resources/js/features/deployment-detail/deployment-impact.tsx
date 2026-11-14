import { TriangleAlert, Undo2 } from 'lucide-react';
import { Sparkline } from '@/components/charts/sparkline';
import { Button } from '@/components/ui/button';
import { shortSha } from '@/features/deployments/format';
import {
    delta,
    formatErrorRate,
    formatMs,
    formatRate,
} from '@/features/metrics/format';
import { cn } from '@/lib/utils';

type Impact = App.Data.Deployments.DeploymentImpactData;
type Window = App.Data.Deployments.DeploymentImpactWindowData;
type Summary = App.Data.Deployments.DeploymentSummaryData;

/**
 * "Since this deploy" (docs/08 §12): requests, error rate and p95 since
 * the deployment went live against the hour before, the error rate around
 * the deploy as a sparkline with the moment it went live, and, when errors
 * rose past the team's alert threshold, an offer to roll back to the
 * deployment it replaced. Renders nothing without traffic.
 */
export function DeploymentImpact({
    impact,
    onRollback,
}: {
    impact: Impact;
    onRollback: (deployment: Summary) => void;
}) {
    const { before, after } = impact;
    const times = impact.timestamps.map((t) => Date.parse(t));
    const liveAt = Date.parse(impact.liveAt);
    const first = times[0] ?? liveAt;
    const last =
        (times[times.length - 1] ?? liveAt) + impact.bucketSeconds * 1000;
    const marker = Math.min(
        100,
        Math.max(0, ((liveAt - first) / Math.max(1, last - first)) * 100),
    );
    const period = impact.replaced
        ? `The ${impact.minutesAfter} ${impact.minutesAfter === 1 ? 'minute' : 'minutes'} it served, against the hour before`
        : impact.minutesAfter >= 60
          ? 'The first hour, against the hour before'
          : `The ${impact.minutesAfter} ${impact.minutesAfter === 1 ? 'minute' : 'minutes'} since it went live, against the hour before`;
    const rollback = impact.rollbackTo;

    return (
        <section
            aria-labelledby="deployment-impact"
            className="flex flex-col gap-5 rounded-xl border bg-card p-5 shadow-card sm:p-6"
        >
            <div className="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
                <div className="flex min-w-0 flex-col gap-0.5">
                    <h2 id="deployment-impact" className="type-heading">
                        Since this deploy
                    </h2>
                    <p className="type-small text-muted-foreground">{period}</p>
                </div>
                {impact.worse && rollback && (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => onRollback(rollback)}
                    >
                        <Undo2 aria-hidden="true" />
                        Roll back to{' '}
                        {shortSha(rollback.commitSha) ?? rollback.id}
                    </Button>
                )}
            </div>

            {impact.worse && (
                <p className="flex items-start gap-2 type-body">
                    <TriangleAlert
                        aria-hidden="true"
                        className="mt-0.5 size-4 shrink-0 text-status-progress"
                    />
                    <span>
                        <span className="sr-only">Warning: </span>
                        {before?.errorRatePct != null
                            ? `Errors went from ${formatErrorRate(before.errorRatePct)} to ${formatErrorRate(after.errorRatePct ?? 0)} after this deploy.`
                            : `${formatErrorRate(after.errorRatePct ?? 0)} of requests failed after this deploy.`}
                    </span>
                </p>
            )}

            <dl className="grid grid-cols-1 gap-5 sm:grid-cols-3">
                <Figure
                    label="Requests"
                    value={`${formatRate(after.requestsPerSecond)}/s`}
                    before={
                        before
                            ? `${formatRate(before.requestsPerSecond)}/s`
                            : null
                    }
                />
                <Figure
                    label="Error rate"
                    value={
                        after.errorRatePct != null
                            ? formatErrorRate(after.errorRatePct)
                            : null
                    }
                    before={
                        before?.errorRatePct != null
                            ? formatErrorRate(before.errorRatePct)
                            : null
                    }
                    change={delta(
                        after.errorRatePct,
                        before?.errorRatePct,
                        'lowerIsBetter',
                        0.5,
                    )}
                />
                <Figure
                    label="p95 latency"
                    value={after.p95Ms != null ? formatMs(after.p95Ms) : null}
                    before={
                        before?.p95Ms != null ? formatMs(before.p95Ms) : null
                    }
                    change={latencyDelta(after, before)}
                />
            </dl>

            {times.length > 1 && (
                <div className="flex flex-col gap-1.5">
                    <div className="relative">
                        <Sparkline
                            values={impact.errorRatePct}
                            label="Error rate before and after this deploy"
                            height={32}
                            min={0}
                        />
                        <span
                            aria-hidden="true"
                            className="absolute inset-y-0 w-px border-l border-dashed border-(--signal)"
                            style={{ left: `${marker}%` }}
                        />
                    </div>
                    <p className="relative flex justify-between type-small text-muted-foreground">
                        <span>An hour before</span>
                        <span
                            className="absolute -translate-x-1/2 text-signal"
                            style={{ left: `${marker}%` }}
                        >
                            Went live
                        </span>
                        <span>
                            {impact.replaced
                                ? 'Replaced'
                                : impact.minutesAfter >= 60
                                  ? 'An hour after'
                                  : 'Now'}
                        </span>
                    </p>
                </div>
            )}
        </section>
    );
}

function latencyDelta(after: Window, before: Window | null) {
    return delta(after.p95Ms, before?.p95Ms, 'lowerIsBetter', 20);
}

function Figure({
    label,
    value,
    before,
    change,
}: {
    label: string;
    value: string | null;
    before: string | null;
    change?: { label: string; tone?: 'good' | 'bad' | 'neutral' };
}) {
    return (
        <div className="flex min-w-0 flex-col gap-1">
            <dt className="type-small text-muted-foreground">{label}</dt>
            <dd className="flex flex-wrap items-baseline gap-x-2">
                <span className="type-stat tabular-nums">{value ?? '–'}</span>
                {change && change.tone !== 'neutral' && (
                    <span
                        className={cn(
                            'type-small tabular-nums',
                            change.tone === 'bad'
                                ? 'text-status-error'
                                : 'text-status-ok',
                        )}
                    >
                        {change.label}
                    </span>
                )}
            </dd>
            <dd className="type-small text-muted-foreground tabular-nums">
                {before
                    ? `${before} the hour before`
                    : 'No requests the hour before'}
            </dd>
        </div>
    );
}
