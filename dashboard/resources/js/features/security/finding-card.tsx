import { ArrowRight } from 'lucide-react';
import { MetaLine } from '@/components/patterns/meta-line';
import { StatusIcon } from '@/components/status';
import { cn } from '@/lib/utils';
import { SEVERITY_LABEL, shortDate } from './format';
import type { Severity } from './format';

type Finding = App.Data.Security.FindingCardData;

/**
 * The severity as a word with its cue: red ✕ for critical and high, a
 * hollow dot otherwise (the word carries the meaning, colour adds to it).
 */
export function SeverityText({
    severity,
    cvss,
    className,
}: {
    severity: string;
    cvss?: number | null;
    className?: string;
}) {
    const level = severity as Severity;
    const urgent = level === 'critical' || level === 'high';

    return (
        <span
            className={cn(
                'inline-flex shrink-0 items-center gap-1.5',
                urgent ? 'font-medium text-status-error' : 'text-foreground',
                className,
            )}
        >
            <StatusIcon state={urgent ? 'failed' : 'idle'} />
            {SEVERITY_LABEL[level] ?? severity}
            {cvss !== null && cvss !== undefined && (
                <span className="font-normal text-muted-foreground tabular-nums">
                    {cvss.toFixed(1)}
                </span>
            )}
        </span>
    );
}

/** "⚠ Known exploited since 3 Oct" */
export function KnownExploited({ since }: { since: string | null }) {
    return (
        <span className="inline-flex items-center gap-1.5 font-medium text-foreground">
            <StatusIcon state="warning" />
            Known exploited{since ? ` since ${shortDate(since)}` : ''}
        </span>
    );
}

/** Severity, known exploited and the advisory id as one quiet line. */
export function SeverityLine({
    severity,
    cvss,
    knownExploited,
    knownExploitedSince,
    advisory,
    className,
}: {
    severity: string;
    cvss: number | null;
    knownExploited: boolean;
    knownExploitedSince: string | null;
    advisory?: string;
    className?: string;
}) {
    return (
        <MetaLine className={cn('type-small', className)}>
            <SeverityText severity={severity} cvss={cvss} />
            {knownExploited ? (
                <KnownExploited since={knownExploitedSince} />
            ) : null}
            {advisory ? (
                <code className="type-mono-small text-muted-foreground">
                    {advisory}
                </code>
            ) : null}
        </MetaLine>
    );
}

/** The package line: installed version, then the fix. */
export function PackageLine({
    finding,
    className,
}: {
    finding: Pick<Finding, 'package' | 'version' | 'fixedVersion'>;
    className?: string;
}) {
    return (
        <p
            className={cn(
                'flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 type-mono',
                className,
            )}
        >
            <span className="min-w-0 truncate">
                {finding.package}{' '}
                <span className="text-muted-foreground">{finding.version}</span>
            </span>
            {finding.fixedVersion && (
                <>
                    <ArrowRight
                        aria-label="fixed in"
                        className="size-3.5 shrink-0 text-faint"
                    />
                    <span>{finding.fixedVersion}</span>
                </>
            )}
        </p>
    );
}
