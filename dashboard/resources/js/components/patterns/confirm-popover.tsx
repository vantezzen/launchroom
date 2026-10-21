import { useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

type ConfirmPopoverProps = {
    /** The trigger (a Button). */
    children: ReactNode;
    /** Optional bold first line ("Roll back to 3b7d5e1?"). */
    title?: ReactNode;
    /** The consequence in one sentence. */
    consequence: ReactNode;
    confirmLabel: string;
    onConfirm: () => void | Promise<unknown>;
    /** `destructive` for removals (outline red). */
    tone?: 'default' | 'destructive';
    align?: 'start' | 'center' | 'end';
};

/**
 * Confirmation for reversible-but-consequential actions (design-system
 * §6): rollback, remove a member, remove a role. Irreversible data loss
 * uses a dialog with typed confirmation instead.
 */
export function ConfirmPopover({
    children,
    title,
    consequence,
    confirmLabel,
    onConfirm,
    tone = 'default',
    align = 'end',
}: ConfirmPopoverProps) {
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);

    const confirm = async () => {
        setBusy(true);

        try {
            await onConfirm();
            setOpen(false);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>{children}</PopoverTrigger>
            <PopoverContent align={align} className="flex w-80 flex-col gap-3">
                <div className="flex flex-col gap-1">
                    {title && <p className="type-label">{title}</p>}
                    <p className="type-body text-muted-foreground">
                        {consequence}
                    </p>
                </div>
                <div className="flex justify-end gap-2">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setOpen(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        size="sm"
                        variant={
                            tone === 'destructive' ? 'destructive' : 'default'
                        }
                        disabled={busy}
                        onClick={() => void confirm()}
                    >
                        {confirmLabel}
                    </Button>
                </div>
            </PopoverContent>
        </Popover>
    );
}
