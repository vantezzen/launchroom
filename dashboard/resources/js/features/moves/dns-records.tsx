import { CopyButton } from '@/components/copy-button';
import { Status } from '@/components/status';
import { Skeleton } from '@/components/ui/skeleton';
import { dnsRecordState, fromMoveRecords } from '@/features/moves/shape';
import type { DnsSwitchRecord } from '@/features/moves/shape';
import { cn } from '@/lib/utils';

/**
 * The DNS switch after a move (docs/14 §13.2, §13.4): one row per hostname
 * with the record to set (type and value, both copyable), the address it
 * replaces and the live check as an icon and a word. Never changes DNS.
 */
export function DnsSwitchList({
    records,
    preview = false,
    className,
}: {
    records: DnsSwitchRecord[];
    /** Before the switch: the records without their checks. */
    preview?: boolean;
    className?: string;
}) {
    return (
        <ul
            className={cn(
                'flex flex-col divide-y rounded-xl border bg-card',
                className,
            )}
        >
            {records.map((record) => {
                const [state, label] = dnsRecordState(
                    record.check,
                    record.previous,
                );
                const check = record.check;
                const previous = (record.previous ?? []).filter(
                    (address) =>
                        !record.values.some((entry) => entry.value === address),
                );

                return (
                    <li
                        key={`${record.kind}:${record.host}`}
                        className="flex flex-col gap-2.5 px-4 py-4 sm:px-5"
                    >
                        <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                            <span className="flex min-w-0 flex-col gap-0.5">
                                <span className="type-small text-muted-foreground">
                                    {record.kind}
                                    {record.usedBy
                                        ? ` for ${record.usedBy}`
                                        : ''}
                                </span>
                                <span className="flex min-w-0 items-center gap-1">
                                    <code className="type-mono break-all">
                                        {record.host}
                                    </code>
                                    <CopyButton
                                        value={record.host}
                                        label={record.host}
                                        size="xs"
                                    />
                                </span>
                            </span>
                            {preview ? null : check === undefined ? (
                                <Skeleton className="mt-1 h-4 w-24" />
                            ) : (
                                <Status
                                    state={state}
                                    label={label}
                                    className="pt-0.5"
                                />
                            )}
                        </div>
                        {record.values.length === 0 ? (
                            <p className="type-small text-muted-foreground">
                                The new server hasn’t reported a public address
                                yet.
                            </p>
                        ) : (
                            <dl className="flex flex-col gap-1.5 rounded-lg bg-recessed px-3 py-2">
                                {record.values.map(({ type, value }) => (
                                    <div
                                        key={`${type}:${value}`}
                                        className="flex min-w-0 items-center gap-2"
                                    >
                                        <dt className="inline-flex h-6 w-12 shrink-0 items-center justify-center rounded-md bg-hover type-mono-small font-medium">
                                            {type}
                                        </dt>
                                        <dd className="flex min-w-0 items-center gap-1">
                                            <span className="sr-only">
                                                {`Set ${record.host} to `}
                                            </span>
                                            <code className="type-mono break-all">
                                                {value}
                                            </code>
                                            <CopyButton
                                                value={value}
                                                label={`${type} value for ${record.host}`}
                                                size="xs"
                                            />
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        )}
                        {previous.length > 0 &&
                            (preview || check?.status !== 'ok') && (
                                <p className="type-small text-muted-foreground">
                                    Replaces{' '}
                                    <code className="type-mono-small">
                                        {previous.join(', ')}
                                    </code>
                                </p>
                            )}
                        {!preview && check && check.status !== 'ok' && (
                            <p className="type-small text-muted-foreground">
                                {check.message}
                            </p>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}

/**
 * DNS records a move asks the user to change (docs/14 §13.2): the host,
 * the address to set and the one it replaces, and the live check once the
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

    return <DnsSwitchList records={fromMoveRecords(records, live)} />;
}
