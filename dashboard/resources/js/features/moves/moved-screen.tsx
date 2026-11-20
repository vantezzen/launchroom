import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { CopyButton } from '@/components/copy-button';
import { Button } from '@/components/ui/button';
import { AuthFrame } from '@/features/auth/auth-frame';

export type MovedProps = {
    /** The new dashboard's address. */
    movedTo: string;
    /** Settings → Move launchroom here: the DNS records, for instance owners. */
    summaryHref?: string | null;
};

/**
 * Every page of the old dashboard after the hand-over (docs/14 §13.4):
 * where launchroom went, and, for instance owners, the way to the DNS
 * records to change (Settings → Move launchroom stays readable here).
 */
export function MovedScreen({ movedTo, summaryHref }: MovedProps) {
    return (
        <AuthFrame
            title="launchroom moved"
            description="This dashboard handed over to a new server. Changes happen there now."
            footer="If the address doesn’t open yet, its DNS record still points at this machine."
        >
            <div className="flex flex-col gap-4 rounded-xl border bg-card p-5 shadow-card">
                <span className="flex min-w-0 items-center justify-center gap-1">
                    <code className="type-mono break-all">{movedTo}</code>
                    <CopyButton
                        value={movedTo}
                        label="new dashboard address"
                        size="xs"
                    />
                </span>
                <Button asChild className="w-full">
                    <a href={movedTo}>
                        Open the new dashboard
                        <ArrowUpRight aria-hidden="true" />
                    </a>
                </Button>
            </div>
            {summaryHref && (
                <p className="text-center type-small text-muted-foreground">
                    Instance owners see which DNS records to change, with a live
                    check for each.{' '}
                    <Link
                        href={summaryHref}
                        className="font-medium text-foreground underline underline-offset-4"
                    >
                        Show the DNS records
                    </Link>
                </p>
            )}
        </AuthFrame>
    );
}
