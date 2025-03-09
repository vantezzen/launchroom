import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useEffect, useState } from 'react';
import HttpCodes from './HttpCodes';
import MostUsedUserAgents from './MostUsedUserAgents';
import RequestsPerPath from './RequestsPerPath';
import TotalRequests from './TotalRequests';

const TIME_RANGES = ['5m', '15m', '1h', '6h', '12h', '24h'];

const fetchMetrics = async (query: string) => {
    try {
        const url = route('metrics.index', {
            query,
        });
        const response = await fetch(url);
        const data = await response.json();
        return data.data.result;
    } catch (error) {
        console.error('Failed to fetch metrics', error);
        return [];
    }
};

type Metric = {
    metric: Record<string, string>;
    value: number;
};
type Metrics = {
    requests?: Metric[];
    paths?: Metric[];
    userAgents?: Metric[];
    responseTime?: Metric[];
    httpCodes?: Metric[];
    trafficIn?: Metric[];
    trafficOut?: Metric[];
};

const MetricsDashboard = ({ service }: { service?: string }) => {
    const [timeRange, setTimeRange] = useState('6h');

    const filter = service ? `{service="${service}"}` : '';

    useEffect(() => {
        const queries = {
            requests: `round(sum(rate(traefik_service_requests_total${filter}[${timeRange}]) * time()/1000))`,
            paths: `sum by (xrequestpath) (round(rate(traefik_router_requests_total${filter}[${timeRange}]) * time()/1000 ))`,
            userAgents: `sum by (useragent) (round(rate(traefik_service_requests_total${filter}[${timeRange}]) * time()/1000))`,
            responseTime: `sum(rate(traefik_service_request_duration_seconds_sum${filter}[${timeRange}])) / sum(rate(traefik_service_request_duration_seconds_count${filter}[${timeRange}]))`,

            httpCodes: `sum by (code) (round(rate(traefik_service_requests_total${filter}[${timeRange}]) * time()/1000))`,
            trafficIn: `round(sum(rate(traefik_service_bytes_read_total${filter}[${timeRange}]) * time()/1000))`,
            trafficOut: `round(sum(rate(traefik_service_bytes_write_total${filter}[${timeRange}]) * time()/1000))`,
        };
    }, [service, timeRange]);

    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between">
                <h1 className="text-2xl font-bold">Service Metrics for {service}</h1>
                <Select onValueChange={setTimeRange} defaultValue={timeRange}>
                    <SelectTrigger className="w-[120px]">
                        <SelectValue placeholder="Select Time" />
                    </SelectTrigger>
                    <SelectContent>
                        {TIME_RANGES.map((range) => (
                            <SelectItem key={range} value={range}>
                                {range}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            <TotalRequests timeRange={timeRange} filter={filter} />
            <RequestsPerPath timeRange={timeRange} filter={filter} />
            <MostUsedUserAgents timeRange={timeRange} filter={filter} />
            <HttpCodes timeRange={timeRange} filter={filter} />
        </div>
    );
};

export default MetricsDashboard;
