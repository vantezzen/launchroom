import { MiniMap, useStore } from '@xyflow/react';
import type { Node } from '@xyflow/react';
import { useState } from 'react';

const MAP_KEY = 'launchroom.canvas.map';

/** Past this many services the map shows even when everything fits. */
const MANY_SERVICES = 8;

/** The zoom the canvas must still be readable at to fit without a map. */
const READABLE_ZOOM = 0.6;

type MapChoice = 'auto' | 'shown' | 'hidden';

function readChoice(): MapChoice {
    try {
        const value = window.localStorage.getItem(MAP_KEY);

        return value === 'shown' || value === 'hidden' ? value : 'auto';
    } catch {
        return 'auto';
    }
}

/**
 * Whether the canvas overview shows (docs/08 §13): only when the services
 * don't fit the visible canvas at a readable zoom, or there are many of
 * them. The toolbar's map button overrides that per browser.
 */
export function useCanvasMap(services: number): {
    shown: boolean;
    toggle: () => void;
} {
    const [choice, setChoice] = useState<MapChoice>(readChoice);
    const overflows = useStore((state) => {
        if (state.width === 0 || state.height === 0) {
            return false;
        }

        let minX = Infinity;
        let minY = Infinity;
        let maxX = -Infinity;
        let maxY = -Infinity;

        state.nodeLookup.forEach((node) => {
            const { x, y } = node.internals.positionAbsolute;
            const width = node.measured.width ?? 0;
            const height = node.measured.height ?? 0;
            minX = Math.min(minX, x);
            minY = Math.min(minY, y);
            maxX = Math.max(maxX, x + width);
            maxY = Math.max(maxY, y + height);
        });

        if (minX === Infinity) {
            return false;
        }

        return (
            (maxX - minX) * READABLE_ZOOM > state.width ||
            (maxY - minY) * READABLE_ZOOM > state.height
        );
    });
    const auto = services > MANY_SERVICES || overflows;
    const shown = choice === 'auto' ? auto : choice === 'shown' && services > 0;

    const toggle = () => {
        const next: MapChoice = shown ? 'hidden' : 'shown';
        setChoice(next);

        try {
            window.localStorage.setItem(MAP_KEY, next);
        } catch {
            // Private windows: the choice lasts for this page only.
        }
    };

    return { shown, toggle };
}

/** The canvas overview, bottom right on wide screens. */
export function CanvasMap<N extends Node>({
    nodeColor,
    nodeStrokeColor,
}: {
    nodeColor: (node: N) => string;
    nodeStrokeColor: (node: N) => string;
}) {
    return (
        <MiniMap<N>
            position="bottom-right"
            pannable
            zoomable
            ariaLabel="Canvas overview"
            nodeColor={nodeColor}
            nodeStrokeColor={nodeStrokeColor}
            nodeStrokeWidth={6}
            nodeBorderRadius={14}
            maskColor="color-mix(in oklab, var(--background) 55%, transparent)"
            maskStrokeColor="var(--signal)"
            maskStrokeWidth={1.5}
            className="hidden lg:block"
            style={{ width: 168, height: 108 }}
        />
    );
}
