import {
    CircleAlert,
    CircleCheck,
    Info,
    LoaderCircle,
    TriangleAlert,
} from 'lucide-react';
import { Toaster as Sonner, type ToasterProps } from 'sonner';
import { useAppearance } from '@/hooks/use-appearance';
import { useFlashToast } from '@/hooks/use-flash-toast';

/**
 * Toasts (design-system §6): bottom-right, float elevation, icon + one
 * line + optional action ("Open", "Undo"). Only for async outcomes or
 * undo, never for saves that are visible anyway:
 *
 *     toast.success('web is live after 1m 12s', { action: { label: 'Open', onClick } })
 */
function Toaster({ ...props }: ToasterProps) {
    const { appearance } = useAppearance();

    useFlashToast();

    return (
        <Sonner
            theme={appearance}
            className="toaster group"
            position="bottom-right"
            gap={8}
            icons={{
                success: <CircleCheck className="size-4 text-status-ok" />,
                error: <CircleAlert className="size-4 text-status-error" />,
                warning: <TriangleAlert className="size-4 text-status-progress" />,
                info: <Info className="size-4 text-muted-foreground" />,
                loading: (
                    <LoaderCircle className="size-4 text-status-progress motion-safe:animate-spin" />
                ),
            }}
            toastOptions={{
                classNames: {
                    toast: '!rounded-xl !border !border-border !bg-popover !text-popover-foreground !shadow-float !gap-2.5 !px-4 !py-3 !font-sans',
                    title: '!type-body !font-medium',
                    description: '!type-small !text-muted-foreground',
                    actionButton:
                        '!rounded-md !bg-primary !text-primary-foreground !h-7 !px-2.5 !text-[13px] !font-medium',
                    cancelButton:
                        '!rounded-md !bg-hover !text-foreground !h-7 !px-2.5 !text-[13px] !font-medium',
                },
            }}
            style={
                {
                    '--normal-bg': 'var(--popover)',
                    '--normal-text': 'var(--popover-foreground)',
                    '--normal-border': 'var(--border)',
                } as React.CSSProperties
            }
            {...props}
        />
    );
}

export { Toaster };
