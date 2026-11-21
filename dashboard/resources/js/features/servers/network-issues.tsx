import { Link } from '@inertiajs/react';
import { StatusIcon } from '@/components/status';
import { Button } from '@/components/ui/button';
import { ErrorDetails } from '@/features/servers/error-details';
import { meshProblem, storageProblem } from '@/features/servers/problems';
import { edit as networkSettings } from '@/routes/network-settings';

/**
 * What needs an owner's attention in the network (Servers page): one row
 * for one issue, one callout with a count and a single "Open network" for
 * several, since they all lead to the same settings.
 */
export function NetworkIssues({
    mesh,
    edgeOverview,
    canManageIngress,
}: {
    mesh: App.Data.Servers.MeshOverviewData;
    edgeOverview?: App.Data.Ingress.EdgeOverviewData;
    canManageIngress: boolean;
}) {
    const items: {
        key: string;
        text: string;
        detail: string;
        raw?: string | null;
    }[] = [];

    if (edgeOverview?.singlePointOfFailure) {
        items.push({
            key: 'spof',
            text: 'All public traffic goes through one server.',
            detail: 'Give the edge role to a second public server so one can fail without downtime.',
        });
    }

    if (edgeOverview && edgeOverview.degradedStorage.length > 0) {
        const problem = storageProblem(edgeOverview.degradedStorage);
        items.push({
            key: 'storage',
            text: problem.title,
            detail: problem.detail,
        });
    }

    if (mesh.canManage && mesh.error !== null) {
        items.push({
            key: 'mesh',
            text: meshProblem.title,
            detail: meshProblem.detail,
            raw: mesh.error,
        });
    }

    if (items.length === 0) {
        return null;
    }

    const open = canManageIngress && (
        <Button asChild size="sm" variant="ghost">
            <Link href={networkSettings()}>Open network</Link>
        </Button>
    );
    const body = (item: (typeof items)[number], strong: boolean) => (
        <>
            <span
                className={
                    strong
                        ? 'type-body font-medium'
                        : 'type-label text-foreground'
                }
            >
                {item.text}
            </span>
            <span className="type-small text-muted-foreground">
                {item.detail}
            </span>
            <ErrorDetails text={item.raw} className="mt-1.5" />
            {item.key === 'spof' && edgeOverview && (
                <a
                    href={edgeOverview.guideUrl}
                    target="_blank"
                    rel="noreferrer"
                    className="mt-1 type-small text-signal underline-offset-4 hover:underline"
                >
                    Learn more
                </a>
            )}
        </>
    );

    // One issue: one row. Several: one callout with a count, so the page
    // doesn't stack warnings that all lead to the same place.
    if (items.length === 1) {
        return (
            <div className="flex flex-wrap items-start gap-x-4 gap-y-2 rounded-xl border bg-card px-5 py-4">
                <StatusIcon state="warning" className="mt-1" />
                <div className="flex min-w-0 flex-1 flex-col">
                    {body(items[0], true)}
                </div>
                {open}
            </div>
        );
    }

    return (
        <section
            aria-labelledby="network-issues"
            className="overflow-hidden rounded-xl border bg-card"
        >
            <div className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-4">
                <StatusIcon state="warning" />
                <h2
                    id="network-issues"
                    className="min-w-0 flex-1 type-body font-medium"
                >
                    {items.length} network issues
                </h2>
                {open}
            </div>
            <ul className="flex flex-col divide-y border-t">
                {items.map((item) => (
                    <li
                        key={item.key}
                        className="flex min-w-0 flex-col py-3 pr-5 pl-[52px]"
                    >
                        {body(item, false)}
                    </li>
                ))}
            </ul>
        </section>
    );
}
