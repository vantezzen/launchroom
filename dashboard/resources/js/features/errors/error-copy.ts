type ErrorPage = App.Data.Errors.ErrorPageData;

export type ErrorCopy = {
    /** The browser tab's title. */
    tab: string;
    title: string;
    text: string;
};

const MISSING: Record<App.Enums.Http.MissingResource, ErrorCopy> = {
    project: {
        tab: 'Project not found',
        title: 'No project at this address',
        text: 'It may have been deleted, or the link belongs to a team you’re not in.',
    },
    environment: {
        tab: 'Environment not found',
        title: 'No environment at this address',
        text: 'It may have been deleted from this project.',
    },
    service: {
        tab: 'Service not found',
        title: 'No service at this address',
        text: 'It may have been removed from this environment.',
    },
    server: {
        tab: 'Server not found',
        title: 'No server at this address',
        text: 'It may have been removed, or the link belongs to a team you’re not in.',
    },
};

function seconds(value: number): string {
    if (value >= 120) {
        return `${Math.ceil(value / 60)} minutes`;
    }

    return value === 1 ? '1 second' : `${value} seconds`;
}

/**
 * What an error page says (docs/08 §24). The same words whether a row is
 * missing or out of reach: the server never tells the two apart.
 */
export function errorCopy(error: ErrorPage, signedIn: boolean): ErrorCopy {
    switch (error.kind) {
        case 'not_found':
            return error.missing
                ? MISSING[error.missing]
                : {
                      tab: 'Page not found',
                      title: 'This page doesn’t exist',
                      text: 'The link may be old, or the address has a typo.',
                  };
        case 'forbidden':
            return {
                tab: 'No access',
                title: 'You don’t have access to this page',
                text: signedIn
                    ? 'Your role in this team doesn’t include it. A team owner or admin can change that.'
                    : 'Sign in with an account that has access to it.',
            };
        case 'expired':
            return {
                tab: 'Session expired',
                title: 'Your session expired',
                text: signedIn
                    ? 'Reload to continue where you were. A form you were filling in needs to be sent again.'
                    : 'Reload the page and sign in again.',
            };
        case 'too_many_requests':
            return {
                tab: 'Too many requests',
                title: 'Too many requests',
                text:
                    error.retryAfter !== null
                        ? `launchroom paused your requests for a moment. Try again in ${seconds(error.retryAfter)}.`
                        : 'launchroom paused your requests for a moment. Try again shortly.',
            };
        case 'server_error':
            return {
                tab: 'Something went wrong',
                title: 'Something went wrong',
                text: 'launchroom ran into an error and logged it. Try again, and if it keeps happening, the reference below finds it in the logs.',
            };
        case 'unavailable':
            return {
                tab: 'Maintenance',
                title: 'launchroom is down for maintenance',
                text: 'This page reloads by itself as soon as launchroom is back.',
            };
    }
}
