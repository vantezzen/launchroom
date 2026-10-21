import { formatBytes, formatCompact } from '@/components/charts/format';
import type { StatDelta } from '@/components/charts/stat-tile';
import type { ChartMarker } from '@/components/charts/time-series-chart';

/** Requests per second: "12.4", "0.03", "1.2K". */
export function formatRate(value: number): string {
    if (value === 0) {
        return '0';
    }

    if (value < 0.01) {
        return '<0.01';
    }

    if (value < 0.1) {
        return value.toFixed(2);
    }

    return value < 1000
        ? value.toFixed(value < 10 ? 1 : 0)
        : formatCompact(value);
}

/** Latency: "38 ms", "1.2 s", "> 30 s". */
export function formatMs(value: number): string {
    if (value >= 30_000) {
        return '> 30 s';
    }

    if (value >= 1000) {
        return `${(value / 1000).toFixed(value < 10_000 ? 1 : 0)} s`;
    }

    return `${Math.round(value)} ms`;
}

export function formatErrorRate(value: number): string {
    if (value === 0) {
        return '0%';
    }

    return value < 1 ? `${value.toFixed(2)}%` : `${value.toFixed(1)}%`;
}

export const formatBandwidth = (bytesPerSecond: number) =>
    `${formatBytes(bytesPerSecond)}/s`;

export const formatCores = (value: number) =>
    value < 1 ? `${Math.round(value * 1000)}m` : value.toFixed(2);

export const formatMemory = (mb: number) => formatBytes(mb * 1024 * 1024);

/**
 * Change against the previous period (design-system §9): coloured only when
 * the direction has a meaning (`lowerIsBetter` for errors and latency) and
 * the change matters (≥ 10 % and at least `minAbsolute`, so 0.30 % → 0.32 %
 * errors stays neutral), hidden when there's nothing to compare with.
 */
export function delta(
    current: number | null | undefined,
    previous: number | null | undefined,
    meaning: 'neutral' | 'lowerIsBetter' = 'neutral',
    minAbsolute = 0,
): StatDelta | undefined {
    if (current == null || previous == null || previous === 0) {
        return undefined;
    }

    const change = ((current - previous) / previous) * 100;

    if (!Number.isFinite(change)) {
        return undefined;
    }

    const rounded = Math.round(change);
    const label =
        rounded === 0
            ? '±0%'
            : `${rounded > 0 ? '+' : '−'}${Math.abs(rounded)}%`;
    const matters =
        Math.abs(change) >= 10 && Math.abs(current - previous) >= minAbsolute;

    if (meaning === 'neutral' || !matters) {
        return { label, tone: 'neutral' };
    }

    return { label, tone: change < 0 ? 'good' : 'bad' };
}

type Deploy = App.Data.Metrics.DeployMarkerData;

/**
 * Deploy markers for time charts (design-system §9): signal ticks labelled
 * with the short SHA, red for failed deploys, the service and outcome on hover.
 */
export function deployMarkers(
    deploys: Deploy[] | undefined,
    serviceId?: string,
): ChartMarker[] {
    return (deploys ?? [])
        .filter((deploy) => !serviceId || deploy.serviceId === serviceId)
        .map((deploy) => {
            const failed = deploy.status === 'failed';
            const outcome = failed
                ? 'failed'
                : deploy.status === 'active'
                  ? 'live'
                  : deploy.status.replace(/_/g, ' ');

            return {
                t: Date.parse(deploy.at),
                label: deploy.sha ?? (failed ? 'failed' : 'deploy'),
                tone: failed ? 'error' : 'signal',
                detail: [deploy.serviceName, deploy.sha, outcome]
                    .filter(Boolean)
                    .join(' · '),
            };
        });
}

/** Zips a series with the response's timestamps for TimeSeriesChart. */
export function points(
    values: (number | null)[],
    times: number[],
): { t: number; v: number | null }[] {
    return values.map((v, i) => ({ t: times[i], v }));
}
