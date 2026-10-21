import type { VisitOptions } from '@inertiajs/core';
import type { CSSProperties } from 'react';

/** Motion tokens (design-system §8), for JS-driven animation. */
export const motion = {
    fast: 120,
    base: 180,
    slow: 240,
    enter: 'cubic-bezier(0.2, 0.8, 0.2, 1)',
    exit: 'cubic-bezier(0.4, 0, 1, 1)',
} as const;

export function prefersReducedMotion(): boolean {
    return (
        typeof window !== 'undefined' &&
        window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true
    );
}

/**
 * Inertia visit defaults (app.tsx): page-level navigations run inside the
 * View Transitions API so the content cross-fades (CSS in app.css) while the
 * sidebar and top bar stay. Partial reloads, realtime refreshes, panels
 * that keep state, forms and prefetches swap instantly, and so does
 * everything with reduced motion.
 */
export function pageVisitOptions(
    _href: string,
    options: VisitOptions,
): VisitOptions {
    const method = (options.method ?? 'get').toLowerCase();
    const partial =
        (options.only?.length ?? 0) > 0 || (options.except?.length ?? 0) > 0;

    if (
        method !== 'get' ||
        partial ||
        options.preserveState === true ||
        options.async === true ||
        options.prefetch === true ||
        // Links always pass `false`; anything else was chosen by the caller.
        (options.viewTransition !== undefined &&
            options.viewTransition !== false) ||
        prefersReducedMotion()
    ) {
        return {};
    }

    return { viewTransition: true };
}

/**
 * Shared-element name for View Transitions (design-system §8): the same
 * name on the source (canvas node logo, deployment row) and the target
 * (panel header, deployment header) morphs one into the other.
 *
 *     <Logo style={sharedElement('svc', service.id)} />
 */
export function sharedElement(kind: string, id: string): CSSProperties {
    const ident = `${kind}-${id}`.replace(/[^a-zA-Z0-9_-]/g, '_');

    return { viewTransitionName: ident } as CSSProperties;
}
