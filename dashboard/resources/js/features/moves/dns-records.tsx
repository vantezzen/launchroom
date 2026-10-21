import { ArrowRight } from 'lucide-react';
import type { StatusState } from '@/components/status';
import { Status } from '@/components/status';
import { Skeleton } from '@/components/ui/skeleton';

const checkStates: Record<string, [StatusState, string]> = {
    ok: ['active', 'Points here'],
    missing: ['warning', 'No record yet'],
    mismatch: ['warning', 'Not switched yet'],
    unknown: ['warning', 'Can’t compare'],
    error: ['warning', 'Lookup failed'],
};

const kindLabels: Record<string, string> = {
    dashboard: 'Dashboard',
    apps: 'Apps',
    domain: 'Domain',
};

/**
 * DNS records a move asks the user to change (docs/14 §13.2): the host,
 * which address to replace and with what, and the live check once the
 * deferred lookups arrived.
 */
export function DnsRecords({
    records,
    live,
}: {
    records: App.Data.Moves.MoveDnsRecordData[];
    /** The same records with live checks; undefined while they load. */
    live?: App.Data.Moves.MoveDnsRecordData[];
}) {
    if (records.length === 0) {
        return (
            <p className="type-body text-muted-foreground">
                No DNS record changes: your domains don’t point at this server.
            </p>
        );
    }

    return (
        <ul className="flex flex-col divide-y rounded-xl border bg-card shadow-card">
            {records.map((record) => {
                const check =
                    live?.find((entry) => entry.host === record.host)?.check ??
                    null;
                const [state, label] =
                    check === null
                        ? ['idle' as StatusState, 'Not checked']
                        : (checkStates[check.status] ?? [
                              'warning' as StatusState,
                              check.status,
                          ]);

                return (
                    <li
                        key={`${record.kind}:${record.host}`}
                        className="flex flex-col gap-1.5 px-5 py-4"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <span className="flex min-w-0 items-baseline gap-2">
                                <span className="type-small text-muted-foreground">
                                    {kindLabels[record.kind] ?? record.kind}
                                </span>
                                <span className="type-mono-small break-all">
                                    {record.host}
                                </span>
                            </span>
                            {live === undefined ? (
                                <Skeleton className="h-4 w-24" />
                            ) : (
                                <Status state={state} label={label} />
                            )}
                        </div>
                        <span className="flex flex-wrap items-center gap-x-2 gap-y-1 type-mono-small text-muted-foreground">
                            <span className="line-through decoration-1">
                                {record.remove.join(', ') || 'old address'}
                            </span>
                            <ArrowRight
                                aria-hidden="true"
                                className="size-3.5 shrink-0"
                            />
                            <span className="sr-only">replace with</span>
                            <span className="text-foreground">
                                {record.addresses.join(', ') ||
                                    'the new server’s address'}
                            </span>
                        </span>
                        {check !== null && check.status !== 'ok' && (
                            <span className="type-small text-muted-foreground">
                                {check.message}
                            </span>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}
