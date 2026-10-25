import { Status } from '@/components/status';
import { CopyButton } from '@/features/deployment-detail/overview-card';
import { cn } from '@/lib/utils';

/**
 * The wildcard record(s) for the platform domain (docs/05 §5), one per
 * public address, name and value copyable. Shared by Get started and
 * Settings → Network; looks like a service domain's records.
 */
export function PlatformDnsRecords({
    domain,
    addresses,
}: {
    domain: string;
    addresses: string[];
}) {
    if (addresses.length === 0) {
        return (
            <div className="flex flex-col gap-1 rounded-xl border bg-recessed px-4 py-3">
                <Status
                    state="progress"
                    label="Looking up this server’s public address"
                />
                <p className="type-small text-muted-foreground">
                    The record to create appears here once your server reports
                    its public IP, usually within a minute.
                </p>
            </div>
        );
    }

    const name = `*.${domain}`;

    return (
        <div className="flex min-w-0 flex-col gap-2">
            <span className="type-label">
                {addresses.length === 1
                    ? 'Create this record at your DNS provider'
                    : `Create these ${addresses.length} records, one per address`}
            </span>
            <ul className="divide-y overflow-hidden rounded-xl border bg-recessed">
                {addresses.map((address) => {
                    const type = address.includes(':') ? 'AAAA' : 'A';

                    return (
                        <li
                            key={address}
                            className="grid min-w-0 grid-cols-[3.5rem_minmax(0,1fr)] items-start gap-x-3 gap-y-2 px-4 py-3 sm:grid-cols-[3.5rem_minmax(0,1fr)_minmax(0,1fr)]"
                        >
                            <span className="inline-flex h-6 w-fit items-center rounded-md bg-hover px-2 type-mono-small font-medium">
                                {type}
                            </span>
                            <RecordField
                                label="Name"
                                value={name}
                                copyLabel={`Copy the ${type} record name`}
                            />
                            <RecordField
                                label="Value"
                                value={address}
                                copyLabel={`Copy the ${type} record value`}
                                className="col-start-2 sm:col-start-3"
                            />
                        </li>
                    );
                })}
            </ul>
            <p className="type-small text-muted-foreground">
                Using Cloudflare? Set the record to{' '}
                <strong className="font-medium text-foreground">
                    DNS only
                </strong>{' '}
                so every app address gets a certificate.
            </p>
        </div>
    );
}

function RecordField({
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
        <span className={cn('flex min-w-0 flex-col gap-0.5', className)}>
            <span className="type-small text-muted-foreground">{label}</span>
            <span className="flex min-w-0 items-start gap-1">
                <code className="min-w-0 type-mono-small break-all">
                    {value}
                </code>
                <CopyButton
                    value={value}
                    label={copyLabel}
                    className="-my-0.5"
                />
            </span>
        </span>
    );
}
