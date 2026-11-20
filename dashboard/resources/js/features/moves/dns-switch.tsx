import { ArrowUpRight } from 'lucide-react';
import { CopyButton } from '@/components/copy-button';
import { CopyValue } from '@/components/copy-value';
import { Status } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { DnsSwitchList } from '@/features/moves/dns-records';
import { fromDnsSwitch } from '@/features/moves/shape';
import type { DnsSwitchData } from '@/features/moves/types';

function hostOf(url: string): string {
    try {
        return new URL(url).host;
    } catch {
        return url;
    }
}

/** The newest check time of the records, if any ran. */
export function lastChecked(data: DnsSwitchData): string | null {
    return (
        data.records
            .map((record) => record.check?.checkedAt ?? null)
            .filter((value): value is string => value !== null)
            .sort()
            .at(-1) ?? null
    );
}

/**
 * The new dashboard's address on the old one after the hand-over: open it,
 * whether its record already points there, and the tunnel that reaches it
 * before DNS switched.
 */
export function NewDashboardCard({
    newUrl,
    data,
}: {
    newUrl: string | null;
    /** Undefined while the deferred records load. */
    data: DnsSwitchData | null | undefined;
}) {
    const url = newUrl ?? data?.newUrl ?? null;
    const dashboard = data?.records.find(
        (record) => record.kind === 'dashboard',
    );
    const switched =
        dashboard === undefined || dashboard.check?.status === 'ok';
    const values = (data?.addresses ?? []).map((entry) => entry.value);
    const tunnel = data?.reachBeforeDns ?? null;

    if (url === null) {
        return null;
    }

    const host = hostOf(url);

    return (
        <section
            aria-labelledby="new-dashboard"
            className="flex flex-col gap-4 rounded-xl border bg-card p-5 shadow-card"
        >
            <div className="flex flex-col gap-1">
                <h2 id="new-dashboard" className="type-heading">
                    The new dashboard
                </h2>
                <span className="flex min-w-0 items-center gap-1">
                    <code className="type-mono break-all">{url}</code>
                    <CopyButton
                        value={url}
                        label="new dashboard address"
                        size="xs"
                    />
                </span>
            </div>
            {dashboard?.check && (
                <Status
                    state={switched ? 'active' : 'warning'}
                    label={
                        switched
                            ? `${host} points at the new server`
                            : `${host} still points at this machine`
                    }
                />
            )}
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-4">
                <Button asChild className="w-fit">
                    <a href={url}>
                        Open the new dashboard
                        <ArrowUpRight aria-hidden="true" />
                    </a>
                </Button>
                {!switched && (
                    <p className="type-small text-muted-foreground">
                        Opens once {host} points at{' '}
                        {values.join(' and ') || 'the new server'}.
                    </p>
                )}
            </div>
            {!switched && tunnel !== null && (
                <div className="flex flex-col gap-2 border-t pt-4">
                    <h3 className="type-label">Before DNS switched</h3>
                    <p className="max-w-[65ch] type-small text-muted-foreground">
                        Open a tunnel to the new server from your computer, then
                        open{' '}
                        <a
                            href={tunnel.url}
                            className="type-mono-small text-foreground underline underline-offset-4"
                        >
                            {tunnel.url}
                        </a>{' '}
                        and sign in with the same account as here.
                    </p>
                    <CopyValue value={tunnel.command} label="tunnel command" />
                </div>
            )}
        </section>
    );
}

/**
 * Which records to change, to what, and whether each one switched yet
 * (docs/14 §13.4). Used on the old dashboard after the hand-over and in
 * the new dashboard's checklist.
 */
export function DnsSwitchGuide({
    data,
    onCheck,
    checking = false,
    intro,
}: {
    /** Undefined while the deferred records load. */
    data: DnsSwitchData | null | undefined;
    onCheck: () => void;
    checking?: boolean;
    /** One sentence above the records. */
    intro?: string;
}) {
    if (data === undefined) {
        return (
            <div className="flex flex-col gap-3" aria-busy="true">
                <Skeleton className="h-5 w-40" />
                <Skeleton className="h-28 w-full rounded-xl" />
            </div>
        );
    }

    if (data === null || data.records.length === 0) {
        return (
            <p className="type-body text-muted-foreground">
                No DNS record to change: the dashboard and your apps use
                addresses that follow the server by themselves.
            </p>
        );
    }

    const checkedAt = lastChecked(data);
    const values = data.addresses.map((entry) => entry.value);

    return (
        <section aria-labelledby="dns-switch" className="flex flex-col gap-3">
            <div className="flex flex-wrap items-end justify-between gap-x-4 gap-y-2">
                <div className="flex min-w-0 flex-col gap-1">
                    <h2 id="dns-switch" className="type-heading">
                        Switch DNS
                        {data.checked && (
                            <span className="ml-2 font-normal text-muted-foreground tabular-nums">
                                {data.pointed} of {data.total} switched
                            </span>
                        )}
                    </h2>
                    <p className="max-w-[65ch] type-small text-muted-foreground">
                        {intro ??
                            `At your DNS provider, set each record to ${values.join(' and ') || 'the new server’s address'}${data.oldAddresses.length > 0 ? ` in place of ${data.oldAddresses.join(', ')}` : ''}.`}
                    </p>
                </div>
                <div className="flex items-center gap-3">
                    {checkedAt !== null && (
                        <span className="type-small text-muted-foreground">
                            Checked <TimeAgo value={checkedAt} />
                        </span>
                    )}
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={onCheck}
                        disabled={checking}
                    >
                        {data.checked ? 'Check again' : 'Check DNS'}
                    </Button>
                </div>
            </div>
            <DnsSwitchList records={fromDnsSwitch(data)} />
        </section>
    );
}
