import { Check, ChevronDown, TriangleAlert } from 'lucide-react';
import { createContext, useContext, useState } from 'react';
import type { ComponentProps, ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { ProgressRing } from '@/components/progress-ring';
import { MetaDivider } from '@/components/patterns/meta-line';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
                    : 'sticky bottom-(--phone-nav-h) mt-auto',
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
            {busy && <ProgressRing tone="current" className="size-3.5" />}
            {children}
        </button>
    );
}

export type SaveChoice = {
    id: string;
    label: string;
    /** One quiet line: what happens, or why it can't run now. */
    hint: string;
    disabled?: boolean;
    onSelect: () => void;
};

/**
 * The floating bar's Save as a split button (Laravel Cloud "Save ▾"): the
 * left half runs the default choice, the chevron lists every choice with a
 * line saying what it does. A disabled choice stays listed with its reason.
 */
export function FloatingSaveSplit({
    choices,
    defaultId,
    busy = false,
}: {
    choices: SaveChoice[];
    defaultId: string;
    busy?: boolean;
}) {
    const main =
        choices.find((choice) => choice.id === defaultId) ?? choices[0];

    return (
        <span className="inline-flex items-center rounded-lg bg-background text-foreground">
            <FloatingBarButton
                variant="primary"
                className="rounded-r-none pr-2.5"
                busy={busy}
                disabled={busy || main.disabled}
                onClick={main.onSelect}
            >
                {main.label}
            </FloatingBarButton>
            <span aria-hidden="true" className="h-4 w-px bg-foreground/15" />
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <FloatingBarButton
                        variant="primary"
                        aria-label="More ways to save"
                        className="rounded-l-none px-2"
                        disabled={busy}
                    >
                        <ChevronDown aria-hidden="true" className="size-3.5" />
                    </FloatingBarButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" side="top" className="w-72">
                    {choices.map((choice) => (
                        <DropdownMenuItem
                            key={choice.id}
                            disabled={choice.disabled}
                            onSelect={choice.onSelect}
                            className="items-start data-[disabled]:opacity-100"
                        >
                            <Check
                                aria-hidden="true"
                                className={cn(
                                    'mt-0.5',
                                    choice.id !== main.id && 'invisible',
                                )}
                            />
                            <span className="flex flex-col">
                                <span
                                    className={cn(
                                        choice.disabled &&
                                            'text-muted-foreground',
                                    )}
                                >
                                    {choice.label}
                                </span>
                                <span className="type-small text-muted-foreground">
                                    {choice.hint}
                                </span>
                            </span>
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>
        </span>
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
    /** Replaces "2 changes" ("4 changes in 2 services"). */
    countLabel?: string;
    /** Consequence in a few words: "api will restart". */
    summary?: string;
    /** Left out where discarding happens in the review instead. */
    onDiscard?: () => void;
    /** Opens the full review (the staged changes sheet). */
    onReview?: () => void;
    /** Applies the staged changes (the affected services redeploy). */
    onDeploy: () => void;
    deploying?: boolean;
    deployLabel?: string;
    /** Applying needs a permission the viewer lacks: explain instead. */
    disabledReason?: string;
    placement?: 'page' | 'inline';
};

/**
 * "4 changes in 2 services, api will restart, Review, Apply" for staged
 * changes (direction §5.7, D75, D109). The count uses the staged (dashed)
 * cue and says Apply's scope.
 */
export function StagedBar({
    count,
    countLabel,
    summary,
    onDiscard,
    onReview,
    onDeploy,
    deploying = false,
    deployLabel = 'Apply',
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
                    {countLabel ??
                        (count === 1 ? '1 change' : `${count} changes`)}
                </span>
                {summary && (
                    <span className="hidden min-w-0 items-center gap-2 type-body text-background/70 sm:inline-flex">
                        <MetaDivider className="bg-background/30" />
                        <span className="truncate">{summary}</span>
                    </span>
                )}
            </span>
            {onReview && (
                <FloatingBarButton onClick={onReview}>Review</FloatingBarButton>
            )}
            {onDiscard && (
                <FloatingBarButton onClick={onDiscard} disabled={deploying}>
                    Discard
                </FloatingBarButton>
            )}
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
