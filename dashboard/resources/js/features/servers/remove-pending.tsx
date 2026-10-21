import { Form } from '@inertiajs/react';
import { MoreHorizontal, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { revoke } from '@/routes/servers';
import { destroy as cancelEnrollment } from '@/routes/servers/enrollments';

/** What gets removed: an enrolment nobody used yet, or a connected server still waiting for approval. */
export type PendingTarget =
    | { kind: 'enrollment'; id: string; name: string | null }
    | { kind: 'server'; id: string; name: string };

function copy(target: PendingTarget) {
    const name = target.name ?? 'this server';

    return target.kind === 'enrollment'
        ? {
              title: `Remove ${target.name ?? 'the new server'}?`,
              description:
                  'It never connected. Its install command stops working right away.',
              form: cancelEnrollment.form(target.id),
          }
        : {
              title: `Remove ${name}?`,
              description:
                  'It was never approved and runs nothing. Its key stops working right away.',
              form: revoke.form(target.id),
          };
}

/** The confirmation for removing a pending server (card menu and pages). */
export function RemovePendingDialog({
    target,
    open,
    onOpenChange,
}: {
    target: PendingTarget;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { title, description, form } = copy(target);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogTitle>{title}</DialogTitle>
                <DialogDescription>{description}</DialogDescription>
                <Form
                    {...form}
                    options={{ preserveScroll: true }}
                    onSuccess={() => onOpenChange(false)}
                >
                    {({ processing }) => (
                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button variant="secondary" type="button">
                                    Cancel
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={processing}
                            >
                                Remove server
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

/** "⋯" on a pending card: one item, Remove (stays clickable above the card link). */
export function PendingCardMenu({ target }: { target: PendingTarget }) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        aria-label={`More for ${target.name ?? 'the new server'}`}
                        className="relative z-10 -my-1 text-muted-foreground"
                    >
                        <MoreHorizontal aria-hidden="true" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem
                        variant="destructive"
                        onSelect={() => setOpen(true)}
                    >
                        <Trash2 aria-hidden="true" />
                        Remove server
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            <RemovePendingDialog
                target={target}
                open={open}
                onOpenChange={setOpen}
            />
        </>
    );
}

/** The same action as a button (enrolment page, pending server page). */
export function RemovePendingButton({
    target,
    label = 'Remove',
}: {
    target: PendingTarget;
    label?: string;
}) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <Button variant="destructive" onClick={() => setOpen(true)}>
                {label}
            </Button>
            <RemovePendingDialog
                target={target}
                open={open}
                onOpenChange={setOpen}
            />
        </>
    );
}
