import { Card, CardContent, CardTitle } from '@/components/ui/card';
import { ChartContainer, ChartTooltip, ChartTooltipContent } from '@/components/ui/chart';
import { formatNumber } from '@/utils/number';
import { prometheusDurationToSeconds } from '@/utils/time';
import { useMemo } from 'react';
import { Area, AreaChart, CartesianGrid, XAxis, YAxis } from 'recharts';
import { useMetricsDataRange } from './hooks';

const getStep = (range: number) => {
    if (range <= 15 * 60) return 10; // Last 15m → 10s step
    if (range <= 3600) return 30; // Last 1h → 30s step
    if (range <= 6 * 3600) return 600; // Last 6h → 10m step
    if (range <= 12 * 3600) return 60 * 15; // Last 12h → 15m step
    return 60 * 30; // 24h or more → 30m step
};

function TotalRequests({ filter, timeRange }: { filter: string; timeRange: string }) {
    const timeSeconds = prometheusDurationToSeconds(timeRange);
    const step = getStep(timeSeconds);

    const endDate = useMemo(() => new Date(), []);
    const startDate = useMemo(() => new Date(endDate.getTime() - timeSeconds * 1000), [timeSeconds]);

    const data = useMetricsDataRange(`sum(increase(traefik_service_requests_total${filter}[${step}s]))`, startDate, endDate, step);

    const chartData = data?.map((entry) => ({
        name: new Date(entry.timestamp * 1000).toISOString(),
        value: entry.value,
    }));

    if (!data?.length) {
        return null;
    }

    return (
        <Card>
            <CardTitle>Total requests</CardTitle>
            <CardContent>
                <ChartContainer config={{}} className="h-[300px] w-full">
                    <AreaChart accessibilityLayer data={chartData}>
                        <defs>
                            <linearGradient id="colorGradient-total-requests" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="5%" stopColor="#c4ebfd" stopOpacity={0.8} />
                                <stop offset="95%" stopColor="#c4ebfd" stopOpacity={0.1} />
                            </linearGradient>
                        </defs>
                        <CartesianGrid vertical={false} />
                        <XAxis
                            dataKey="name"
                            tickLine={false}
                            tickMargin={10}
                            axisLine={false}
                            tickFormatter={(value) => new Date(value).toLocaleString()}
                        />
                        <YAxis tickFormatter={(value) => formatNumber(value)} />
                        <ChartTooltip
                            cursor={false}
                            content={<ChartTooltipContent indicator="dashed" />}
                            labelFormatter={(value) => new Date(value).toLocaleString()}
                        />
                        <Area type="monotone" dataKey="value" stroke="#0ea5e9" fill={`url(#colorGradient-total-requests)`} />
                    </AreaChart>
                </ChartContainer>
            </CardContent>
        </Card>
    );
}

export default TotalRequests;
