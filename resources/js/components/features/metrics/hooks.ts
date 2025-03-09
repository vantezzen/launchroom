import { useEffect, useState } from 'react';

type Metric = {
    metric: Record<string, string>;
    timestamp: number;
    value: number;
};

const fetchMetric = async (query: string) => {
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

export function useMetricsData(query: string) {
    const [metric, setMetric] = useState<Metric[] | null>();

    useEffect(() => {
        const loadMetric = async () => {
            const result = await fetchMetric(query);

            const metricDetails = result
                .map((metric: any) => {
                    return {
                        metric: metric.metric,
                        timestamp: metric.value[0],
                        value: Number(metric.value[1]),
                    };
                })
                .sort((a: any, b: any) => b.value - a.value);

            setMetric(metricDetails);
        };

        loadMetric();
    }, [query]);

    return metric;
}
