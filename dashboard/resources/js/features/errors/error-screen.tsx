import { Link, router } from '@inertiajs/react';
import { ArrowLeft, ChevronRight, RotateCw, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { CopyValue } from '@/components/copy-value';
import { Page } from '@/components/patterns/page';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { AuthFrame } from '@/features/auth/auth-frame';
import { openCommandPalette } from '@/features/command-palette/command-palette';
import { backoffMs } from '@/lib/connection';
import { modShortcut } from '@/lib/platform';
import { home, login } from '@/routes';
import { errorCopy } from './error-copy';

type ErrorPage = App.Data.Errors.ErrorPageData;

/** Back in history when there is somewhere to go back to, else home. */
function goBack(): void {
    if (window.history.length > 1) {
        window.history.back();
    } else {
        router.visit(home());
    }
}

/** A full load, so a new session and CSRF token come with it. */
function reloadAt(path: string | null): void {
    if (
        path === null ||
        path === window.location.pathname + window.location.search
    ) {
        window.location.reload();
    } else {
        window.location.assign(path);
    }
}

/**
 * Maintenance: ask `/up/version` with backoff and reload once launchroom
 * answers (the same probe as the reconnect banner, lib/connection.ts).
 */
function useReloadWhenBack(enabled: boolean): void {
    useEffect(() => {
        if (!enabled) {
            return;
        }

        let attempt = 0;
        let timer: number | undefined;
        let stopped = false;

        const probe = async () => {
            try {
                const response = await fetch('/up/version', {
                    cache: 'no-store',
                    headers: { Accept: 'application/json' },
                    signal: AbortSignal.timeout(5000),
                });

                if (response.ok && !stopped) {
                    window.location.reload();

                    return;
                }
            } catch {
                // Still away.
            }

            attempt += 1;

            if (!stopped) {
                timer = window.setTimeout(probe, backoffMs(attempt + 1));
            }
        };

        timer = window.setTimeout(probe, backoffMs(1));

        return () => {
            stopped = true;
            window.clearTimeout(timer);
        };
    }, [enabled]);
}

function PathBox({ path }: { path: string }) {
    return (
        <div className="rounded-lg border bg-recessed px-3 py-2.5 type-mono break-all">
            {path}
        </div>
    );
}

function Suggestions({
    items,
}: {
    items: App.Data.Errors.ErrorSuggestionData[];
}) {
    return (
        <section
            aria-labelledby="error-suggestions"
            className="flex flex-col gap-3"
        >
            <h2
                id="error-suggestions"
                className="type-label text-muted-foreground"
            >
                Did you mean
            </h2>
            <ul className="flex flex-col overflow-hidden rounded-xl border bg-card">
                {items.map((item) => (
                    <li key={item.href} className="border-b last:border-b-0">
                        <Link
                            href={item.href}
                            className="flex items-center gap-3 px-4 py-3 transition-colors duration-fast outline-none hover:bg-hover focus-visible:bg-hover"
                        >
                            <span className="flex min-w-0 flex-1 flex-col">
                                <span className="truncate type-body font-medium">
                                    {item.label}
                                </span>
                                {item.detail && (
                                    <span className="truncate type-small text-muted-foreground">
                                        {item.detail}
                                    </span>
                                )}
                            </span>
                            <ChevronRight
                                className="size-4 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                        </Link>
                    </li>
                ))}
            </ul>
        </section>
    );
}

/** The one forward action of a state that has one, else null. */
function primaryAction(
    error: ErrorPage,
    size: 'default' | 'lg' = 'default',
): ReactNode {
    const wide = size === 'lg' ? 'w-full' : undefined;

    switch (error.kind) {
        case 'expired':
            return (
                <Button
                    size={size}
                    className={wide}
                    onClick={() => reloadAt(error.reloadTo)}
                >
                    <RotateCw aria-hidden="true" />
                    Reload
                </Button>
            );
        case 'server_error':
        case 'too_many_requests':
            return (
                <Button
                    size={size}
                    className={wide}
                    onClick={() => window.location.reload()}
                >
                    <RotateCw aria-hidden="true" />
                    Try again
                </Button>
            );
        case 'unavailable':
            return (
                <p
                    role="status"
                    className="flex h-9 items-center gap-2 type-body text-muted-foreground"
                >
                    <Spinner className="size-4" />
                    Waiting for launchroom
                </p>
            );
        default:
            return null;
    }
}

/**
 * The error itself: the code, a human title, one sentence, the path, the
 * way forward, and for a missing resource the user's own nearby items
 * (docs/08 §24). No page container, so the design gallery can show it.
 */
export function ErrorContent({ error }: { error: ErrorPage }) {
    const copy = errorCopy(error, true);
    const [searchKey, setSearchKey] = useState('Ctrl K');
    const primary = primaryAction(error);

    // The modifier depends on the keyboard: only known in the browser.
    useEffect(() => setSearchKey(modShortcut('K')), []);

    return (
        <div className="flex w-full max-w-[520px] flex-col gap-8">
            <header className="flex flex-col gap-2">
                <p className="type-mono-small text-muted-foreground tabular-nums">
                    Error {error.status}
                </p>
                <h1 className="type-title text-balance">{copy.title}</h1>
                <p className="max-w-[65ch] type-body text-pretty text-muted-foreground">
                    {copy.text}
                </p>
            </header>

            <PathBox path={error.path} />

            {error.reference && (
                <div className="flex flex-col gap-2">
                    <span className="type-label text-muted-foreground">
                        Reference
                    </span>
                    <CopyValue value={error.reference} label="reference" />
                </div>
            )}

            <div className="flex flex-wrap items-center gap-2">
                {primary}
                <Button
                    variant={primary ? 'outline' : 'default'}
                    onClick={goBack}
                >
                    <ArrowLeft aria-hidden="true" />
                    Go back
                </Button>
                <Button variant="outline" asChild>
                    <Link href={home()}>Projects</Link>
                </Button>
                <Button
                    variant="ghost"
                    className="text-muted-foreground"
                    onClick={() => openCommandPalette()}
                >
                    <Search aria-hidden="true" />
                    Search
                    <kbd className="rounded-sm border px-1.5 font-sans type-small">
                        {searchKey}
                    </kbd>
                </Button>
            </div>

            {error.suggestions.length > 0 && (
                <Suggestions items={error.suggestions} />
            )}
        </div>
    );
}

/** `errors/show` signed in: inside the team shell. */
export function ErrorScreen({ error }: { error: ErrorPage }) {
    useReloadWhenBack(error.kind === 'unavailable');

    return (
        <Page size="narrow" className="sm:pt-[8vh]">
            <ErrorContent error={error} />
        </Page>
    );
}

/** The same page signed out: the auth frame, no shell, no search. */
export function SignedOutErrorScreen({ error }: { error: ErrorPage }) {
    const copy = errorCopy(error, false);
    const primary = primaryAction(error, 'lg');
    // After an expired session, Reload already leads to sign-in.
    const signIn = error.kind !== 'unavailable' && error.kind !== 'expired';

    useReloadWhenBack(error.kind === 'unavailable');

    return (
        <AuthFrame
            title={copy.title}
            description={copy.text}
            footer={
                <span className="type-mono-small tabular-nums">
                    Error {error.status}
                </span>
            }
        >
            <PathBox path={error.path} />
            {error.reference && (
                <CopyValue value={error.reference} label="reference" />
            )}
            <div className="flex flex-col gap-2">
                {primary && (
                    <div className="flex justify-center">{primary}</div>
                )}
                {signIn && (
                    <Button
                        variant={primary ? 'outline' : 'default'}
                        size="lg"
                        className="w-full"
                        asChild
                    >
                        <Link href={login()}>Sign in</Link>
                    </Button>
                )}
                <Button variant="ghost" className="w-full" onClick={goBack}>
                    <ArrowLeft aria-hidden="true" />
                    Go back
                </Button>
            </div>
        </AuthFrame>
    );
}
