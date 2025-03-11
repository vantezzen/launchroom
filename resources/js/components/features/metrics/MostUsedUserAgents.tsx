import { Card, CardContent, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { formatNumber } from '@/utils/number';
import { useMetricsData } from './hooks';

function MostUsedUserAgents({ filter, timeRange }: { filter: string; timeRange: string }) {
    const data = useMetricsData(`sum by (useragent) (round(rate(traefik_service_requests_total${filter}[${timeRange}]) * time()/1000))`);

    if (!data) {
        return <Skeleton className="h-[200px]" />;
    }

    return (
        <Card>
            <CardTitle>Most Used User Agents</CardTitle>
            <CardContent>
                <Table className="w-full">
                    <TableHeader>
                        <TableRow>
                            <TableHead>User Agent</TableHead>
                            <TableHead>Requests</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {data
                            ?.filter((entry) => entry.value > 0)
                            .map(({ metric, value }) => (
                                <TableRow key={metric.useragent}>
                                    <TableCell className={cn(!metric.useragent ? 'text-gray-400' : '', 'whitespace-pre-wrap')}>
                                        {metric.useragent ?? 'N/A'}
                                    </TableCell>
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

export default MostUsedUserAgents;
