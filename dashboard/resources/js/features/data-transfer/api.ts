import { http } from '@inertiajs/react';

export type ServiceTransfers = App.Data.Transfers.ServiceTransfersData;
export type Transfer = App.Data.Transfers.TransferData;
export type ImportRun = App.Data.Transfers.ImportRunData;
export type ExportRun = App.Data.Transfers.ExportRunData;
export type Inspection = Omit<
    App.Data.Transfers.ImportInspectionData,
    'status'
> & {
    status: 'pending' | 'done' | 'failed';
};

export class TransferRequestFailed extends Error {
    constructor(
        message: string,
        public readonly status: number,
        /** 423: the server wants the password confirmed first. */
        public readonly requiresPassword = false,
    ) {
        super(message);
    }
}

function parse(data: unknown): Record<string, unknown> | null {
    try {
        const parsed = typeof data === 'string' ? JSON.parse(data) : data;

        return parsed !== null && typeof parsed === 'object'
            ? (parsed as Record<string, unknown>)
            : null;
    } catch {
        return null;
    }
}

export function messageFrom(data: unknown, status: number): string {
    const parsed = parse(data);
    const errors = parsed?.errors as Record<string, unknown> | undefined;
    const first = errors ? Object.values(errors)[0] : undefined;

    if (Array.isArray(first) && typeof first[0] === 'string') {
        return first[0];
    }

    if (typeof parsed?.message === 'string' && parsed.message !== '') {
        return parsed.message;
    }

    if (status === 403) {
        return 'You don’t have permission to do this.';
    }

    return status === 429
        ? 'Too many requests. Wait a moment and try again.'
        : 'The request failed. Try again in a moment.';
}

/**
 * JSON requests of the Move data section (docs/14 §12.6), through
 * Inertia's HTTP client so the XSRF cookie is sent.
 */
export async function transfersApi<T>(
    method: 'get' | 'post' | 'delete',
    url: string,
    data?: unknown,
    signal?: AbortSignal,
): Promise<T> {
    try {
        const response = await http.getClient().request({
            method,
            url,
            data,
            signal,
            headers: { Accept: 'application/json' },
        });

        return (
            response.data === '' || response.data === undefined
                ? null
                : typeof response.data === 'string'
                  ? JSON.parse(response.data)
                  : response.data
        ) as T;
    } catch (error) {
        const response = (
            error as { response?: { status: number; data: unknown } }
        ).response;

        if (response) {
            throw new TransferRequestFailed(
                messageFrom(response.data, response.status),
                response.status,
                response.status === 423 &&
                    parse(response.data)?.requiresPassword === true,
            );
        }

        throw error;
    }
}
