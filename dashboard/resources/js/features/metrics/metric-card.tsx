import { Logo } from '@/components/logo';
import { Sparkline } from '@/components/charts/sparkline';
import type { StatDelta } from '@/components/charts/stat-tile';
import { cn } from '@/lib/utils';

export type MetricCardRow = {
    key: string;
    label: string;
    /** Logo key of the service; omitted for "Other". */
    logo?: string;
    values: (number | null)[];
    /** The row's value for the range, formatted. */
    value: string;
};

const deltaTone = {
    good: 'bg-(--status-ok-soft) text-(--status-ok)',
    bad: 'bg-(--status-error-soft) text-status-error',
    neutral: 'bg-(--hover) text-muted-foreground',
} as const;

/**
 * Metric card of the project Metrics page (docs/15 §2.3, the CDN overview
 * pattern): label and delta chip, the range value in `stat`, then up to
 * three stacked sparklines (one per top service, value right). The whole
 * card selects which metric the big chart below shows (`aria-pressed`).
 * Every sparkline is the signal colour: comparison happens by position and
 * label, not hue (design-system §3.6).
 */
export function MetricCard({
    label,
    value,
    unit,
    delta,
    hint,
    rows,
    min,
    max,
    selected,
    onSelect,
}: {
    label: string;
    value: string | null;
    unit?: string;
    delta?: StatDelta;
    hint?: string;
    rows: MetricCardRow[];
    min?: number;
    max?: number;
    selected: boolean;
    onSelect: () => void;
}) {
    return (
        <button
            type="button"
            aria-pressed={selected}
            onClick={onSelect}
            className={cn(
                'flex min-w-0 flex-col gap-4 rounded-[14px] border bg-card p-5 text-left shadow-[0_1px_2px_rgb(0_0_0/0.04)] dark:shadow-none',
                'hover:border-(--border-strong) motion-safe:transition-[border-color,box-shadow] motion-safe:duration-[120ms]',
                'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--signal)',
                selected && 'border-foreground ring-[0.5px] ring-foreground',
            )}
        >
            <div className="flex items-center justify-between gap-2">
                <span className="type-label text-muted-foreground">
                    {label}
                </span>
                {delta && (
                    <span
                        className={cn(
                            'rounded-full px-1.5 py-px type-small tabular-nums',
                            deltaTone[delta.tone ?? 'neutral'],
                        )}
                    >
                        {delta.label}
                    </span>
                )}
            </div>
            <div className="-mt-2 flex items-baseline gap-1.5">
                <span className="text-2xl leading-[30px] font-semibold tracking-[-0.02em] tabular-nums">
                    {value ?? '–'}
                </span>
                {unit && value !== null && (
                    <span className="type-small text-muted-foreground">
                        {unit}
                    </span>
                )}
            </div>
            {rows.length > 0 ? (
                <ul className="flex flex-col gap-2.5">
                    {rows.map((row) => (
                        <li
                            key={row.key}
                            className="grid grid-cols-[minmax(0,1fr)_minmax(40px,1fr)_auto] items-center gap-3"
                        >
                            <span className="flex min-w-0 items-center gap-2">
                                {row.logo ? (
                                    <Logo
                                        kind={row.logo}
                                        name={row.label}
                                        size={20}
                                        decorative
                                    />
                                ) : (
                                    <span
                                        aria-hidden="true"
                                        className="size-5 shrink-0"
                                    />
                                )}
                                <span className="truncate type-small text-foreground">
                                    {row.label}
                                </span>
                            </span>
                            <Sparkline
                                values={row.values}
                                height={20}
                                min={min}
                                max={max}
                                label={`${row.label}, ${label}`}
                                strokeWidth={1.5}
                            />
                            <span className="min-w-12 text-right type-small text-muted-foreground tabular-nums">
                                {row.value}
                            </span>
                        </li>
                    ))}
                </ul>
            ) : (
                hint && (
                    <p className="type-small text-muted-foreground">{hint}</p>
                )
            )}
        </button>
    );
}
