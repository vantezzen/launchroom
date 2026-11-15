import type { StatDelta } from '@/components/charts/stat-tile';
import type { ChartMarker } from '@/components/charts/time-series-chart';

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
 * with the short SHA, red for failed deploys, the service and outcome on
 * hover, each linking to its deployment. The one mapping for every chart
 * (Analytics, Logs, Security, server pages) over `DeployMarkerData`.
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
                detail: `${[deploy.serviceName, deploy.sha]
                    .filter(Boolean)
                    .join(' ')}: ${outcome}`,
                href: deploy.href ?? undefined,
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
