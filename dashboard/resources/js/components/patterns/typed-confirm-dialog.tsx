import { Check, Minus } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

export type TypedConfirmObject = {
    /** The name people recognise ("shop", "server-3"). */
    name: string;
    /** Where it lives, muted after the name ("2 environments"). */
    detail?: ReactNode;
    /** A 16 px icon or logo before the name. */
    icon?: ReactNode;
};

/**
 * Confirmation for a step that can't be taken back (design-system §13.9
 * "Typed confirm"): the object as a chip, what goes, what is kept, extra
 * fields (a final-backup checkbox, a password) and "Type `shop` to
 * confirm". Without `expected` the extra fields are the confirmation (a
 * password). Controlled: the trigger lives with the page.
 */
export function TypedConfirmDialog({
    open,
    onOpenChange,
    title,
    object,
    goes,
    goesHeading = 'Deleted',
    kept = [],
    expected,
    loose = false,
    confirmLabel,
    onConfirm,
    processing,
    ready = true,
    error,
    tone = 'destructive',
    children,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    object?: TypedConfirmObject;
    /** What is removed, one entry per line. */
    goes: ReactNode[];
    /** The heading over `goes` ("Deleted", "Ends"). */
    goesHeading?: string;
    /** What stays (snapshots, other members' access), with a check each. */
    kept?: ReactNode[];
    /** The word to type; leave out when `children` confirm (a password). */
    expected?: string;
    /** Ignore case (a phrase rather than a name). */
    loose?: boolean;
    confirmLabel: string;
    /** Gets the typed text (trimmed). */
    onConfirm: (typed: string) => void;
    processing: boolean;
    /** Further conditions for the confirm button (a filled password). */
    ready?: boolean;
    /** Under the typed field: the server's answer. */
    error?: string;
    /** `default` for steps that aren't data loss (handing over a server). */
    tone?: 'destructive' | 'default';
    children?: ReactNode;
}) {
    const [typed, setTyped] = useState('');
    const matches =
        expected === undefined ||
        (loose
            ? typed.trim().toLowerCase() === expected.toLowerCase()
            : typed.trim() === expected);

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                onOpenChange(next);
                setTyped('');
            }}
        >
            <DialogContent className="sm:max-w-md">
                <form
                    className="flex flex-col gap-5"
                    onSubmit={(event) => {
                        event.preventDefault();

                        if (matches && ready && !processing) {
                            onConfirm(typed.trim());
                        }
                    }}
                >
                    <div className="flex flex-col gap-3">
                        <DialogTitle>{title}</DialogTitle>
                        {object && (
                            <span className="inline-flex max-w-full items-center gap-2 self-start rounded-md border bg-recessed px-2.5 py-1 type-small">
                                {object.icon}
                                <span className="truncate font-medium text-foreground">
                                    {object.name}
                                </span>
                                {object.detail && (
                                    <span className="truncate text-muted-foreground">
                                        {object.detail}
                                    </span>
                                )}
                            </span>
                        )}
                    </div>
                    <DialogDescription asChild>
                        <div className="flex flex-col gap-3">
                            {goes.length > 0 && (
                                <ConsequenceList
                                    heading={goesHeading}
                                    items={goes}
                                    kind="goes"
                                />
                            )}
                            {kept.length > 0 && (
                                <ConsequenceList
                                    heading="Kept"
                                    items={kept}
                                    kind="kept"
                                />
                            )}
                        </div>
                    </DialogDescription>

                    {children}

                    {expected !== undefined && (
                        <div className="grid gap-2">
                            <Label htmlFor="typed-confirmation">
                                Type{' '}
                                <span className="type-mono-small">
                                    {expected}
                                </span>{' '}
                                to confirm
                            </Label>
                            <Input
                                id="typed-confirmation"
                                value={typed}
                                autoComplete="off"
                                spellCheck={false}
                                autoFocus
                                onChange={(event) =>
                                    setTyped(event.target.value)
                                }
                            />
                        </div>
                    )}
                    <InputError message={error} />

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="ghost">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            variant={
                                tone === 'destructive'
                                    ? 'destructive-solid'
                                    : 'default'
                            }
                            disabled={!matches || !ready || processing}
                        >
                            {processing && <Spinner />}
                            {confirmLabel}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function ConsequenceList({
    heading,
    items,
    kind,
}: {
    heading: string;
    items: ReactNode[];
    kind: 'goes' | 'kept';
}) {
    const Icon = kind === 'goes' ? Minus : Check;

    return (
        <div className="flex flex-col gap-1.5">
            <p className="type-small font-medium text-foreground">{heading}</p>
            <ul className="flex flex-col gap-1">
                {items.map((item, index) => (
                    <li
                        key={index}
                        className="flex items-start gap-2 type-small text-muted-foreground"
                    >
                        <Icon
                            className={
                                kind === 'goes'
                                    ? 'mt-0.5 size-3.5 shrink-0 text-status-error'
                                    : 'mt-0.5 size-3.5 shrink-0 text-muted-foreground'
                            }
                            aria-hidden="true"
                        />
                        <span>{item}</span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
