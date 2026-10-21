import { http } from '@inertiajs/react';

/**
 * JSON requests outside Inertia visits (pickers, previews, layout saves),
 * through Inertia's HTTP client so the XSRF cookie is sent.
 */
export class RequestFailed extends Error {
    constructor(
        message: string,
        public readonly status: number,
    ) {
        super(message);
    }
}

function messageFrom(body: string, status: number): string {
    try {
        const parsed = JSON.parse(body) as { message?: unknown };

        if (typeof parsed.message === 'string' && parsed.message !== '') {
            return parsed.message;
        }
    } catch {
        // Not JSON: fall through.
    }

    return status === 429
        ? 'Too many requests. Wait a moment and try again.'
        : 'The request failed. Try again in a moment.';
}

export async function requestJson<T>(
    method: 'get' | 'put' | 'post',
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

        return (response.data === '' ? null : JSON.parse(response.data)) as T;
    } catch (error) {
        const response = (
            error as { response?: { status: number; data: unknown } }
        ).response;

        if (response) {
            throw new RequestFailed(
                messageFrom(
                    typeof response.data === 'string'
                        ? response.data
                        : JSON.stringify(response.data),
                    response.status,
                ),
                response.status,
            );
        }

        throw error;
    }
}
