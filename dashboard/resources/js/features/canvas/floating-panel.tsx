import type { ReactNode } from 'react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { cn } from '@/lib/utils';

const MARGIN = 12;

/**
 * A popover anchored at a viewport point (an edge label, a drop target).
 * Escape and outside clicks close it; focus moves in and returns on close.
 */
export function FloatingPanel({
    anchor,
    label,
    onClose,
    children,
    className,
}: {
    anchor: { x: number; y: number };
    label: string;
    onClose: () => void;
    children: ReactNode;
    className?: string;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const [position, setPosition] = useState({ left: anchor.x, top: anchor.y });

    useLayoutEffect(() => {
        const element = ref.current;

        if (!element) {
            return;
        }

        const place = () => {
            const { width, height } = element.getBoundingClientRect();
            const left = Math.min(
                Math.max(MARGIN, anchor.x - width / 2),
                window.innerWidth - width - MARGIN,
            );
            const below = anchor.y + 8;
            const top =
                below + height > window.innerHeight - MARGIN
                    ? Math.max(MARGIN, anchor.y - height - 8)
                    : below;

            setPosition({ left, top });
        };

        place();
        // The content grows once previews load: keep it on screen.
        const observer = new ResizeObserver(place);
        observer.observe(element);

        return () => observer.disconnect();
    }, [anchor.x, anchor.y]);

    useEffect(() => {
        const previous = document.activeElement as HTMLElement | null;
        const focusable = ref.current?.querySelector<HTMLElement>(
            'button:not([disabled]), [href], input, select, [tabindex]:not([tabindex="-1"])',
        );
        focusable?.focus();

        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.stopPropagation();
                onClose();
            }
        };
        const onPointer = (event: PointerEvent) => {
            if (ref.current && !ref.current.contains(event.target as Node)) {
                onClose();
            }
        };

        document.addEventListener('keydown', onKey, true);
        document.addEventListener('pointerdown', onPointer, true);

        return () => {
            document.removeEventListener('keydown', onKey, true);
            document.removeEventListener('pointerdown', onPointer, true);
            previous?.focus?.();
        };
    }, [onClose]);

    return createPortal(
        <div
            ref={ref}
            role="dialog"
            aria-label={label}
            className={cn(
                'fixed z-50 w-[22rem] max-w-[calc(100vw-24px)] rounded-lg border bg-popover p-4 text-popover-foreground shadow-md',
                'animate-in duration-150 fade-in-0 zoom-in-95 motion-reduce:animate-none',
                className,
            )}
            style={position}
        >
            {children}
        </div>,
        document.body,
    );
}
