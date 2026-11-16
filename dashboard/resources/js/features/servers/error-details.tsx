import { ChevronRight } from 'lucide-react';
import { CopyButton } from '@/components/copy-button';
import { cn } from '@/lib/utils';

/**
 * The raw error behind a product sentence (docs/08 §14): collapsed under
 * "Details", mono and copyable, for whoever has to look into it.
 */
export function ErrorDetails({
    text,
    className,
}: {
    text: string | null | undefined;
    className?: string;
}) {
    if (!text) {
        return null;
    }

    return (
        <details className={cn('group type-small', className)}>
            <summary className="inline-flex w-fit cursor-pointer list-none items-center gap-1 rounded-md text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-signal focus-visible:outline-none [&::-webkit-details-marker]:hidden">
                <ChevronRight
                    aria-hidden="true"
                    className="size-3.5 transition-transform duration-fast group-open:rotate-90"
                />
                Details
            </summary>
            <div className="mt-2 flex items-start gap-2 rounded-md border bg-recessed px-3 py-2">
                <code className="min-w-0 flex-1 type-mono-small break-all text-foreground">
                    {text}
                </code>
                <CopyButton value={text} label="error" size="xs" />
            </div>
        </details>
    );
}
