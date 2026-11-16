import { Status } from '@/components/status';
import { DnsRecordTable } from './dns-record-table';

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
            <DnsRecordTable
                records={addresses.map((address) => ({
                    type: address.includes(':') ? 'AAAA' : 'A',
                    name,
                    value: address,
                }))}
            />
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
