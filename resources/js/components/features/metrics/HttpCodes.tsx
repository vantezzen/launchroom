import { Card, CardContent, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { formatNumber } from '@/utils/number';
import { useMetricsData } from './hooks';

function HttpCodes({ filter, timeRange }: { filter: string; timeRange: string }) {
    const data = useMetricsData(`sum by (code) (round(rate(traefik_service_requests_total${filter}[${timeRange}]) * time()/1000))`);

    if (!data) {
        return <Skeleton className="h-[200px]" />;
    }

    return (
        <Card>
            <CardTitle>HTTP Codes</CardTitle>
            <CardContent>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Code</TableHead>
                            <TableHead>Count</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {data?.map(({ metric, value }) => (
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
