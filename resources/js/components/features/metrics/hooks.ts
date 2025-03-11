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

const fetchMetricRange = async (query: string, startDate: Date, endDate: Date, stepSeconds = 300) => {
    try {
        const url = route('metrics.index', {
            query,
            api: 'query_range',
            start: startDate.toISOString(),
            end: endDate.toISOString(),
            step: stepSeconds,
        });
        const response = await fetch(url);
        const data = await response.json();
        return data.data.result;
    } catch (error) {
        console.error('Failed to fetch metrics', error);
        return [];
    }
};
export function useMetricsDataRange(query: string, startDate: Date, endDate: Date, stepSeconds = 300) {
    const [metric, setMetric] = useState<Metric[] | null>();

    useEffect(() => {
        const loadMetric = async () => {
            const result = await fetchMetricRange(query, startDate, endDate, stepSeconds);

            const metricDetails = result
                .map((entry: any) => {
                    return entry.values.map((value: any) => {
                        return {
                            metric: entry.metric,
                            timestamp: value[0],
                            value: Number(value[1]),
                        };
                    });
                })
                .flat()
                .sort((a: any, b: any) => a.timestamp - b.timestamp);

            setMetric(metricDetails);
        };

        loadMetric();
    }, [query, startDate, endDate, stepSeconds]);

    return metric;
}
