import { Check, Copy } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';
import { cn } from '@/lib/utils';

/**
 * A technical value in mono with a copy button (design-system §6).
 */
export function CopyValue({
    value,
    label,
    className,
}: {
    value: string;
    label: string;
    className?: string;
}) {
    const [copied, copy] = useClipboard();

    return (
        <div
            className={cn(
                'flex items-start gap-2 rounded-md border bg-muted px-3 py-2',
                className,
            )}
        >
            <code className="min-w-0 flex-1 type-mono break-all">{value}</code>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-7 shrink-0"
                aria-label={copied === value ? 'Copied' : `Copy ${label}`}
                onClick={() => void copy(value)}
            >
                {copied === value ? (
                    <Check className="size-4" />
                ) : (
                    <Copy className="size-4" />
                )}
            </Button>
        </div>
    );
}
