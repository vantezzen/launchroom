import { ChevronDown, ChevronUp } from 'lucide-react';
import { CopyButton } from '@/components/copy-button';
import { SidePanel } from '@/components/patterns/sheet';
import { Button } from '@/components/ui/button';
import { NullChip } from './result-grid';

/**
 * One row of a result as label/value pairs (docs/08 §15 "Row inspector"):
 * wide rows stay readable in narrow space. Read-only; the rows are the
 * ones already on screen. ↑/↓ (or j/k) step through them.
 */
export function RowSheet({
    title,
    columns,
    rows,
    index,
    offset = 0,
    onIndexChange,
}: {
    /** Table, key or "Result". */
    title: string;
    columns: string[];
    rows: (string | null)[][];
    /** The open row (index into `rows`), null when closed. */
    index: number | null;
    /** Number of the first row minus one (pages). */
    offset?: number;
    onIndexChange: (index: number | null) => void;
}) {
    const row = index === null ? null : (rows[index] ?? null);
    const step = (by: number) => {
        if (index === null) {
            return;
        }

        const next = index + by;

        if (next >= 0 && next < rows.length) {
            onIndexChange(next);
        }
    };

    return (
        <SidePanel
            open={row !== null}
            onOpenChange={(open) => !open && onIndexChange(null)}
            title={`${title}, row ${offset + (index ?? 0) + 1}`}
            size="md"
            footer={
                <div className="flex items-center justify-between gap-2">
                    <span className="type-small text-muted-foreground tabular-nums">
                        {(index ?? 0) + 1} / {rows.length}
                    </span>
                    <span className="flex gap-1">
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={index === null || index === 0}
                            onClick={() => step(-1)}
                        >
                            <ChevronUp aria-hidden="true" />
                            Previous row
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={
                                index === null || index >= rows.length - 1
                            }
                            onClick={() => step(1)}
                        >
                            <ChevronDown aria-hidden="true" />
                            Next row
                        </Button>
                    </span>
                </div>
            }
        >
            {row !== null && (
                <dl
                    className="flex flex-col divide-y px-5"
                    onKeyDown={(event) => {
                        if (event.key === 'ArrowDown' || event.key === 'j') {
                            event.preventDefault();
                            step(1);
                        } else if (
                            event.key === 'ArrowUp' ||
                            event.key === 'k'
                        ) {
                            event.preventDefault();
                            step(-1);
                        }
                    }}
                    tabIndex={-1}
                >
                    {columns.map((column, i) => {
                        const value = row[i] ?? null;

                        return (
                            <div
                                key={`${column}-${i}`}
                                className="flex flex-col gap-1 py-3"
                            >
                                <dt className="flex items-center justify-between gap-2 type-small text-muted-foreground">
                                    <span className="truncate">{column}</span>
                                    {value !== null && (
                                        <CopyButton
                                            value={value}
                                            label={column}
                                            size="xs"
                                        />
                                    )}
                                </dt>
                                <dd className="type-mono-small [overflow-wrap:anywhere] whitespace-pre-wrap">
                                    {value === null ? (
                                        <NullChip />
                                    ) : value === '' ? (
                                        <span className="text-faint">''</span>
                                    ) : (
                                        value
                                    )}
                                </dd>
                            </div>
                        );
                    })}
                </dl>
            )}
        </SidePanel>
    );
}
