import { useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

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
    /** A tooltip on the trigger (icon-only buttons name themselves here). */
    tooltip?: ReactNode;
    /**
     * Controlled: the popover opens from elsewhere (a menu item) and
     * `children` is only where it points (`PopoverAnchor`), not a trigger.
     */
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
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
    tooltip,
    open: controlledOpen,
    onOpenChange,
}: ConfirmPopoverProps) {
    const [ownOpen, setOwnOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const controlled = controlledOpen !== undefined;
    const open = controlled ? controlledOpen : ownOpen;
    const setOpen = (next: boolean) => {
        if (!controlled) {
            setOwnOpen(next);
        }

        onOpenChange?.(next);
    };

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
            {controlled ? (
                <PopoverAnchor asChild>{children}</PopoverAnchor>
            ) : tooltip ? (
                <Tooltip>
                    <PopoverTrigger asChild>
                        <TooltipTrigger asChild>{children}</TooltipTrigger>
                    </PopoverTrigger>
                    <TooltipContent>{tooltip}</TooltipContent>
                </Tooltip>
            ) : (
                <PopoverTrigger asChild>{children}</PopoverTrigger>
            )}
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
