import { ChevronRight } from 'lucide-react';
import { useState } from 'react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { explainMessage } from '@/features/moves/shape';
import { cn } from '@/lib/utils';

/**
 * A backend message as a human sentence first; a technical cause (an exit
 * code, an installer line) folds under "Details" in muted mono.
 */
export function MessageDetail({
    message,
    fallback,
    className,
}: {
    message: string;
    /** The sentence to show when the whole message is technical. */
    fallback: string;
    /** For the summary line (size and colour come from the place it sits). */
    className?: string;
}) {
    const [open, setOpen] = useState(false);
    const { summary, detail } = explainMessage(message, fallback);

    if (detail === null) {
        return <p className={cn('break-words', className)}>{summary}</p>;
    }

    return (
        <Collapsible
            open={open}
            onOpenChange={setOpen}
            className="flex flex-col gap-1.5"
        >
            <p className={cn('break-words', className)}>{summary}</p>
            <CollapsibleTrigger className="flex w-fit items-center gap-1 rounded-md type-small text-muted-foreground transition-colors duration-fast hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                <ChevronRight
                    aria-hidden="true"
                    className={cn(
                        'size-4 shrink-0 transition-transform duration-fast',
                        open && 'rotate-90',
                    )}
                />
                Details
            </CollapsibleTrigger>
            <CollapsibleContent>
                <pre className="rounded-lg bg-recessed px-3 py-2 type-mono-small break-words whitespace-pre-wrap text-muted-foreground">
                    {detail}
                </pre>
            </CollapsibleContent>
        </Collapsible>
    );
}
