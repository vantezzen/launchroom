import { Card, CardContent, CardTitle } from '@/components/ui/card';
import { ChartContainer, ChartTooltip, ChartTooltipContent } from '@/components/ui/chart';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { formatNumber } from '@/utils/number';
import { Pie, PieChart } from 'recharts';
import { useMetricsData } from './hooks';

const getStatusColor = (status: string) => {
    if (status.startsWith('1')) return '#3498db';
    if (status.startsWith('2')) return '#2ecc71';
    if (status.startsWith('3')) return '#f1c40f';
    if (status.startsWith('4')) return '#e74c3c';
    if (status.startsWith('5')) return '#9b59b6';

    return '#34495e';
};

function HttpCodes({ filter, timeRange }: { filter: string; timeRange: string }) {
    const data = useMetricsData(`sum by (code) (round(rate(traefik_service_requests_total${filter}[${timeRange}]) * time()/1000))`);

    const chartData = data?.map(({ metric, value }) => ({
        status: metric.code,
        requests: value,
        fill: getStatusColor(metric.code),
    }));

    if (!data) {
        return <Skeleton className="h-[200px]" />;
    }

    return (
        <Card>
            <CardTitle>HTTP Codes</CardTitle>

            <CardContent>
                <ChartContainer config={{}} className="max-h-[250px] w-full">
                    <PieChart>
                        <ChartTooltip cursor={false} content={<ChartTooltipContent hideLabel />} />
                        <Pie data={chartData} dataKey="requests" nameKey="status" />
                    </PieChart>
                </ChartContainer>

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Code</TableHead>
                            <TableHead>Count</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {data
                            ?.filter((entry) => entry.value > 0)
                            .map(({ metric, value }) => (
                                <TableRow key={metric.code}>
                                    <TableCell>{metric.code}</TableCell>
                                    <TableCell>{formatNumber(value)}</TableCell>
                                </TableRow>
                            )) || (
                            <TableRow>
                                <TableCell colSpan={2} className="text-center">
                                    No data available
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    );
}

export default HttpCodes;
