import type { UrlMethodPair } from '@inertiajs/core';
import { Link, router } from '@inertiajs/react';
import { usePasskeyVerify } from '@laravel/passkeys/react';
import { KeyRound } from 'lucide-react';
import type { ReactNode } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { home } from '@/routes';

/** The instance's host, shown quietly under auth forms (direction §9.15). */
export function instanceHost(): string {
    return typeof window === 'undefined' ? '' : window.location.host;
}

/**
 * Sign-in, password and invitation screens (direction §9.15): plain
 * background, the launchroom mark at 40 px, one title, one sentence, the
 * form, and the instance address in mono-small at the bottom. No
 * illustration, no starter-kit filler.
 */
export function AuthFrame({
    title,
    description,
    media,
    children,
    footer,
    className,
}: {
    title: ReactNode;
    description?: ReactNode;
    /** Replaces the logo mark (e.g. the team's avatar on an invitation). */
    media?: ReactNode;
    children: ReactNode;
    /** Quiet line under the form ("Accounts are invite-only."). */
    footer?: ReactNode;
    className?: string;
}) {
    return (
        <div className="flex min-h-svh flex-col bg-background">
            <main className="flex flex-1 flex-col items-center justify-center px-4 py-12 sm:px-6">
                <div
                    className={cn(
                        'flex w-full max-w-[360px] flex-col gap-8',
                        className,
                    )}
                >
                    <div className="flex flex-col items-center gap-6 text-center">
                        {media ?? (
                            <Link
                                href={home()}
                                className="rounded-[10px] outline-none focus-visible:ring-2 focus-visible:ring-signal focus-visible:ring-offset-2"
                            >
                                <AppLogoIcon className="size-10 text-foreground" />
                                <span className="sr-only">launchroom</span>
                            </Link>
                        )}
                        <div className="flex flex-col gap-2">
                            <h1 className="type-title">{title}</h1>
                            {description && (
                                <p className="type-body text-balance text-muted-foreground">
                                    {description}
                                </p>
                            )}
                        </div>
                    </div>
                    {children}
                    {footer && (
                        <div className="text-center type-small text-muted-foreground">
                            {footer}
                        </div>
                    )}
                </div>
            </main>
            <footer className="pb-6 text-center type-mono-small text-muted-foreground">
                {instanceHost()}
            </footer>
        </div>
    );
}

/** "or" between two ways to do the same thing, in sentence case. */
export function OrDivider({ children = 'or' }: { children?: ReactNode }) {
    return (
        <div className="flex items-center gap-3 type-small text-muted-foreground">
            <span className="h-px flex-1 bg-border" />
            {children}
            <span className="h-px flex-1 bg-border" />
        </div>
    );
}

/** Calm success/info line above a form ("We sent a reset link"). */
export function AuthNotice({ children }: { children: ReactNode }) {
    return (
        <p
            role="status"
            className="rounded-lg bg-(--status-ok-soft) px-3 py-2 text-center type-small text-status-ok"
        >
            {children}
        </p>
    );
}

/**
 * Passkey first (direction §9.15). Renders nothing when the browser can't
 * do passkeys, so the email form stands alone.
 */
export function PasskeySignIn({
    after,
    routes,
    label = 'Sign in with a passkey',
}: {
    after?: ReactNode;
    /** Other passkey endpoints (password confirmation). */
    routes?: { options: UrlMethodPair; submit: UrlMethodPair };
    label?: string;
}) {
    const { verify, isLoading, error, isSupported } = usePasskeyVerify({
        ...(routes && {
            routes: { options: routes.options.url, submit: routes.submit.url },
        }),
        onSuccess: (response) => {
            router.visit(response.redirect ?? '/dashboard');
        },
    });

    if (!isSupported) {
        return null;
    }

    return (
        <>
            <div className="grid gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="lg"
                    className="w-full"
                    onClick={verify}
                    disabled={isLoading}
                >
                    {isLoading ? (
                        <Spinner />
                    ) : (
                        <KeyRound className="size-4" aria-hidden="true" />
                    )}
                    {isLoading ? 'Waiting for your passkey…' : label}
                </Button>
                {error && (
                    <InputError message={error} className="text-center" />
                )}
            </div>
            {after}
        </>
    );
}
