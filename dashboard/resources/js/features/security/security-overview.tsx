import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from '@inertiajs/react';
import { Info, RefreshCw, ShieldCheck } from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import { TimeRangeSelect } from '@/components/charts/time-range-select';
import type { TimeRangeOption } from '@/components/charts/time-range-select';
import { Callout } from '@/components/patterns/callout';
import { EmptyState } from '@/components/patterns/empty-state';
import { PageHeader } from '@/components/patterns/page';
import { Section } from '@/components/patterns/section';
import { TopBarActions } from '@/components/patterns/top-bar';
import { relativeTime } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { requestJson } from '@/features/projects/http';
import { cn } from '@/lib/utils';
import { investigate as investigateRoute } from '@/routes/security/findings';
import { check as checkRoute, data as dataRoute } from '@/routes/security';
import { FindingSheet } from './finding-detail';
import { ActionNeededList } from './action-needed';
import { FirewallSection } from './firewall-section';
import { card, plural } from './format';
import { HowItWorks } from './how-it-works';
import { InventorySection } from './inventory-section';
import { SecurityHero } from './security-hero';
import { groupActionNeeded } from './shape';
import { WorthKnowing } from './worth-knowing';

type PageData = App.Data.Security.SecurityPageData;
type Finding = App.Data.Security.FindingCardData;
type Range = '24h' | '7d' | '30d';

const RANGES: TimeRangeOption<Range>[] = [
    { value: '24h', label: 'Day' },
    { value: '7d', label: 'Week' },
    { value: '30d', label: 'Month' },
];

/**
 * The Security page (docs/22 §9.2): what runs, what affects it, what the
 * web firewall stops. The protections show through their numbers; the one
 * explanation is "How it works". Refetched every minute.
 */
