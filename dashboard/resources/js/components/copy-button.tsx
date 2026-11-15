import { Check, Copy } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';
import { cn } from '@/lib/utils';

/** An icon button that copies `value`; `label` names it ("Copy {label}"). */
export function CopyButton({
    value,
    label,
    size = 'sm',
    className,
}: {
    value: string;
    label: string;
    /** `xs` sits inside a line of small text (24 px). */
    size?: 'xs' | 'sm';
    className?: string;
}) {
    const [copied, copy] = useClipboard();

    return (
        <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            className={cn(
                'shrink-0 text-muted-foreground',
                size === 'xs' && 'size-6 [&_svg]:size-3.5',
                className,
            )}
            aria-label={copied === value ? 'Copied' : `Copy ${label}`}
            title={size === 'xs' ? `Copy ${label}` : undefined}
            onClick={() => void copy(value)}
        >
            {copied === value ? <Check /> : <Copy />}
        </Button>
    );
}
