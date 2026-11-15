import { MetaLine } from '@/components/patterns/meta-line';
import { StatusIcon } from '@/components/status';
import { cn } from '@/lib/utils';
import { labelOf, vocabulary } from '@/lib/vocabulary';
import { shortDate } from './format';

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
    const table = vocabulary.Security.Severity;
    const urgent =
        Object.hasOwn(table, severity) &&
        table[severity as keyof typeof table].isHighOrCritical;

    return (
        <span
            className={cn(
                'inline-flex shrink-0 items-center gap-1.5',
                urgent ? 'font-medium text-status-error' : 'text-foreground',
                className,
            )}
        >
            <StatusIcon state={urgent ? 'failed' : 'idle'} />
            {labelOf(vocabulary.Security.Severity, severity) ?? severity}
            {cvss !== null && cvss !== undefined && (
                <span className="font-normal text-muted-foreground tabular-nums">
                    {cvss.toFixed(1)}
                </span>
            )}
        </span>
    );
}

/** "⚠ Known exploited since 3 Oct" */
function KnownExploited({ since }: { since: string | null }) {
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
