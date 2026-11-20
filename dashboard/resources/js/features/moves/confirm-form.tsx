import { router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { ConfirmPopover } from '@/components/patterns/confirm-popover';

export type FormDefinition = {
    action: string;
    method: 'get' | 'post' | 'put' | 'patch' | 'delete';
};

/**
 * A reversible move action (Cancel move, Stop a failed service) confirmed
 * in a popover anchored to its button, then sent as the form's request.
 */
export function ConfirmForm({
    children,
    title,
    consequence,
    confirmLabel,
    form,
    tone = 'destructive',
    align = 'end',
}: {
    /** The trigger (a Button). */
    children: ReactNode;
    title: string;
    consequence: string;
    confirmLabel: string;
    form: FormDefinition;
    tone?: 'default' | 'destructive';
    align?: 'start' | 'center' | 'end';
}) {
    return (
        <ConfirmPopover
            title={title}
            consequence={consequence}
            confirmLabel={confirmLabel}
            tone={tone}
            align={align}
            onConfirm={() =>
                new Promise<void>((resolve) =>
                    router.visit(form.action, {
                        method: form.method,
                        preserveScroll: true,
                        onFinish: () => resolve(),
                    }),
                )
            }
        >
            {children}
        </ConfirmPopover>
    );
}
