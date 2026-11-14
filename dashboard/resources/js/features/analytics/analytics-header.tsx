import { Link } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { TimeRangeSelect } from '@/components/charts/time-range-select';
import type { TimeRangeOption } from '@/components/charts/time-range-select';
import { PageHeader } from '@/components/patterns/page';
import { PillTabs } from '@/components/patterns/pill-tabs';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useLocationSearch } from '@/hooks/use-observe-scope';
import type { ObserveScopeState } from '@/hooks/use-observe-scope';
import { analyticsHref } from '@/lib/links';
import { rangeWords, readScope } from '@/lib/observe-scope';
import type { ObserveRange } from '@/lib/observe-scope';
import { index as teamAnalytics } from '@/routes/analytics';

type Service = App.Data.Metrics.MetricsServiceData;
type View = App.Enums.AnalyticsView;

const ALL = 'all';

/**
 * The service filter of an Analytics view: the URL's `?service=` (public
 * id or slug, carried over from Logs or the service panel) wins over the
 * page's `initialService`; anything not in this environment means every
 * service. Changes go back to the URL (observe scope, docs/08 §1).
 */
export function useAnalyticsService<T extends ObserveRange>(
    scope: ObserveScopeState<T>,
    services: Service[],
    initialService: string | null,
): [Service | null, (id: string | null) => void] {
    const [selected, setSelected] = useState<string | null>(() => {
        const wanted = scope.service ?? initialService;

        return (
            services.find((s) => s.id === wanted || s.slug === wanted)?.id ??
            null
        );
    });

    return [
        services.find((s) => s.id === selected) ?? null,
        (id) => {
            setSelected(id);
            scope.setService(id);
        },
    ];
}

/**
 * The header both views of the project's Analytics tab share (D75):
 * breadcrumbs (all projects / project / service), the title with what is
 * shown, the Traffic and Resources views as tabs that keep the range and
 * the service, and the service and range selects.
 */
export function AnalyticsHeader<T extends ObserveRange>({
    project,
    projectName,
    environment,
    environmentName,
    view,
    services,
    service,
    onService,
    range,
    ranges,
    onRange,
    snapped,
}: {
    project: string;
    projectName: string;
    environment: string;
    environmentName: string;
    view: View;
    services: Service[];
    service: Service | null;
    onService: (id: string | null) => void;
    range: T;
    ranges: TimeRangeOption<T>[];
    onRange: (range: T) => void;
    snapped: string | null;
}) {
    // The address bar is the scope: tabs carry what it says right now.
    const scope = readScope(useLocationSearch());
    const carried = {
        project,
        environment,
        service: scope.service,
        range: scope.range,
    };

    return (
        <>
            <AnalyticsBreadcrumbs
                projectName={projectName}
                serviceName={service?.name ?? null}
                onProject={() => onService(null)}
            />
            <PageHeader
                title="Analytics"
                description={
                    <>
                        {`${service?.name ?? 'All services'} in ${environmentName}, ${rangeWords(range)}. `}
                        {snapped}
                    </>
                }
                actions={
                    <>
                        {services.length > 1 && (
                            <Select
                                value={service?.id ?? ALL}
                                onValueChange={(next) =>
                                    onService(next === ALL ? null : next)
                                }
                            >
                                <SelectTrigger
                                    size="sm"
                                    className="h-9 w-auto min-w-40 gap-2"
                                    aria-label="Service"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>
                                        All services
                                    </SelectItem>
                                    {services.map((s) => (
                                        <SelectItem key={s.id} value={s.id}>
                                            {s.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}
                        <TimeRangeSelect
                            value={range}
                            onChange={onRange}
                            options={ranges}
                        />
                    </>
                }
            >
                <PillTabs
                    label="Analytics views"
                    className="mt-3 -ml-3"
                    preserve
                    tabs={[
                        {
                            label: 'Traffic',
                            href: analyticsHref({
                                ...carried,
                                view: 'traffic',
                            }),
                            active: view === 'traffic',
                        },
                        {
                            label: 'Resources',
                            href: analyticsHref({
                                ...carried,
                                view: 'resources',
                            }),
                            active: view === 'resources',
                        },
                    ]}
                />
            </PageHeader>
        </>
    );
}

/**
 * Where this view sits in the drill-down: Analytics (every project) /
 * project / service. The project crumb clears the service filter.
 */
function AnalyticsBreadcrumbs({
    projectName,
    serviceName,
    onProject,
}: {
    projectName: string;
    serviceName: string | null;
    onProject: () => void;
}) {
    const crumb =
        'rounded-sm text-muted-foreground transition-colors duration-fast hover:text-foreground focus-visible:outline-2 focus-visible:outline-(--signal)';
    let last: ReactNode = (
        <span aria-current="page" className="text-foreground">
            {projectName}
        </span>
    );

    if (serviceName) {
        last = (
            <button type="button" onClick={onProject} className={crumb}>
                {projectName}
            </button>
        );
    }

    return (
        <nav aria-label="Analytics" className="-mb-2">
            <ol className="flex min-w-0 items-center gap-1.5 type-small">
                <li>
                    <Link href={teamAnalytics()} className={crumb}>
                        All projects
                    </Link>
                </li>
                <li aria-hidden="true" className="text-faint">
                    /
                </li>
                <li className="min-w-0 truncate">{last}</li>
                {serviceName && (
                    <>
                        <li aria-hidden="true" className="text-faint">
                            /
                        </li>
                        <li className="min-w-0 truncate">
                            <span
                                aria-current="page"
                                className="text-foreground"
                            >
                                {serviceName}
                            </span>
                        </li>
                    </>
                )}
            </ol>
        </nav>
    );
}
