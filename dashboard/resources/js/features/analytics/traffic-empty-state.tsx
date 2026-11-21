import { Link } from '@inertiajs/react';
import { ArrowUpRight, BarChart3, ChevronRight } from 'lucide-react';
import { Logo } from '@/components/logo';
import { EmptyState } from '@/components/patterns/empty-state';
import { Status } from '@/components/status';
import { Button } from '@/components/ui/button';
import { RemedyButton } from '@/features/deployments/deployments-tab';
import { statusDetail } from '@/features/service-status/live-status';
import { useNow } from '@/hooks/use-now';
import { plural } from '@/lib/format';
import services from '@/routes/projects/services';
import { trafficEmptyCopy } from './traffic-empty';

type Readiness = App.Data.Analytics.TrafficReadinessData;
type Blocker = App.Data.Analytics.TrafficBlockerData;

/**
 * The Traffic view without requests (docs/15 §5.4, D109): why, in one
 * title and one line, the services that should serve with their status
 * (D108), and the one fix for people who may deploy. Team Analytics links
 * to the services instead (`canFix` false, `showProject`).
 */
export function TrafficEmptyState({
    readiness,
    service,
    where,
    range,
    canFix,
    showProject = false,
}: {
    readiness: Readiness;
    service: { id: string; name: string } | null;
    where: string;
    range: string;
    canFix: boolean;
    showProject?: boolean;
}) {
    const copy = trafficEmptyCopy({
        readiness,
        service,
        where,
        range,
        canFix,
    });
    const more = service === null ? readiness.moreNotServing : 0;

    return (
        <EmptyState
            icon={copy.blockers.length > 0 ? undefined : BarChart3}
            variant="framed"
            title={copy.title}
            action={
                copy.address ? (
                    <Button asChild variant="outline" size="sm">
                        <a
                            href={`https://${copy.address}`}
                            target="_blank"
                            rel="noreferrer"
                        >
                            Open {copy.address}
                            <ArrowUpRight aria-hidden="true" />
                        </a>
                    </Button>
                ) : undefined
            }
            body={
                copy.blockers.length > 0 ? (
                    <BlockerList
                        blockers={copy.blockers}
                        more={more}
                        canFix={canFix}
                        showProject={showProject}
                    />
                ) : undefined
            }
        >
            {copy.line}
        </EmptyState>
    );
}

function BlockerList({
    blockers,
    more,
    canFix,
    showProject,
}: {
    blockers: Blocker[];
    more: number;
    canFix: boolean;
    showProject: boolean;
}) {
    const now = useNow(true, 30_000);

    return (
        <div className="flex w-full min-w-0 flex-col gap-2">
            <ul className="divide-y overflow-hidden rounded-xl border bg-card text-left">
                {blockers.map((blocker) => {
                    const panel = services.show({
                        project: blocker.projectId,
                        environment: blocker.environmentId,
                        service: blocker.serviceId,
                        tab: 'deployments',
                    }).url;
                    const remedy = canFix ? blocker.status.remedy : null;

                    return (
                        <li
                            key={blocker.serviceId}
                            className="flex items-center gap-3 px-4 py-3"
                        >
                            <Logo kind={blocker.logo} size={32} decorative />
                            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                                <Link
                                    href={panel}
                                    className="truncate type-body font-medium underline-offset-4 hover:underline"
                                >
                                    {showProject
                                        ? `${blocker.projectName} / ${blocker.name}`
                                        : blocker.name}
                                </Link>
                                <Status
                                    state={blocker.status.state}
                                    label={blocker.status.label}
                                    detail={statusDetail(blocker.status, now)}
                                    className="type-small"
                                />
                            </div>
                            {remedy ? (
                                <RemedyButton
                                    remedy={remedy}
                                    target={{
                                        project: blocker.projectId,
                                        environment: blocker.environmentId,
                                        service: blocker.serviceId,
                                        name: blocker.name,
                                        deployment: blocker.status.deploymentId,
                                    }}
                                />
                            ) : (
                                <Button asChild variant="ghost" size="sm">
                                    <Link href={panel}>
                                        Open
                                        <ChevronRight aria-hidden="true" />
                                    </Link>
                                </Button>
                            )}
                        </li>
                    );
                })}
            </ul>
            {more > 0 && (
                <p className="type-small text-muted-foreground">
                    {plural(more, 'more service', 'more services')} with an
                    address {more === 1 ? 'isn’t' : 'aren’t'} serving.
                </p>
            )}
        </div>
    );
}
