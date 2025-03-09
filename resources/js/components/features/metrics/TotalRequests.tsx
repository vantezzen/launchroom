import { prometheusDurationToSeconds } from '@/utils/time';
import { useMetricsData } from './hooks';

const getStep = (range: number) => {
    if (range <= 15 * 60) return '30s'; // Last 15m → 30s step
    if (range <= 3600) return '1m'; // Last 1h → 1m step
    if (range <= 6 * 3600) return '10m'; // Last 6h → 10m step
    if (range <= 12 * 3600) return '15m'; // Last 12h → 15m step
    return '30m'; // 24h or more → 30m step
};

function TotalRequests({ filter, timeRange }: { filter: string; timeRange: string }) {
    const timeSeconds = prometheusDurationToSeconds(timeRange);
    const step = getStep(timeSeconds);

    const data = useMetricsData(`increase(traefik_service_requests_total${filter}[${timeRange}])`);

    console.log(data);

    return <div>TotalRequests</div>;
}

export default TotalRequests;
