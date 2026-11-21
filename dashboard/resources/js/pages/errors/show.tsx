import { Head, usePage } from '@inertiajs/react';
import { errorCopy } from '@/features/errors/error-copy';
import {
    ErrorScreen,
    SignedOutErrorScreen,
} from '@/features/errors/error-screen';
import { noLayout } from '@/lib/pages';

type Props = { error: App.Data.Errors.ErrorPageData };

/**
 * Every dashboard page error (docs/08 §24): 403, 404, 419, 429, 5xx.
 * Signed in it sits in the team shell, signed out in the auth frame.
 */
export default function ErrorPage({ error }: Props) {
    const signedIn = usePage().props.auth.user !== null;

    return (
        <>
            <Head title={errorCopy(error, signedIn).tab} />
            {signedIn ? (
                <ErrorScreen error={error} />
            ) : (
                <SignedOutErrorScreen error={error} />
            )}
        </>
    );
}

// Signed in: the default team layout, without a breadcrumb (an empty props
// object would read as "no layouts", so it names the prop).
ErrorPage.layout = (props: { auth: { user: unknown } }) =>
    props.auth.user ? { breadcrumbs: [] } : noLayout;
