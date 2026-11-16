import { Status } from '@/components/status';
import { CopyButton } from '@/components/copy-button';
import { cn } from '@/lib/utils';

export type DnsRecordRow = {
    type: string;
    name: string;
    value: string;
    /** What the last check saw: found, not yet, or unknown (no check for this name). */
    found?: boolean | null;
};

/**
 * Whether a check of `check.hostname` saw this record: an A/AAAA record's
 * address among the answers, a CNAME's target as the CNAME. Null for names
 * the check didn't look at (the `www.` twin).
 */
export function recordFound(
    record: { type: string; name: string; value: string },
    check: App.Data.Domains.DnsCheckData | null | undefined,
): boolean | null {
    if (!check || check.hostname !== record.name) {
        return null;
    }

    if (record.type === 'CNAME') {
        return (
            check.cname?.replace(/\.$/, '') === record.value.replace(/\.$/, '')
        );
    }

    return check.addresses.includes(record.value);
}

/**
 * DNS records the way a registrar's form asks for them (Vercel, Resend):
 * Type, Name and Value columns with a copy button in each cell, and a
 * status per record once a check knows it. Narrow containers stack each
 * record as a small card.
 */
export function DnsRecordTable({
    records,
    className,
}: {
    records: DnsRecordRow[];
    className?: string;
}) {
    const statuses = records.some(
        (record) => record.found !== undefined && record.found !== null,
    );

    return (
        <div
            className={cn(
                '@container overflow-hidden rounded-lg border bg-card',
                className,
            )}
        >
            <table className="w-full table-fixed border-collapse text-left">
                <thead className="hidden border-b bg-recessed @lg:table-header-group">
                    <tr className="type-small text-muted-foreground">
                        <th scope="col" className="w-20 px-4 py-2 font-normal">
                            Type
                        </th>
                        <th scope="col" className="px-2 py-2 font-normal">
                            Name
                        </th>
                        <th scope="col" className="px-2 py-2 font-normal">
                            Value
                        </th>
                        {statuses && (
                            <th
                                scope="col"
                                className="w-36 px-4 py-2 font-normal"
                            >
                                Status
                            </th>
                        )}
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {records.map((record) => (
                        <tr
                            key={`${record.type}-${record.name}-${record.value}`}
                            className="grid grid-cols-[4rem_minmax(0,1fr)] gap-x-3 gap-y-2 p-4 @lg:table-row @lg:p-0"
                        >
                            <td className="align-top @lg:px-4 @lg:py-3">
                                <span className="inline-flex h-6 w-fit items-center rounded-md bg-hover px-2 type-mono-small font-medium">
                                    {record.type}
                                </span>
                            </td>
                            <Cell
                                label="Name"
                                value={record.name}
                                copyLabel={`${record.type} record name`}
                            />
                            <Cell
                                label="Value"
                                value={record.value}
                                copyLabel={`${record.type} record value`}
                                className="col-start-2"
                            />
                            {statuses && (
                                <td className="col-start-2 align-top @lg:px-4 @lg:py-3.5">
                                    {record.found === true ? (
                                        <Status
                                            state="active"
                                            label="Found"
                                            className="type-small"
                                        />
                                    ) : record.found === false ? (
                                        <Status
                                            state="progress"
                                            label="Not found yet"
                                            className="type-small"
                                        />
                                    ) : (
                                        <span className="sr-only">
                                            Not checked
                                        </span>
                                    )}
                                </td>
                            )}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function Cell({
    label,
    value,
    copyLabel,
    className,
}: {
    label: string;
    value: string;
    copyLabel: string;
    className?: string;
}) {
    return (
        <td className={cn('min-w-0 align-top @lg:px-2 @lg:py-2.5', className)}>
            <span className="flex min-w-0 items-start gap-1">
                <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <span className="type-small text-muted-foreground @lg:sr-only">
                        {label}
                    </span>
                    <code className="pt-1 type-mono-small break-all">
                        {value}
                    </code>
                </span>
                <CopyButton value={value} label={copyLabel} size="xs" />
            </span>
        </td>
    );
}
