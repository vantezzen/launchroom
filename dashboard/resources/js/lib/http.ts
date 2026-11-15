import { http } from '@inertiajs/react';

type Method = 'get' | 'post' | 'put' | 'patch' | 'delete';

/** A JSON request the server answered with an error status. */
export class RequestFailed extends Error {
    constructor(
        message: string,
        public readonly status: number,
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

/**
 * The user-facing sentence of an error response: the first validation
 * error, else the server's message, else one per status.
 */
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
 * JSON requests outside Inertia visits, through Inertia's HTTP client so
 * the XSRF cookie is sent. Errors are `RequestFailed` with `messageFrom`.
 */
export async function requestJson<T>(
    method: Method,
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
            response.data === '' ||
            response.data === undefined ||
            response.data === null
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
            throw new RequestFailed(
                messageFrom(response.data, response.status),
                response.status,
            );
        }

        throw error;
    }
}

/** The XSRF header value for raw `fetch` calls (keepalive, binary bodies). */
export function xsrfToken(): string | null {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : null;
}
