import { Link } from '@inertiajs/react';
import { Callout } from '@/components/patterns/callout';
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
 * What a proxy in front of the domain means for TLS (docs/05 §5): for
 * Cloudflare, Full (strict), and certificates over HTTP-01 through the proxy
 * or DNS-01 with an API token.
 */
export function ProxyNote({ proxy }: { proxy: string }) {
    const docsUrl = useDocsUrl();
    const cloudflare = proxy === 'Cloudflare';

    return (
        <Callout
            title={`Visitors connect to ${proxy}, which connects to your server`}
            actions={
                <LearnMore
                    href={docsUrl('troubleshooting/dns-and-tls#behind-a-proxy')}
                >
                    DNS behind a proxy
                </LearnMore>
            }
        >
            <ul className="flex list-disc flex-col gap-1 pl-4">
                {cloudflare && (
                    <li>
                        Set SSL/TLS to{' '}
                        <strong className="text-foreground">
                            Full (strict)
                        </strong>{' '}
                        in Cloudflare. With Flexible, requests loop between HTTP
                        and HTTPS.
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
            </ul>
        </Callout>
    );
}

/**
 * The platform wildcard behind Cloudflare's proxy (docs/05 §5): its free
 * edge certificate covers the zone and one level below it, and generated app
 * addresses are deeper, so visitors get a certificate error. Amber, not red:
 * nothing is broken until an app is opened, and the fix is one click.
 */
export function CloudflareWildcardCallout({ domain }: { domain: string }) {
    const docsUrl = useDocsUrl();
    const example = `web-production.shop.${domain}`;

    return (
        <Callout
            tone="warning"
            title="Turn off Cloudflare’s proxy for this record"
            actions={
                <>
                    <LearnMore
                        href={docsUrl(
                            'troubleshooting/dns-and-tls#behind-a-proxy',
                        )}
                    />
                    <LearnMore href="https://developers.cloudflare.com/ssl/edge-certificates/universal-ssl/limitations/">
                        Cloudflare certificate limits
                    </LearnMore>
                </>
            }
        >
            <p>
                Cloudflare’s free certificate covers your domain and one level
                below it. App addresses such as{' '}
                <code className="type-mono-small text-foreground">
                    {example}
                </code>{' '}
                are deeper, so visitors get a certificate error while the proxy
                is on.
            </p>
            <ol className="flex list-decimal flex-col gap-1 pl-4">
                <li>
                    Recommended: in Cloudflare’s DNS settings, set{' '}
                    <code className="type-mono-small text-foreground">
                        *.{domain}
                    </code>{' '}
                    to <strong className="text-foreground">DNS only</strong>{' '}
                    (grey cloud). Your server then gets a certificate for every
                    app address.
                </li>
                <li>
                    Or keep the proxy: add an advanced certificate for each
                    project (
                    <code className="type-mono-small text-foreground">
                        *.shop.{domain}
                    </code>
                    ) and set SSL/TLS to{' '}
                    <strong className="text-foreground">Full (strict)</strong>.
                </li>
            </ol>
        </Callout>
    );
}

/**
 * A live DNS check (docs/05 §5): status with icon and label, what was
 * found, and when it was checked.
 */
export function DnsCheckResult({
    result,
    compact = false,
    wildcard,
}: {
    result: Check;
    compact?: boolean;
    /** The platform domain when this checks its wildcard record. */
    wildcard?: string;
}) {
    // Reaching the server over HTTP isn't enough here: app addresses would
    // fail TLS at Cloudflare's edge, so the check reads as a warning.
    const cloudflareWildcard =
        result.proxy === 'Cloudflare' && wildcard !== undefined;

    return (
        <div className="flex flex-col gap-3" role="status">
            <div className="flex flex-col gap-1">
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <Status
                        state={
                            cloudflareWildcard
                                ? 'warning'
                                : dnsCheckState(result)
                        }
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
            {cloudflareWildcard ? (
                <CloudflareWildcardCallout domain={wildcard} />
            ) : (
                result.proxy && <ProxyNote proxy={result.proxy} />
            )}
        </div>
    );
}
