import { ArrowRight } from 'lucide-react';
import { cn } from '@/lib/utils';
import { publishedWords } from './format';

/**
 * The running version beside the one it becomes (docs/04 §14.4): two mono
 * tiles joined by an arrow, each with when it was published. The new one
 * carries the foreground border, the old one stays quiet.
 */
export function VersionPair({
    from,
    fromPublishedAt,
    to,
    toPublishedAt,
    className,
}: {
    from: string;
    fromPublishedAt: string | null;
    to: string;
    toPublishedAt: string | null;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-center gap-3 rounded-xl bg-recessed p-4',
                className,
            )}
        >
            <VersionTile
                label="Now"
                version={from}
                published={publishedWords(fromPublishedAt)}
            />
            <ArrowRight
                aria-label="becomes"
                className="size-4 text-muted-foreground"
            />
            <VersionTile
                label="Next"
                version={to}
                published={publishedWords(toPublishedAt)}
                emphasis
            />
        </div>
    );
}

function VersionTile({
    label,
    version,
    published,
    emphasis = false,
}: {
    label: string;
    version: string;
    published: string | null;
    emphasis?: boolean;
}) {
    return (
        <div
            className={cn(
                'flex min-w-0 flex-col gap-1 rounded-lg border bg-card px-3 py-2.5',
                emphasis && 'border-foreground',
            )}
        >
            <span className="type-small text-muted-foreground">{label}</span>
            <span
                className={cn(
                    'truncate type-mono text-[15px]',
                    emphasis ? 'font-medium' : 'text-muted-foreground',
                )}
                title={version}
            >
                {version}
            </span>
            <span className="truncate type-small text-muted-foreground">
                {published ?? 'Publish date unknown'}
            </span>
        </div>
    );
}
