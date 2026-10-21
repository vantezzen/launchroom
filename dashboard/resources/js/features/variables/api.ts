import { http } from '@inertiajs/react';
import { RequestFailed } from '@/features/projects/http';

export { RequestFailed };

type Method = 'get' | 'post' | 'put' | 'patch' | 'delete';

function messageFrom(data: unknown, status: number): string {
    try {
        const parsed = (typeof data === 'string' ? JSON.parse(data) : data) as {
            message?: unknown;
            errors?: Record<string, unknown>;
        };
        const first = parsed.errors
            ? Object.values(parsed.errors)[0]
            : undefined;

        if (Array.isArray(first) && typeof first[0] === 'string') {
            return first[0];
        }

        if (typeof parsed.message === 'string' && parsed.message !== '') {
            return parsed.message;
        }
    } catch {
        // Not JSON.
    }

    return status === 429
        ? 'Too many requests. Wait a moment and try again.'
        : 'The request failed. Try again in a moment.';
}

/**
 * JSON requests of the variables editor (every verb), through Inertia's
 * HTTP client so the XSRF cookie is sent. Errors carry the server's
 * user-facing message (validation or domain rule).
 */
export async function api<T>(
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
            response.data === '' || response.data === undefined
                ? null
                : JSON.parse(response.data)
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
