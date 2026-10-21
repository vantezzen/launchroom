import * as SheetPrimitive from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type SheetSize = 'sm' | 'md' | 'lg';

const widths: Record<SheetSize, string> = {
    sm: 'sm:w-[480px]',
    md: 'sm:w-[560px]',
    lg: 'sm:w-[720px]',
};

type SidePanelProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: ReactNode;
    /** One line under the title. */
    description?: ReactNode;
    /** Logo tile or icon before the title. */
    media?: ReactNode;
    /** Status pill or chips after the title. */
    status?: ReactNode;
    /** 480 / 560 / 720 px on desktop; phones are always full screen. */
    size?: SheetSize;
    /** Sticky footer (actions) or a floating `UnsavedBar`. */
    footer?: ReactNode;
    children: ReactNode;
    className?: string;
};

/**
 * Right sheet (design-system §6, direction §5.7): float level, 16 px
 * radius, 12 px inset from the viewport on desktop, full screen on phones.
 * Header with media, title, status and close; the body scrolls; sections
 * inside use `SheetSection`.
 */
export function SidePanel({
    open,
    onOpenChange,
    title,
    description,
    media,
    status,
    size = 'md',
    footer,
    children,
    className,
}: SidePanelProps) {
    return (
        <SheetPrimitive.Root open={open} onOpenChange={onOpenChange}>
            <SheetPrimitive.Portal>
                <SheetPrimitive.Overlay className="fixed inset-0 z-50 bg-black/20 data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:fade-in-0 dark:bg-black/50" />
                <SheetPrimitive.Content
                    className={cn(
                        'fixed inset-0 z-50 flex flex-col overflow-hidden bg-card text-card-foreground shadow-float outline-none',
                        'sm:inset-y-3 sm:right-3 sm:left-auto sm:max-w-[calc(100vw-1.5rem)] sm:rounded-2xl sm:border',
                        'data-[state=open]:animate-in data-[state=open]:duration-slow data-[state=open]:ease-enter data-[state=open]:fade-in-0 data-[state=open]:slide-in-from-right-8',
                        'data-[state=closed]:animate-out data-[state=closed]:duration-base data-[state=closed]:ease-exit data-[state=closed]:fade-out-0 data-[state=closed]:slide-out-to-right-8',
                        widths[size],
                        className,
                    )}
                >
                    <div className="flex shrink-0 items-start gap-3 border-b px-5 py-4">
                        {media && <div className="shrink-0">{media}</div>}
                        <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                            <div className="flex min-w-0 flex-wrap items-center gap-2">
                                <SheetPrimitive.Title className="truncate type-heading">
                                    {title}
                                </SheetPrimitive.Title>
                                {status}
                            </div>
                            {description ? (
                                <SheetPrimitive.Description className="type-body text-muted-foreground">
                                    {description}
                                </SheetPrimitive.Description>
                            ) : (
                                <SheetPrimitive.Description className="sr-only">
                                    {typeof title === 'string'
                                        ? title
                                        : 'Details'}
                                </SheetPrimitive.Description>
                            )}
                        </div>
                        <SheetPrimitive.Close className="-mr-1.5 flex size-8 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors duration-fast hover:bg-hover hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                            <X aria-hidden="true" className="size-4" />
                            <span className="sr-only">Close</span>
                        </SheetPrimitive.Close>
                    </div>
                    {/* Not positioned: an inline UnsavedBar floats over the sheet, not the scroll content. */}
                    <div className="min-h-0 flex-1 overflow-y-auto pb-16">
                        <div className="flex flex-col [&>section+section]:border-t">
                            {children}
                        </div>
                    </div>
                    {footer && (
                        <div className="relative shrink-0 border-t px-5 py-3">
                            {footer}
                        </div>
                    )}
                </SheetPrimitive.Content>
            </SheetPrimitive.Portal>
        </SheetPrimitive.Root>
    );
}

/**
 * A section inside a sheet: heading, optional one-line description and a
 * right-aligned control (switch, button), then its content. Sections are
 * separated by hairlines.
 */
export function SheetSection({
    title,
    description,
    control,
    children,
    className,
}: {
    title?: ReactNode;
    description?: ReactNode;
    control?: ReactNode;
    children?: ReactNode;
    className?: string;
}) {
    return (
        <section className={cn('flex flex-col gap-3 px-5 py-5', className)}>
            {(title || control) && (
                <div className="flex items-start justify-between gap-4">
                    <div className="flex min-w-0 flex-col gap-0.5">
                        {title && <h3 className="type-label">{title}</h3>}
                        {description && (
                            <p className="type-small text-muted-foreground">
                                {description}
                            </p>
                        )}
                    </div>
                    {control && <div className="shrink-0">{control}</div>}
                </div>
            )}
            {children}
        </section>
    );
}
