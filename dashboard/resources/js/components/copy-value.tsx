import { CopyButton } from '@/components/copy-button';
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
    return (
        <div
            className={cn(
                'flex items-start gap-2 rounded-md border bg-muted px-3 py-2',
                className,
            )}
        >
            <code className="min-w-0 flex-1 type-mono break-all">{value}</code>
            <CopyButton
                value={value}
                label={label}
                className="size-7 text-foreground [&_svg]:size-4"
            />
        </div>
    );
}
