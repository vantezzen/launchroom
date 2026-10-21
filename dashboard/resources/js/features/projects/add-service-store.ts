import { useSyncExternalStore } from 'react';

/**
 * Open state of the add-service sheet, shared by the top bar's "+ Add" and
 * the canvas (empty state, context menu) without prop drilling through the
 * persistent layout.
 */
type AddServiceRequest = {
    open: boolean;
    position: { x: number; y: number } | null;
};

let state: AddServiceRequest = { open: false, position: null };
const listeners = new Set<() => void>();

function emit(next: AddServiceRequest): void {
    state = next;
    listeners.forEach((listener) => listener());
}

export function openAddService(
    position: { x: number; y: number } | null = null,
): void {
    emit({ open: true, position });
}

export function closeAddService(): void {
    emit({ open: false, position: state.position });
}

export function useAddService(): AddServiceRequest {
    return useSyncExternalStore(
        (listener) => {
            listeners.add(listener);

            return () => listeners.delete(listener);
        },
        () => state,
        () => state,
    );
}
