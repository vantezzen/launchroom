import { useCallback, useLayoutEffect, useRef, useState } from 'react';

/** Width of an element, kept current with a ResizeObserver (0 until measured). */
export function useMeasuredWidth<T extends HTMLElement>(): [
    (node: T | null) => void,
    number,
] {
    const [width, setWidth] = useState(0);
    const observer = useRef<ResizeObserver | null>(null);
    const [node, setNode] = useState<T | null>(null);

    const ref = useCallback((element: T | null) => setNode(element), []);

    useLayoutEffect(() => {
        if (!node) {
            return;
        }

        setWidth(node.getBoundingClientRect().width);

        if (typeof ResizeObserver === 'undefined') {
            return;
        }

        observer.current = new ResizeObserver((entries) => {
            const entry = entries[0];

            if (entry) {
                setWidth(entry.contentRect.width);
            }
        });
        observer.current.observe(node);

        return () => observer.current?.disconnect();
    }, [node]);

    return [ref, width];
}
