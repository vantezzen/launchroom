import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';

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
 */
export function ResultGrid({
    columns,
    rows,
    offset = 0,
    className,
}: {
    columns: string[];
    rows: (string | null)[][];
    /** Number of the first row minus one (pages). */
    offset?: number;
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
                                {offset + index + 1}
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
