import { router } from '@inertiajs/react';
import { MoreHorizontal } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
} from '@/components/ui/popover';

/**
 * The `⋯` of a git connection card (docs/design/direction.md §5.12: items in
 * a list are removed from their own menu, not from a page-wide danger zone).
 * The one entry opens a confirmation anchored to the button.
 */
export function DisconnectMenu({
    name,
    label,
    title,
    consequence,
    confirmLabel,
    url,
}: {
    /** What the card shows ("acme-launchroom", "gitlab.com"). */
    name: string;
    /** "Disconnect…" or "Remove setup…". */
    label: string;
    title: string;
    consequence: string;
    confirmLabel: string;
    url: string;
}) {
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);

    const confirm = () => {
        setBusy(true);
        router.delete(url, {
            preserveScroll: true,
            onFinish: () => {
                setBusy(false);
                setConfirming(false);
            },
        });
    };

    return (
        <Popover open={confirming} onOpenChange={setConfirming}>
            <DropdownMenu modal={false}>
                <PopoverAnchor asChild>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            aria-label={`Options for ${name}`}
                        >
                            <MoreHorizontal aria-hidden="true" />
                        </Button>
                    </DropdownMenuTrigger>
                </PopoverAnchor>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem
                        className="text-status-error focus:text-status-error"
                        onSelect={() => setConfirming(true)}
                    >
                        {label}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            {/* The closing menu moves focus when the pointer leaves its item,
                which would count as a focus outside; outside clicks and Esc
                still close. */}
            <PopoverContent
                align="end"
                className="flex w-80 flex-col gap-3"
                onFocusOutside={(event) => event.preventDefault()}
            >
                <div className="flex flex-col gap-1">
                    <p className="type-label">{title}</p>
                    <p className="type-body text-muted-foreground">
                        {consequence}
                    </p>
                </div>
                <div className="flex justify-end gap-2">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setConfirming(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        size="sm"
                        variant="destructive"
                        disabled={busy}
                        onClick={confirm}
                    >
                        {confirmLabel}
                    </Button>
                </div>
            </PopoverContent>
        </Popover>
    );
}
