import { useSyncExternalStore } from 'react';

export type AddChoice =
    | 'github'
    | 'catalog'
    | 'image'
    | 'database'
    | 'worker'
    | 'cron';

/**
 * The add-service sheet's request, shared by the top bar's "+ Add" and the
 * canvas (empty state, context menu) without prop drilling through the
 * persistent layout: where the new node goes and which choice opens first.
 */
type AddServiceRequest = {
    open: boolean;
    position: { x: number; y: number } | null;
    choice: AddChoice | null;
};

let state: AddServiceRequest = { open: false, position: null, choice: null };
const listeners = new Set<() => void>();

function emit(next: AddServiceRequest): void {
    state = next;
    listeners.forEach((listener) => listener());
}

export function openAddService({
    position = null,
    choice = null,
}: {
    position?: { x: number; y: number } | null;
    choice?: AddChoice | null;
} = {}): void {
    emit({ open: true, position, choice });
}

export function closeAddService(): void {
    emit({ ...state, open: false });
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