export function SecurityOverview({
    inventoryQuery,
    service,
}: {
    inventoryQuery: string | null;
    service: string | null;
}) {
    const [range, setRange] = useState<Range>('7d');
    const [howOpen, setHowOpen] = useState(false);
    const [finding, setFinding] = useState<string | null>(null);
    const client = useQueryClient();

    const { data, isError } = useQuery({
        queryKey: ['security', range],
        queryFn: ({ signal }) =>
            requestJson<PageData>(
                'get',
                dataRoute({ query: { range } }).url,
                undefined,
                signal,
            ),
        refetchInterval: 60_000,
        placeholderData: (previous) => previous,
        retry: false,
    });

    const refresh = () =>
        void client.invalidateQueries({ queryKey: ['security'] });

    const openHref = (href: string) => {
        const id = href.split('/').pop() ?? null;
        setFinding(id);
    };

    const checkNow = async () => {
        try {
            await requestJson('post', checkRoute().url);
            toast.success('Checking every package now');
            window.setTimeout(refresh, 4000);
        } catch (error) {
            toast.error((error as Error).message);
        }
    };

    const investigate = async (target: Finding) => {
        try {
            await requestJson('post', investigateRoute(target.id).url);
            toast.success(`Checking ${target.service.name}`);
            refresh();
        } catch (error) {
            toast.error((error as Error).message);
        }
    };

    const groups = useMemo(
        () =>
            groupActionNeeded(
                (data?.actionNeeded ?? []).filter(
                    (f) => service === null || f.service.id === service,
                ),
            ),
        [data, service],
    );
    const serviceName =
        service === null
            ? null
            : (data?.actionNeeded.find((f) => f.service.id === service)?.service
                  .name ?? null);

    return (
        <>
            <TopBarActions>
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => setHowOpen(true)}
                >
                    <Info aria-hidden="true" />
                    <span className="hidden sm:inline">How it works</span>
                </Button>
                {data?.canCheckNow && (
                    <CheckNowButton
                        data={data}
                        onCheck={() => void checkNow()}
                    />
                )}
            </TopBarActions>

            <PageHeader
                title="Security"
                actions={
                    <TimeRangeSelect<Range>
                        value={range}
                        onChange={setRange}
                        options={RANGES}
                    />
                }
            />

            {isError && !data ? (
                <EmptyState variant="framed">
                    The Security page could not be loaded. It refreshes every
                    minute.
                </EmptyState>
            ) : !data ? (
                <Skeleton />
            ) : !data.hasServices ? (
                <EmptyState icon={ShieldCheck} variant="framed">
                    Deploy a service to see what it runs and how it’s protected.
                </EmptyState>
            ) : (
                <div className="flex flex-col gap-10">
                    {data.checksOff && (
                        <Callout
                            title="Vulnerability checks are off on this launchroom"
                            actions={
                                data.canManageSources ? (
                                    <a
                                        href="/settings/instance-security"
                                        className="text-signal hover:underline"
                                    >
                                        Settings
                                    </a>
                                ) : undefined
                            }
                        >
                            Packages are still listed and the web firewall still
                            runs.
                        </Callout>
                    )}

                    <SecurityHero data={data} groups={groups} range={range} />

                    {(groups.length > 0 || service !== null) && (
                        <Section
                            id="action-needed"
                            title="Action needed"
                            description={
                                service !== null
                                    ? `Only ${serviceName ?? 'one service'}`
                                    : undefined
                            }
                            actions={
                                service !== null ? (
                                    <Button variant="ghost" size="sm" asChild>
                                        <Link href="/security">
                                            Show every service
                                        </Link>
                                    </Button>
                                ) : undefined
                            }
                        >
                            {groups.length > 0 ? (
                                <ActionNeededList
                                    groups={groups}
                                    onOpen={(f) => setFinding(f.id)}
                                    onInvestigate={(f) => void investigate(f)}
                                    canInvestigate={data.canManage}
                                />
                            ) : (
                                <EmptyState icon={ShieldCheck} variant="framed">
                                    Nothing needs action in this service.
                                </EmptyState>
                            )}
                        </Section>
                    )}

                    <FirewallSection
                        firewall={data.firewall}
                        summary={data.summary}
                        canManage={data.canManage}
                        onChanged={refresh}
                    />

                    <WorthKnowing
                        groups={data.worthKnowing}
                        notices={data.notices}
                        onOpen={openHref}
                    />

                    <InventorySection
                        description={
                            data.summary.packages > 0
                                ? `${plural(data.summary.packages, 'package')} in ${plural(data.summary.servicesChecked, 'service')}`
                                : undefined
                        }
                        runtimes={data.runtimes}
                        baseImages={data.baseImages}
                        initialQuery={inventoryQuery}
                        onOpenFinding={openHref}
                    />
                </div>
            )}

            <HowItWorks
                open={howOpen}
                onOpenChange={setHowOpen}
                ruleSet={data?.firewall.ruleSet ?? null}
            />
            <FindingSheet
                id={finding}
                onOpenChange={(open) => !open && setFinding(null)}
            />
        </>
    );
}

function CheckNowButton({
    data,
    onCheck,
}: {
    data: PageData;
    onCheck: () => void;
}) {
    const waiting = data.nextCheckAt !== null;
    const button = (
        <Button
            variant="outline"
            size="sm"
            disabled={waiting || data.checksOff}
            onClick={onCheck}
        >
            <RefreshCw aria-hidden="true" />
            Check now
        </Button>
    );

    if (!waiting || !data.checkedAt) {
        return button;
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span tabIndex={0}>{button}</span>
            </TooltipTrigger>
            <TooltipContent>
                Checked {relativeTime(data.checkedAt)}
            </TooltipContent>
        </Tooltip>
    );
}

function Skeleton() {
    return (
        <div
            aria-hidden="true"
            className="flex animate-skeleton-in flex-col gap-4"
        >
            <div className={cn(card, 'h-[220px]')} />
            <div className={cn(card, 'h-[300px]')} />
        </div>
    );
}
