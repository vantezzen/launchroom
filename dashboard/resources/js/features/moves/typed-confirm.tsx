import { router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { TypedConfirmDialog } from '@/components/patterns/typed-confirm-dialog';
import type { TypedConfirmObject } from '@/components/patterns/typed-confirm-dialog';
import { Button } from '@/components/ui/button';

type FormDefinition = {
    action: string;
    method: 'get' | 'post' | 'put' | 'patch' | 'delete';
};

/**
 * A move step that can't be taken back asks for a typed word first (the
 * server's name, "hand over"); the value is sent as `confirmation`. A
 * trigger button around the shared `TypedConfirmDialog`.
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
    object,
    goes = [],
    goesHeading,
    kept = [],
    secondary,
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
    /** Passed through to the dialog: the object chip, what goes, what is kept. */
    object?: TypedConfirmObject;
    goes?: ReactNode[];
    goesHeading?: string;
    kept?: ReactNode[];
    /** Under the description: another way out ("Move to another server"). */
    secondary?: ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | undefined>();

    return (
        <>
            <Button
                variant={destructive ? 'destructive' : 'default'}
                disabled={disabled}
                onClick={() => setOpen(true)}
            >
                {trigger}
            </Button>
            <TypedConfirmDialog
                open={open}
                onOpenChange={(next) => {
                    setOpen(next);
                    setError(undefined);
                }}
                title={title}
                object={object}
                goes={goes}
                goesHeading={goesHeading}
                kept={kept}
                expected={expected}
                loose={loose}
                confirmLabel={confirmLabel}
                tone={destructive ? 'destructive' : 'default'}
                processing={processing}
                error={error}
                onConfirm={(typed) =>
                    router.visit(form.action, {
                        method: form.method,
                        data: { ...data, confirmation: typed },
                        preserveScroll: true,
                        onStart: () => setProcessing(true),
                        onFinish: () => setProcessing(false),
                        onSuccess: () => setOpen(false),
                        onError: (errors) =>
                            setError(
                                errors.confirmation ??
                                    errors.target ??
                                    Object.values(errors)[0],
                            ),
                    })
                }
            >
                {description != null && (
                    <div className="flex flex-col gap-2 type-body text-muted-foreground">
                        {description}
                    </div>
                )}
                {secondary}
            </TypedConfirmDialog>
        </>
    );
}
