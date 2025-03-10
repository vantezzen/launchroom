import { Card, CardContent, CardTitle } from '@/components/ui/card';
import { ChartContainer, ChartTooltip, ChartTooltipContent } from '@/components/ui/chart';
import { Skeleton } from '@/components/ui/skeleton';
import { formatNumber } from '@/utils/number';
import { useMemo } from 'react';
import { Bar, BarChart, CartesianGrid, XAxis, YAxis } from 'recharts';
import { useMetricsData } from './hooks';

function RequestsPerPath({ filter, timeRange }: { filter: string; timeRange: string }) {
    const data = useMetricsData(`sum by (xrequestpath) (round(rate(traefik_router_requests_total${filter}[${timeRange}]) * time()/1000 ))`);

    const chartData = useMemo(
        () => data?.map(({ metric, value }) => ({ name: metric.xrequestpath, value })).filter((metric) => metric.value > 0),
        [data],
    );

    if (!data) {
        return <Skeleton className="h-[400px]" />;
    }

    return (
        <Card>
            <CardTitle>Requests Per Path</CardTitle>
            <CardContent>
                <ChartContainer config={{}} className="h-[300px] w-full">
                    <BarChart accessibilityLayer data={chartData}>
                        <CartesianGrid vertical={false} />
                        <XAxis dataKey="name" tickLine={false} tickMargin={10} axisLine={false} />
                        <YAxis tickFormatter={(value) => formatNumber(value)} />
                        <ChartTooltip cursor={false} content={<ChartTooltipContent indicator="dashed" />} />
                        <Bar dataKey="value" fill="#3498db" radius={4} />
                    </BarChart>
                </ChartContainer>
            </CardContent>
        </Card>
    );
}

export default RequestsPerPath;
