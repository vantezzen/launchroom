import { useState } from 'react';
import { RangeSelect } from '@/components/charts/time-range-select';
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
import type { ObserveRange, RangeOf, RangePage } from '@/lib/observe-scope';

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
 * the title with what is shown (no breadcrumb in the content: the top bar
 * carries project and environment, direction §3.3), the Traffic and Resources views as tabs that keep the range and
 * the service, and the service and range selects.
 */
export function AnalyticsHeader<P extends RangePage>({
    project,
    environment,
    environmentName,
    view,
    services,
    service,
    onService,
    range,
    rangePage,
    onRange,
    snapped,
}: {
    project: string;
    environment: string;
    environmentName: string;
    view: View;
    services: Service[];
    service: Service | null;
    onService: (id: string | null) => void;
    range: RangeOf<P>;
    /** Whose ranges the select offers (Traffic: analytics, Resources: resources). */
    rangePage: P;
    onRange: (range: RangeOf<P>) => void;
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
                    <RangeSelect
                        page={rangePage}
                        value={range}
                        onChange={onRange}
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
    );
}
