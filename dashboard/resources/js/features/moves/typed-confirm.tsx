import { Form } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type FormDefinition = {
    action: string;
    method: 'get' | 'post' | 'put' | 'patch' | 'delete';
};

/**
 * A step that can't be taken back asks for a typed word first (the server's
 * name, "hand over"); the value is sent as `confirmation`.
 */
export function TypedConfirm({
    trigger,
    title,
    description,
    expected,
    confirmLabel,
    form,
    data = {},
    destructive = false,
    disabled = false,
    loose = false,
}: {
    trigger: string;
    title: string;
    description: ReactNode;
    expected: string;
    confirmLabel: string;
    form: FormDefinition;
    data?: Record<string, unknown>;
    destructive?: boolean;
    disabled?: boolean;
    /** Ignore case (for a phrase rather than a name). */
    loose?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [typed, setTyped] = useState('');

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                setTyped('');
            }}
        >
            <DialogTrigger asChild>
                <Button
                    variant={destructive ? 'destructive' : 'default'}
                    disabled={disabled}
                >
                    {trigger}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>{title}</DialogTitle>
                <DialogDescription asChild>
                    <div className="flex flex-col gap-2">{description}</div>
                </DialogDescription>
                <Form
                    {...form}
                    transform={(values) => ({
                        ...values,
                        ...data,
                        confirmation: typed.trim(),
                    })}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="flex flex-col gap-2">
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
                                    onChange={(event) =>
                                        setTyped(event.target.value)
                                    }
                                />
                                <InputError
                                    message={
                                        errors.confirmation ??
                                        errors.target ??
                                        Object.values(errors)[0]
                                    }
                                />
                            </div>
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary" type="button">
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    variant={
                                        destructive ? 'destructive' : 'default'
                                    }
                                    disabled={
                                        processing ||
                                        (loose
                                            ? typed.trim().toLowerCase() !==
                                              expected.toLowerCase()
                                            : typed.trim() !== expected)
                                    }
                                >
                                    {processing && <Spinner />}
                                    {confirmLabel}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
