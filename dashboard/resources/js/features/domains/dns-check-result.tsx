import { Link } from '@inertiajs/react';
import { Info } from 'lucide-react';
import type { StatusState } from '@/components/status';
import { Status } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
import { LearnMore, useDocsUrl } from '@/features/settings/settings-card';
import { edit as networkSettings } from '@/routes/network-settings';

type Check = App.Data.Domains.DnsCheckData;

const states: Record<string, StatusState> = {
    ok: 'active',
    proxied: 'warning',
    mismatch: 'failed',
    missing: 'warning',
    unknown: 'warning',
    error: 'warning',
};

const labels: Record<string, string> = {
    ok: 'DNS verified',
    proxied: 'Behind a proxy, not confirmed',
    mismatch: 'DNS points elsewhere',
    missing: 'No DNS record yet',
    unknown: 'Can’t compare yet',
    error: 'DNS check failed',
};

export function dnsCheckState(check: Check): StatusState {
    return states[check.status] ?? 'idle';
}

/** The status word: "Reached through a proxy (Cloudflare)" when it is one. */
export function dnsCheckLabel(check: Check): string {
    if (check.status === 'ok' && check.proxy) {
        return `Reached through a proxy (${check.proxy})`;
    }

    if (check.status === 'ok' && check.reachedOverHttp) {
        return 'Reaches your server';
    }

    return labels[check.status] ?? check.status;
}

/**
 * What a proxy in front of the domain means for TLS (docs/05 §5). For
 * Cloudflare: Full (strict), certificates over HTTP-01 through the proxy or
 * DNS-01 with an API token, and the one-level limit of its free edge
 * certificate (generated app addresses are deeper than that).
 */
export function ProxyNote({
    proxy,
    wildcard = false,
}: {
    proxy: string;
    wildcard?: boolean;
}) {
    const docsUrl = useDocsUrl();
    const cloudflare = proxy === 'Cloudflare';

    return (
        <div className="flex gap-3 rounded-lg border bg-recessed px-4 py-3">
            <Info
                aria-hidden="true"
                className="mt-0.5 size-4 shrink-0 text-muted-foreground"
            />
            <div className="flex min-w-0 flex-col gap-2 type-small text-muted-foreground">
                <p className="font-medium text-foreground">
                    Visitors connect to {proxy}, which connects to your server.
                </p>
                <ul className="flex list-disc flex-col gap-1 pl-4">
                    {cloudflare && (
                        <li>
                            Set SSL/TLS to <strong>Full (strict)</strong> in
                            Cloudflare. Flexible loops between HTTP and HTTPS.
                        </li>
                    )}
                    <li>
                        Your server gets its certificates over HTTP through the
                        proxy.
                        {cloudflare && (
                            <>
                                {' '}
                                For wildcard certificates, add a Cloudflare API
                                token in{' '}
                                <Link
                                    href={networkSettings()}
                                    className="text-signal underline-offset-4 hover:underline"
                                >
                                    Settings → Network
                                </Link>
                                .
                            </>
                        )}
                    </li>
                    {cloudflare && wildcard && (
                        <li>
                            Cloudflare’s free edge certificate covers one
                            subdomain level. App addresses are deeper
                            (web-production.shop.apps.example.com), so set this
                            record to <strong>DNS only</strong>, or use a
                            Cloudflare certificate that covers them.
                        </li>
                    )}
                </ul>
                <LearnMore
                    href={docsUrl('troubleshooting/dns-and-tls#behind-a-proxy')}
                >
                    DNS behind a proxy
                </LearnMore>
            </div>
        </div>
    );
}

/**
 * A live DNS check (docs/05 §5): status with icon and label, what was
 * found, and when it was checked.
 */
export function DnsCheckResult({
    result,
    compact = false,
    wildcard = false,
}: {
    result: Check;
    compact?: boolean;
    wildcard?: boolean;
}) {
    return (
        <div className="flex flex-col gap-2" role="status">
            <div className="flex flex-col gap-1">
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <Status
                        state={dnsCheckState(result)}
                        label={dnsCheckLabel(result)}
                    />
                    <span className="type-small text-muted-foreground">
                        Checked <TimeAgo value={result.checkedAt} />
                    </span>
                </div>
                {!compact || result.status !== 'ok' || result.proxy ? (
                    <p className="max-w-[65ch] type-small text-muted-foreground">
                        {result.message}
                        {result.cname && (
                            <>
                                {' '}
                                Via CNAME{' '}
                                <span className="font-mono">
                                    {result.cname}
                                </span>
                                .
                            </>
                        )}
                    </p>
                ) : null}
            </div>
            {result.proxy && (
                <ProxyNote proxy={result.proxy} wildcard={wildcard} />
            )}
        </div>
    );
}
