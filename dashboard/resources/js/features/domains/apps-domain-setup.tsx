import { router } from '@inertiajs/react';
import { Globe, Zap } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { useCallback, useEffect, useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { ChoiceTiles } from '@/components/patterns/choice-tiles';
import { Status } from '@/components/status';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { requestJson, RequestFailed } from '@/features/projects/http';
import { cn } from '@/lib/utils';
import onboardingRoutes from '@/routes/onboarding';
import {
    check as checkDomain,
    confirm as confirmDomain,
} from '@/routes/onboarding/domain';
import { DnsCheckResult } from './dns-check-result';
import { PlatformDnsRecords } from './platform-dns-records';

type DnsCheck = App.Data.Domains.DnsCheckData;

const DNS_POLL_MS = 10_000;

/**
 * Polls the wildcard check every 10 s while it isn't green (flows §1:
 * "DNS turns green by itself"); `run` is "Check now".
 */
function useDnsWatch(domain: string) {
    const [result, setResult] = useState<DnsCheck | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [checking, setChecking] = useState(false);

    const run = useCallback(async () => {
        setChecking(true);

        try {
            setResult(
                await requestJson<DnsCheck>('post', checkDomain().url, {
                    domain,
                }),
            );
            setError(null);
        } catch (failure) {
            setError(
                failure instanceof RequestFailed
                    ? failure.message
                    : 'The DNS check failed.',
            );
        } finally {
            setChecking(false);
        }
    }, [domain]);

    useEffect(() => {
        void run();
    }, [run]);

    useEffect(() => {
        if (result?.status === 'ok') {
            return;
        }

        const timer = window.setInterval(() => {
            if (document.visibilityState === 'visible') {
                void run();
            }
        }, DNS_POLL_MS);

        return () => window.clearInterval(timer);
    }, [result?.status, run]);

    return { result, error, checking, run };
}

/** "Domain for your apps": saves `apps_domain` (PUT onboarding/domain). */
function AppsDomainForm({
    domain,
    dashboardHost,
}: {
    domain: string | null;
    dashboardHost: string;
}) {
    const [value, setValue] = useState(domain ?? '');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const normalized = value.trim().replace(/^\*\./, '').toLowerCase();

    const save = (event: FormEvent) => {
        event.preventDefault();
        router.put(
            onboardingRoutes.domain().url,
            { appsDomain: normalized === '' ? null : normalized },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setSaving(true),
                onError: (errors) =>
                    setError(errors.appsDomain ?? 'Saving failed.'),
                onSuccess: () => setError(null),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <form onSubmit={save} className="flex flex-col gap-2">
            <Label htmlFor="apps-domain">Domain for your apps</Label>
            <div className="flex flex-wrap gap-2">
                <Input
                    id="apps-domain"
                    value={value}
                    onChange={(event) => setValue(event.target.value)}
                    placeholder="apps.example.com"
                    autoComplete="off"
                    spellCheck={false}
                    aria-describedby="apps-domain-hint"
                    className="min-w-0 flex-1 basis-56 type-mono"
                />
                <Button
                    type="submit"
                    variant={domain ? 'outline' : 'default'}
                    disabled={
                        saving ||
                        normalized === '' ||
                        normalized === (domain ?? '')
                    }
                >
                    {saving && <Spinner />}
                    Save
                </Button>
            </div>
            <p
                id="apps-domain-hint"
                className="type-small text-muted-foreground"
            >
                A subdomain such as apps.example.com, not the dashboard’s{' '}
                <span className="type-mono-small">{dashboardHost}</span>.
            </p>
            <InputError message={error ?? undefined} />
        </form>
    );
}

/**
 * The live check after saving (docs/08 §3.1). Get started gates the step:
 * Continue once DNS reaches the server, or Continue anyway. Settings has
 * nothing to continue to: the domain is already in use, so it keeps
 * checking, says what that means for new apps, and records the
 * verification by itself once DNS turns green.
 */
function AppsDomainVerify({
    domain,
    mode,
    done,
}: {
    domain: string;
    mode: 'onboarding' | 'settings';
    /** Onboarding: the step is done. Settings: DNS was verified before. */
    done: boolean;
}) {
    const { result, error, checking, run } = useDnsWatch(domain);
    const [confirming, setConfirming] = useState(false);
    const [confirmError, setConfirmError] = useState<string | null>(null);
    const verified = result?.status === 'ok';
    const recorded = useRef(false);

    const confirm = useCallback((anyway: boolean) => {
        router.post(
            confirmDomain().url,
            { anyway },
            {
                preserveScroll: true,
                onStart: () => setConfirming(true),
                onFinish: () => setConfirming(false),
                onError: (errors) =>
                    setConfirmError(errors.appsDomain ?? 'Saving failed.'),
                onSuccess: () => setConfirmError(null),
            },
        );
    }, []);

    useEffect(() => {
        if (mode === 'settings' && verified && !done && !recorded.current) {
            recorded.current = true;
            confirm(false);
        }
    }, [mode, verified, done, confirm]);

    const gate = mode === 'onboarding' && !done;
    // A finished Get started step shows the result only, as before.
    const actions = mode === 'onboarding' ? gate : !verified;

    return (
        <div className="flex flex-col gap-4">
            <div aria-live="polite">
                {error ? (
                    <InputError message={error} />
                ) : result === null || result.status === 'missing' ? (
                    <Status
                        state="progress"
                        label="Waiting for DNS"
                        detail="checked every 10 seconds, usually takes a few minutes"
                    />
                ) : (
                    <DnsCheckResult result={result} compact wildcard={domain} />
                )}
            </div>
            {actions && (
                <div className="flex flex-col gap-2">
                    <div className="flex flex-wrap items-center gap-2">
                        {gate && verified ? (
                            <Button
                                onClick={() => confirm(false)}
                                disabled={confirming}
                            >
                                {confirming && <Spinner />}
                                Continue
                            </Button>
                        ) : (
                            <Button
                                variant="outline"
                                onClick={() => void run()}
                                disabled={checking}
                            >
                                {checking && <Spinner />}
                                Check now
                            </Button>
                        )}
                        {gate && !verified && (
                            <Button
                                variant="ghost"
                                onClick={() => confirm(true)}
                                disabled={confirming}
                            >
                                Continue anyway
                            </Button>
                        )}
                    </div>
                    {!verified && (
                        <p className="max-w-[65ch] type-small text-muted-foreground">
                            {gate ? 'If you continue now, new' : 'New'} apps get
                            addresses under{' '}
                            <span className="type-mono-small">{domain}</span>{' '}
                            right away, and they open once DNS reaches your
                            server.
                        </p>
                    )}
                    <InputError message={confirmError ?? undefined} />
                </div>
            )}
        </div>
    );
}

/**
 * The platform domain picker (docs/08 §3.1, docs/05 §5), the same on Get
 * started and in Settings → Network: "Use my domain" (the field, the
 * wildcard record with the server's public address and the live check) or
 * "Use a free address" (sslip.io). The caller supplies what choosing the
 * free address does (`freeAction`) and how the check ends (`mode`).
 */
export function AppsDomainPicker({
    domain,
    addresses,
    freeWildcard,
    dashboardHost,
    usingFree,
    freeAction,
    mode,
    done,
    footer,
    framed = false,
}: {
    domain: string | null;
    addresses: string[];
    /** "*.203-0-113-7.sslip.io" */
    freeWildcard: string | null;
    dashboardHost: string;
    /** Free addresses are what new apps get now. */
    usingFree: boolean;
    /** The button under "Use a free address" while it isn't in use. */
    freeAction: ReactNode;
    mode: 'onboarding' | 'settings';
    done: boolean;
    /** Under "Use my domain" (Get started: Skip for now). */
    footer?: ReactNode;
    /** Puts the chosen option's body in a card (settings pages). */
    framed?: boolean;
}) {
    const [choice, setChoice] = useState<'own' | 'free'>(
        usingFree ? 'free' : 'own',
    );
    const body = cn(
        'flex min-w-0 flex-col gap-5',
        framed && 'rounded-xl border bg-card p-5 shadow-card',
    );

    return (
        <>
            <ChoiceTiles
                label="Address for your apps"
                columns={2}
                value={choice}
                onChange={setChoice}
                options={[
                    {
                        value: 'own',
                        label: 'Use my domain',
                        description: domain
                            ? `One DNS record for ${domain}`
                            : 'One DNS record, set up once',
                        media: (
                            <Globe
                                className="size-5 text-muted-foreground"
                                aria-hidden="true"
                            />
                        ),
                    },
                    {
                        value: 'free',
                        label: 'Use a free address for now',
                        description: freeWildcard
                            ? `Works right away with ${freeWildcard}`
                            : 'Works right away',
                        media: (
                            <Zap
                                className="size-5 text-muted-foreground"
                                aria-hidden="true"
                            />
                        ),
                    },
                ]}
            />

            {choice === 'own' ? (
                <div className={body}>
                    <AppsDomainForm
                        key={domain ?? ''}
                        domain={domain}
                        dashboardHost={dashboardHost}
                    />
                    {domain && (
                        <>
                            <PlatformDnsRecords
                                domain={domain}
                                addresses={addresses}
                            />
                            <AppsDomainVerify
                                key={domain}
                                domain={domain}
                                mode={mode}
                                done={done}
                            />
                        </>
                    )}
                    {footer}
                </div>
            ) : (
                <div className={cn(body, 'gap-3')}>
                    <p className="type-small text-muted-foreground">
                        Good for trying things out. Switch to your own domain
                        before going live, because free addresses share
                        certificate limits with everyone else.
                    </p>
                    <div>
                        {usingFree ? (
                            <Status
                                state="active"
                                label="Using free addresses"
                            />
                        ) : (
                            freeAction
                        )}
                    </div>
                </div>
            )}
        </>
    );
}
