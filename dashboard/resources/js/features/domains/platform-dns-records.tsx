import { Info } from 'lucide-react';
import { CopyValue } from '@/components/copy-value';

/** The platform wildcard and Cloudflare's visitor-certificate limitation. */
export function PlatformDnsRecords({
    domain,
    addresses,
}: {
    domain: string;
    addresses: string[];
}) {
    return (
        <div className="flex min-w-0 flex-col gap-3">
            <p className="type-label">
                Create these records at your DNS provider
            </p>
            {addresses.length === 0 ? (
                <p className="type-small text-muted-foreground">
                    Records appear once an edge server reports its public
                    address.
                </p>
            ) : (
                <ul className="divide-y rounded-lg border bg-recessed">
                    {addresses.map((address) => (
                        <li
                            key={address}
                            className="grid min-w-0 gap-2 p-4 sm:grid-cols-[3rem_minmax(0,1fr)]"
                        >
                            <span className="type-mono-small text-muted-foreground">
                                {address.includes(':') ? 'AAAA' : 'A'}
                            </span>
                            <div className="flex min-w-0 flex-col gap-1">
                                <CopyValue
                                    value={`*.${domain}`}
                                    label="record name"
                                    className="min-w-0 border-0 bg-transparent p-0"
                                />
                                <CopyValue
                                    value={address}
                                    label="record value"
                                    className="min-w-0 border-0 bg-transparent p-0"
                                />
                            </div>
                        </li>
                    ))}
                </ul>
            )}
            <div className="flex items-start gap-3 rounded-lg border bg-recessed p-4 type-small">
                <Info
                    className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <p className="min-w-0 [overflow-wrap:anywhere] text-muted-foreground">
                    Using Cloudflare? Set these records to{' '}
                    <strong className="text-foreground">DNS only</strong> (turn
                    off the orange cloud). Universal SSL covers the zone’s root
                    domain and one subdomain level. Deeper app addresses under{' '}
                    <code>{domain}</code> can fail with{' '}
                    <code>ERR_SSL_VERSION_OR_CIPHER_MISMATCH</code> unless your
                    Cloudflare certificate explicitly covers them.{' '}
                    <a
                        href="https://developers.cloudflare.com/ssl/edge-certificates/universal-ssl/limitations/"
                        target="_blank"
                        rel="noreferrer"
                        className="underline underline-offset-4"
                    >
                        Cloudflare certificate coverage
                    </a>
                </p>
            </div>
        </div>
    );
}
