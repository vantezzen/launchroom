import { X } from 'lucide-react';
import { formatBytes } from '@/features/backups/format';
import { cn } from '@/lib/utils';

type Point = App.Data.Backups.BackupTrendPointData;

export function formatDuration(ms: number | null): string {
    if (ms === null) {
        return '—';
    }

    if (ms < 1000) {
        return '< 1 s';
    }

    const seconds = Math.round(ms / 1000);

    if (seconds < 90) {
        return `${seconds} s`;
    }

    const minutes = Math.round(seconds / 60);

    return minutes < 90 ? `${minutes} min` : `${Math.round(minutes / 60)} h`;
}

function median(values: number[]): number | null {
    if (values.length === 0) {
        return null;
    }

    const sorted = [...values].sort((a, b) => a - b);

    return sorted[Math.floor(sorted.length / 2)];
}

/**
 * Size, duration and success of the last backups (docs/14 §10.6): one
 * hairline-calm bar strip (height = size, failed runs a short red bar with
 * an ✕) and three facts. Colour only where it means something.
 */
export function BackupTrend({ points }: { points: Point[] }) {
    if (points.length < 2) {
        return null;
    }

    const sizes = points
        .filter((p) => p.status === 'succeeded' && p.sizeBytes !== null)
        .map((p) => p.sizeBytes as number);
    const maxSize = Math.max(1, ...sizes);
    const succeeded = points.filter((p) => p.status === 'succeeded').length;
    const typical = median(
        points
            .filter((p) => p.status === 'succeeded' && p.durationMs !== null)
            .map((p) => p.durationMs as number),
    );
    const last = [...points].reverse().find((p) => p.status === 'succeeded');

    return (
        <div className="flex flex-col gap-4 rounded-xl border bg-card p-5 shadow-card">
            <div
                role="img"
                aria-label={`Last ${points.length} backups: ${succeeded} succeeded`}
                className="flex h-16 items-end gap-1.5"
            >
                {points.map((point) => {
                    const failed = point.status === 'failed';
                    const height = failed
                        ? 18
                        : Math.max(
                              8,
                              Math.round(
                                  ((point.sizeBytes ?? 0) / maxSize) * 100,
                              ),
                          );

                    return (
                        <div
                            key={point.id}
                            title={`${new Date(point.at).toLocaleString()}: ${
                                failed
                                    ? 'Failed'
                                    : `${formatBytes(point.sizeBytes)} in ${formatDuration(point.durationMs)}`
                            }${point.subject === 'base_backup' ? ' (base backup)' : ''}`}
                            className="relative flex w-2.5 shrink-0 flex-col items-center justify-end"
                            style={{ height: '100%' }}
                        >
                            {failed && (
                                <X
                                    aria-hidden="true"
                                    className="mb-0.5 size-3 text-status-error"
                                />
                            )}
                            <span
                                className={cn(
                                    'w-full rounded-sm',
                                    failed
                                        ? 'bg-status-error'
                                        : point.subject === 'base_backup'
                                          ? 'bg-foreground/35'
                                          : 'bg-foreground/15',
                                )}
                                style={{ height: `${height}%` }}
                            />
                        </div>
                    );
                })}
            </div>
            <dl className="grid grid-cols-3 gap-3">
                <div className="flex flex-col">
                    <dt className="type-small text-muted-foreground">
                        Succeeded
                    </dt>
                    <dd className="type-body tabular-nums">
                        {succeeded} of {points.length}
                    </dd>
                </div>
                <div className="flex flex-col">
                    <dt className="type-small text-muted-foreground">
                        Usually takes
                    </dt>
                    <dd className="type-body tabular-nums">
                        {formatDuration(typical)}
                    </dd>
                </div>
                <div className="flex flex-col">
                    <dt className="type-small text-muted-foreground">
                        Last size
                    </dt>
                    <dd className="type-body tabular-nums">
                        {formatBytes(last?.sizeBytes ?? null)}
                    </dd>
                </div>
            </dl>
        </div>
    );
}
