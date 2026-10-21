import type { StatusState } from '@/components/status';
import { Status } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';

type Check = App.Data.Domains.DnsCheckData;

const states: Record<string, StatusState> = {
    ok: 'active',
    mismatch: 'failed',
    missing: 'warning',
    unknown: 'warning',
    error: 'warning',
};

const labels: Record<string, string> = {
    ok: 'DNS verified',
    mismatch: 'DNS points elsewhere',
    missing: 'No DNS record yet',
    unknown: 'Can’t compare yet',
    error: 'DNS check failed',
};

export function dnsCheckState(check: Check): StatusState {
    return states[check.status] ?? 'idle';
}

/**
 * A live DNS check (docs/05 §5): status with icon and label, what was
 * found, and when it was checked.
 */
export function DnsCheckResult({
    result,
    compact = false,
}: {
    result: Check;
    compact?: boolean;
}) {
    return (
        <div className="flex flex-col gap-1" role="status">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <Status
                    state={dnsCheckState(result)}
                    label={labels[result.status] ?? result.status}
                />
                <span className="type-small text-muted-foreground">
                    Checked <TimeAgo value={result.checkedAt} />
                </span>
            </div>
            {!compact || result.status !== 'ok' ? (
                <p className="max-w-[65ch] type-small text-muted-foreground">
                    {result.message}
                    {result.cname && (
                        <>
                            {' '}
                            Via CNAME{' '}
                            <span className="font-mono">{result.cname}</span>.
                        </>
                    )}
                </p>
            ) : null}
        </div>
    );
}
