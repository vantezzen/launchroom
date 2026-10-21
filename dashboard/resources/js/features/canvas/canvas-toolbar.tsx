import { useReactFlow, useViewport } from '@xyflow/react';
import { LayoutGrid, Maximize, Minus, Plus, Search } from 'lucide-react';
import type { ComponentProps, ReactNode } from 'react';
import { openCommandPalette } from '@/features/command-palette/command-palette';
import { prefersReducedMotion } from '@/lib/motion';
import { cn } from '@/lib/utils';

function ToolButton({
    label,
    children,
    className,
    ...props
}: ComponentProps<'button'> & { label: string; children: ReactNode }) {
    return (
        <button
            type="button"
            aria-label={label}
            title={label}
            className={cn(
                'inline-flex h-8 min-w-8 items-center justify-center gap-1.5 rounded-lg px-2 type-label text-muted-foreground transition-colors duration-fast hover:bg-hover hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-40',
                className,
            )}
            {...props}
        >
            {children}
        </button>
    );
}

function Divider() {
    return <span aria-hidden="true" className="mx-0.5 h-4 w-px bg-border" />;
}

/**
 * The floating canvas toolbar (direction §9.4, Laravel Cloud): Add, Fit,
 * Tidy, zoom and ⌘K, bottom centre of the visible canvas; with staged
 * changes it ends in "N changes · Review & deploy" (design-system §13.9).
 */
export function CanvasToolbar({
    onAdd,
    onTidy,
    staged,
    className,
}: {
    onAdd?: () => void;
    onTidy?: () => void;
    /** The staged changes' "Review & deploy" (StagedToolbarButton), last. */
    staged?: ReactNode;
    className?: string;
}) {
    const flow = useReactFlow();
    const { zoom } = useViewport();
    const duration = prefersReducedMotion() ? 0 : 180;

    return (
        <div
            role="toolbar"
            aria-label="Canvas"
            className={cn(
                'pointer-events-auto flex items-center gap-0.5 rounded-xl border bg-card p-1 shadow-float',
                className,
            )}
        >
            {onAdd && (
                <>
                    <ToolButton
                        label="Add a service"
                        onClick={onAdd}
                        className="text-foreground"
                    >
                        <Plus aria-hidden="true" className="size-4" />
                        Add
                    </ToolButton>
                    <Divider />
                </>
            )}
            <ToolButton
                label="Fit to screen"
                onClick={() =>
                    void flow.fitView({ padding: 0.25, maxZoom: 1, duration })
                }
            >
                <Maximize aria-hidden="true" className="size-4" />
            </ToolButton>
            {onTidy && (
                <ToolButton label="Tidy up layout" onClick={onTidy}>
                    <LayoutGrid aria-hidden="true" className="size-4" />
                </ToolButton>
            )}
            <Divider />
            <ToolButton
                label="Zoom out"
                onClick={() => void flow.zoomOut({ duration })}
            >
                <Minus aria-hidden="true" className="size-4" />
            </ToolButton>
            <span
                aria-live="polite"
                className="w-11 text-center type-small text-muted-foreground tabular-nums"
            >
                {Math.round(zoom * 100)}%
            </span>
            <ToolButton
                label="Zoom in"
                onClick={() => void flow.zoomIn({ duration })}
            >
                <Plus aria-hidden="true" className="size-4" />
            </ToolButton>
            <Divider />
            <ToolButton
                // The visible text (⌘K) is part of the name (axe
                // label-content-name-mismatch).
                label="Search and commands ⌘K"
                onClick={() => openCommandPalette()}
            >
                <Search aria-hidden="true" className="size-4" />
                <kbd className="font-sans type-small">⌘K</kbd>
            </ToolButton>
            {staged}
        </div>
    );
}
