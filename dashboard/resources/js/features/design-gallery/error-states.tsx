import { ErrorContent } from '@/features/errors/error-screen';

/**
 * Every dashboard error page (docs/08 §24, D109) with sample data, as it
 * sits in the shell. Signed out the same content uses the auth frame.
 */

type ErrorPage = App.Data.Errors.ErrorPageData;

const base: ErrorPage = {
    status: 404,
    kind: 'not_found',
    path: '/settings/notificatons',
    missing: null,
    suggestions: [],
    reference: null,
    retryAfter: null,
    reloadTo: null,
};

const SAMPLES: { name: string; error: ErrorPage }[] = [
    { name: 'Unknown page', error: base },
    {
        name: 'Missing service, with the environment’s services',
        error: {
            ...base,
            path: '/p/prj_01M59SDW7FHB3NNET7S9DHK1EC/env_01M59SDWA27ERZHV49HK5HD01S/s/svc_01M59SDWEBFE7ZJSK3R6EXQDG4',
            missing: 'service',
            suggestions: [
                { label: 'api', href: '#api', detail: 'production' },
                { label: 'web', href: '#web', detail: 'production' },
                { label: 'worker', href: '#worker', detail: 'production' },
            ],
        },
    },
    {
        name: 'Missing project, with the team’s projects',
        error: {
            ...base,
            path: '/p/prj_01M59SDW7FHB3NNET7S9DHK1ED',
            missing: 'project',
            suggestions: [
                { label: 'Shop', href: '#shop', detail: null },
                { label: 'Acme API', href: '#acme', detail: null },
            ],
        },
    },
    {
        name: 'No access',
        error: { ...base, status: 403, kind: 'forbidden', path: '/logs' },
    },
    {
        name: 'Session expired',
        error: {
            ...base,
            status: 419,
            kind: 'expired',
            path: '/settings/profile',
            reloadTo: '/settings/profile',
        },
    },
    {
        name: 'Too many requests',
        error: {
            ...base,
            status: 429,
            kind: 'too_many_requests',
            path: '/projects/new',
            retryAfter: 40,
        },
    },
    {
        name: 'Server error, with its reference',
        error: {
            ...base,
            status: 500,
            kind: 'server_error',
            path: '/p/prj_01M59SDW7FHB3NNET7S9DHK1EC/settings',
            reference: '0b5e8f9e-3c1d-4f8a-9a51-6d2c4e7b1a90',
        },
    },
    {
        name: 'Maintenance',
        error: { ...base, status: 503, kind: 'unavailable', path: '/projects' },
    },
];

export default function ErrorStates() {
    return (
        <div id="error-pages" className="grid gap-6 lg:grid-cols-2">
            {SAMPLES.map(({ name, error }) => (
                <figure key={name} className="flex flex-col gap-2">
                    <figcaption className="type-small text-muted-foreground">
                        {name}
                    </figcaption>
                    <div className="rounded-xl border bg-background p-6 sm:p-8">
                        <ErrorContent error={error} />
                    </div>
                </figure>
            ))}
        </div>
    );
}
