import { LoaderCircle, TriangleAlert } from 'lucide-react';
import { createContext, useContext, useState } from 'react';
import type { ComponentProps, ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { MetaDivider } from '@/components/patterns/meta-line';
import { cn } from '@/lib/utils';

type Slot = {
    node: HTMLElement | null;
    setNode: (node: HTMLElement | null) => void;
};

const FloatingSlotContext = createContext<Slot | null>(null);

/** Layout-level home of page floating bars (bottom centre of the content). */
export function FloatingBarProvider({ children }: { children: ReactNode }) {
    const [node, setNode] = useState<HTMLElement | null>(null);

    return (
        <FloatingSlotContext.Provider value={{ node, setNode }}>
            {children}
        </FloatingSlotContext.Provider>
    );
}

/**
 * Rendered once at the end of the layout's content column: sticks to the
 * bottom of the viewport while the page scrolls and sits at the bottom of
 * short pages.
 */
export function FloatingBarOutlet({ overlay = false }: { overlay?: boolean }) {
    const slot = useContext(FloatingSlotContext);

    return (
        <div
            ref={slot?.setNode}
            className={cn(
                'pointer-events-none z-30 flex justify-center px-4 pb-5 empty:hidden [&>*]:pointer-events-auto',
                // Full-bleed pages (canvas): float over the surface.
                overlay
                    ? 'absolute inset-x-0 bottom-0'
                    : 'sticky bottom-0 mt-auto',
            )}
        />
    );
}

type FloatingBarProps = {
    /** `page` floats at the bottom of the content area; `inline` at the bottom of the nearest positioned parent (a sheet). */
    placement?: 'page' | 'inline';
    /** Accessible name of the bar region. */
    label: string;
    children: ReactNode;
    className?: string;
};

/** Inverse floating bar (design-system §6): black in light, white in dark. */
export function FloatingBar({
    placement = 'page',
    label,
    children,
    className,
}: FloatingBarProps) {
    const slot = useContext(FloatingSlotContext);

    const bar = (
        <div
            role="region"
            aria-label={label}
            aria-live="polite"
            className={cn(
                'flex max-w-[calc(100vw-2rem)] items-center gap-1 rounded-xl bg-foreground py-1.5 pr-1.5 pl-3.5 text-background shadow-float',
                'animate-in duration-base ease-enter fade-in-0 slide-in-from-bottom-2',
                className,
            )}
        >
            {children}
        </div>
    );

    if (placement === 'inline') {
        return (
            <div className="pointer-events-none absolute inset-x-0 bottom-4 z-20 flex justify-center px-4 [&>*]:pointer-events-auto">
                {bar}
            </div>
        );
    }

    return slot?.node ? createPortal(bar, slot.node) : null;
}

/** A button inside a floating bar: `primary` is the inverted-inverse fill. */
export function FloatingBarButton({
    variant = 'quiet',
    busy = false,
    children,
    className,
    ...props
}: ComponentProps<'button'> & {
    variant?: 'primary' | 'quiet';
    busy?: boolean;
}) {
    return (
        <button
            type="button"
            className={cn(
                'inline-flex h-8 items-center gap-1.5 rounded-lg px-3 type-label whitespace-nowrap transition-colors duration-fast focus-visible:ring-2 focus-visible:ring-signal focus-visible:outline-none disabled:opacity-60',
                variant === 'primary'
                    ? 'bg-background text-foreground hover:bg-background/90'
                    : 'text-background/80 hover:bg-background/10 hover:text-background',
                className,
            )}
            {...props}
        >
            {busy && (
                <LoaderCircle
                    aria-hidden="true"
                    className="size-3.5 motion-safe:animate-spin"
                />
            )}
            {children}
        </button>
    );
}

type UnsavedBarProps = {
    /** Shown while the form is dirty. */
    open: boolean;
    onReset: () => void;
    onSave: () => void;
    saving?: boolean;
    message?: string;
    placement?: 'page' | 'inline';
};

/**
 * "⚠ Unsaved changes · Reset · Save" for instant settings (direction §5.7):
 * replaces per-section Save buttons.
 */
export function UnsavedBar({
    open,
    onReset,
    onSave,
    saving = false,
    message = 'Unsaved changes',
    placement = 'page',
}: UnsavedBarProps) {
    if (!open) {
        return null;
    }

    return (
        <FloatingBar placement={placement} label="Unsaved changes">
            <TriangleAlert
                aria-hidden="true"
                className="size-3.5 shrink-0 opacity-80"
            />
            <span className="mr-2 truncate type-label">{message}</span>
            <FloatingBarButton onClick={onReset} disabled={saving}>
                Reset
            </FloatingBarButton>
            <FloatingBarButton
                variant="primary"
                onClick={onSave}
                busy={saving}
                disabled={saving}
            >
                Save
            </FloatingBarButton>
        </FloatingBar>
    );
}

type StagedBarProps = {
    count: number;
    /** Consequence in a few words: "api will restart". */
    summary?: string;
    onDiscard: () => void;
    onDeploy: () => void;
    deploying?: boolean;
    deployLabel?: string;
    /** Deploy needs a permission the viewer lacks: explain instead. */
    disabledReason?: string;
    placement?: 'page' | 'inline';
};

/**
 * "2 changes · api will restart · Discard · Deploy" for staged changes
 * (direction §5.7). The count uses the staged (dashed) cue.
 */
export function StagedBar({
    count,
    summary,
    onDiscard,
    onDeploy,
    deploying = false,
    deployLabel = 'Deploy',
    disabledReason,
    placement = 'page',
}: StagedBarProps) {
    if (count === 0) {
        return null;
    }

    return (
        <FloatingBar placement={placement} label="Staged changes">
            <span className="flex min-w-0 items-center gap-2 pr-2">
                <span
                    aria-hidden="true"
                    className="size-2 shrink-0 rounded-full border-[1.5px] border-dashed border-background"
                />
                <span className="type-label whitespace-nowrap tabular-nums">
                    {count === 1 ? '1 change' : `${count} changes`}
                </span>
                {summary && (
                    <span className="hidden min-w-0 items-center gap-2 type-body text-background/70 sm:inline-flex">
                        <MetaDivider className="bg-background/30" />
                        <span className="truncate">{summary}</span>
                    </span>
                )}
            </span>
            <FloatingBarButton onClick={onDiscard} disabled={deploying}>
                Discard
            </FloatingBarButton>
            <FloatingBarButton
                variant="primary"
                onClick={onDeploy}
                busy={deploying}
                disabled={deploying || disabledReason !== undefined}
                title={disabledReason}
            >
                {deployLabel}
            </FloatingBarButton>
        </FloatingBar>
    );
}
