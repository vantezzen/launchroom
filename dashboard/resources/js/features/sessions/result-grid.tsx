import { Download } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { downloadText, exportFileName, toCsv, toJson } from './export';

/** Cells longer than this are cut; the full value opens in a popover. */
const CUT = 80;

export function NullChip() {
    return (
        <span className="rounded-sm bg-hover px-1 py-px font-sans text-[11px] tracking-wide text-faint uppercase">
            null
        </span>
    );
}

function Cell({ value }: { value: string | null }) {
    if (value === null) {
        return <NullChip />;
    }

    if (value.length <= CUT && !value.includes('\n')) {
        return (
            <span className="whitespace-pre">
                {value === '' ? <span className="text-faint">''</span> : value}
            </span>
        );
    }

    return (
        <Popover>
            <PopoverTrigger className="cursor-pointer text-left whitespace-pre hover:text-signal focus-visible:text-signal focus-visible:outline-none">
                {value.replaceAll('\n', ' ').slice(0, CUT)}
                <span className="text-faint">…</span>
            </PopoverTrigger>
            <PopoverContent
                align="start"
                className="max-h-80 w-[min(32rem,90vw)] overflow-auto"
            >
                <pre className="type-mono-small break-all whitespace-pre-wrap">
                    {value}
                </pre>
            </PopoverContent>
        </Popover>
    );
}

/**
 * The result grid (docs/08 §15): sticky header, row numbers, mono cells,
 * NULL as a faint chip, long cells cut with the full value in a popover.
 * With `onOpenRow` the row number opens that row in the inspector.
 */
export function ResultGrid({
    columns,
    rows,
    offset = 0,
    onOpenRow,
    className,
}: {
    columns: string[];
    rows: (string | null)[][];
    /** Number of the first row minus one (pages). */
    offset?: number;
    onOpenRow?: (index: number) => void;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'max-h-[min(60vh,560px)] overflow-auto rounded-xl border bg-card',
                className,
            )}
        >
            <table className="w-full border-separate border-spacing-0 type-mono-small">
                <thead>
                    <tr>
                        <th
                            scope="col"
                            className="sticky top-0 left-0 z-20 w-10 border-b bg-recessed px-3 py-2 text-right font-normal text-faint"
                        >
                            <span className="sr-only">Row</span>
                        </th>
                        {columns.map((column, index) => (
                            <th
                                key={`${column}-${index}`}
                                scope="col"
                                className="sticky top-0 z-10 border-b bg-recessed px-3 py-2 text-left font-sans text-[12px] font-medium whitespace-nowrap text-muted-foreground"
                            >
                                {column}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, index) => (
                        <tr key={index} className="hover:bg-hover/60">
                            <td className="sticky left-0 border-b bg-card px-3 py-1.5 text-right text-faint tabular-nums">
                                {onOpenRow ? (
                                    <button
                                        type="button"
                                        className="cursor-pointer rounded-sm tabular-nums underline-offset-2 hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        aria-label={`Open row ${offset + index + 1}`}
                                        onClick={() => onOpenRow(index)}
                                    >
                                        {offset + index + 1}
                                    </button>
                                ) : (
                                    offset + index + 1
                                )}
                            </td>
                            {row.map((value, cell) => (
                                <td
                                    key={cell}
                                    className="border-b px-3 py-1.5 align-top"
                                >
                                    <Cell value={value} />
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/**
 * Take the rows on screen out of the grid (docs/08 §15): copy as CSV or
 * JSON, or download a CSV. Built in the browser; nothing is stored.
 */
export function ResultExport({
    name,
    columns,
    rows,
    offset = 0,
}: {
    /** Table or key name for the file ("orders-rows-1-50.csv"). */
    name: string;
    columns: string[];
    rows: (string | null)[][];
    offset?: number;
}) {
    const copy = async (text: string, what: string) => {
        try {
            await navigator.clipboard.writeText(text);
            toast.success(
                `Copied ${rows.length} ${rows.length === 1 ? 'row' : 'rows'} as ${what}.`,
            );
        } catch {
            toast.error('Copying isn’t allowed in this browser.');
        }
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="sm" disabled={rows.length === 0}>
                    <Download aria-hidden="true" />
                    Export
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem
                    onSelect={() => void copy(toCsv(columns, rows), 'CSV')}
                >
                    Copy as CSV
                </DropdownMenuItem>
                <DropdownMenuItem
                    onSelect={() => void copy(toJson(columns, rows), 'JSON')}
                >
                    Copy as JSON
                </DropdownMenuItem>
                <DropdownMenuItem
                    onSelect={() =>
                        downloadText(
                            exportFileName(
                                name,
                                offset + 1,
                                offset + rows.length,
                            ),
                            toCsv(columns, rows),
                            'text/csv;charset=utf-8',
                        )
                    }
                >
                    Download CSV
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
