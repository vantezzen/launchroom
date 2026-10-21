import { Plus, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export type LabelRow = { key: string; value: string };

export function toRows(labels: Record<string, string>): LabelRow[] {
    return Object.entries(labels).map(([key, value]) => ({ key, value }));
}

export function toLabels(rows: LabelRow[]): Record<string, string> {
    const labels: Record<string, string> = {};

    for (const row of rows) {
        const key = row.key.trim();

        if (key !== '') {
            labels[key] = row.value.trim();
        }
    }

    return labels;
}

/**
 * key = value rows (server labels, required placement labels). `zone` and
 * `provider` are the failure domains the scheduler spreads replicas across.
 */
export function LabelsEditor({
    id,
    rows,
    onChange,
    disabled,
    addLabel = 'Add label',
}: {
    id: string;
    rows: LabelRow[];
    onChange: (rows: LabelRow[]) => void;
    disabled?: boolean;
    addLabel?: string;
}) {
    const update = (index: number, patch: Partial<LabelRow>) =>
        onChange(
            rows.map((row, i) => (i === index ? { ...row, ...patch } : row)),
        );

    return (
        <div className="flex flex-col gap-2">
            {rows.length === 0 && (
                <p className="type-small text-muted-foreground">No labels.</p>
            )}
            {rows.map((row, index) => (
                <div key={index} className="flex items-center gap-2">
                    <Input
                        aria-label={`Label ${index + 1} name`}
                        id={index === 0 ? id : undefined}
                        value={row.key}
                        placeholder="zone"
                        disabled={disabled}
                        onChange={(event) =>
                            update(index, { key: event.target.value })
                        }
                        className="type-mono"
                    />
                    <span className="text-muted-foreground" aria-hidden>
                        =
                    </span>
                    <Input
                        aria-label={`Label ${index + 1} value`}
                        value={row.value}
                        placeholder="hel"
                        disabled={disabled}
                        onChange={(event) =>
                            update(index, { value: event.target.value })
                        }
                        className="type-mono"
                    />
                    {!disabled && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label={`Remove label ${row.key || index + 1}`}
                            onClick={() =>
                                onChange(rows.filter((_, i) => i !== index))
                            }
                        >
                            <X className="size-4" />
                        </Button>
                    )}
                </div>
            ))}
            {!disabled && (
                <div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            onChange([...rows, { key: '', value: '' }])
                        }
                    >
                        <Plus className="size-4" />
                        {addLabel}
                    </Button>
                </div>
            )}
        </div>
    );
}

export function LabelList({ labels }: { labels: Record<string, string> }) {
    const entries = Object.entries(labels);

    if (entries.length === 0) {
        return <span className="text-muted-foreground">No labels</span>;
    }

    return (
        <span className="inline-flex flex-wrap gap-1">
            {entries.map(([key, value]) => (
                <span
                    key={key}
                    className="inline-flex h-5 items-center rounded-[6px] border px-1.5 type-mono-small text-muted-foreground"
                >
                    {key}={value}
                </span>
            ))}
        </span>
    );
}
