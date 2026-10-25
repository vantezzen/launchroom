import { router } from '@inertiajs/react';
import { KeyRound, ShieldCheck, ShieldOff } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { OptionCards } from '@/components/patterns/option-cards';
import { StatusPill } from '@/components/status';
import type { StatusState } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { SettingsCard, SettingsRow } from '@/features/settings/settings-card';
import { reissue, update } from '@/routes/client-certificates';

type Overview = App.Data.Security.ClientCertificatesOverviewData;
type Mode = 'off' | 'verify' | 'require';

const states: Record<
    string,
    { state: StatusState; label: string; hint: string }
> = {
    ready: {
        state: 'active',
        label: 'Ready',
        hint: 'Presented its certificate on every recent request.',
    },
    control_plane: {
        state: 'idle',
        label: 'This server',
        hint: 'Runs the dashboard and talks to it directly. Nothing to do.',
    },
    has_certificate: {
        state: 'staged',
        label: 'Has a certificate',
        hint: 'Turn on Check to see that it presents it.',
    },
    waiting: {
        state: 'progress',
        label: 'Waiting for a certificate',
        hint: 'It asks for one within a minute of connecting over HTTPS.',
    },
    not_presenting: {
        state: 'warning',
        label: 'Not presenting it',
        hint: 'Some requests arrive without a certificate, for example over plain http or not through the dashboard’s address.',
    },
    update: {
        state: 'warning',
        label: 'Needs an update',
        hint: 'Update this server to a version that supports certificates.',
    },
    offline: {
        state: 'idle',
        label: 'Offline',
        hint: 'Readiness shows once it is back.',
    },
};

/**
 * Settings → Security → Server certificates (docs/03 §4.3, D50): the
 * rollout Off → Check → Require as a mode choice, then every server's
 * observed readiness. Require is offered once everything is ready;
 * requiring anyway names the servers that will be refused.
 */
export function ClientCertificatesSection({
    overview,
}: {
    overview: Overview;
}) {
    const mode = overview.mode as Mode;
    const [confirm, setConfirm] = useState<Mode | null>(null);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | undefined>();
    const blocking = overview.servers.filter((server) => !server.ready);

    const save = (next: Mode, force = false) => {
        router.put(
            update.url(),
            { mode: next, force },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => {
                    setConfirm(null);
                    setError(undefined);
                },
                onError: (errors) => setError(errors.mode ?? errors.general),
            },
        );
    };

    const choose = (next: Mode) => {
        if (next === mode) {
            return;
        }

        if (next === 'require' && (mode === 'off' || blocking.length > 0)) {
            setConfirm('require');

            return;
        }

        if (next === 'off' && mode === 'require') {
            setConfirm('off');

            return;
        }

        save(next);
    };

    return (
        <>
            <section className="flex flex-col gap-3.5">
                <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <h2 className="type-heading">Server certificates</h2>
                    <p className="type-body text-muted-foreground">
                        Servers can prove who they are with a certificate in
                        addition to their signed requests.
                    </p>
                </div>
                <OptionCards
                    label="Server certificates"
                    value={mode}
                    onChange={choose}
                    options={[
                        {
                            value: 'off',
                            title: 'Off',
                            description:
                                'Signed requests only. Servers still get a certificate.',
                            media: (
                                <ShieldOff
                                    aria-hidden="true"
                                    className="size-5 text-muted-foreground"
                                />
                            ),
                        },
                        {
                            value: 'verify',
                            title: 'Check',
                            description:
                                'Certificates are verified when presented. Nothing is refused yet.',
                            media: (
                                <KeyRound
                                    aria-hidden="true"
                                    className="size-5 text-muted-foreground"
                                />
                            ),
                        },
                        {
                            value: 'require',
                            title: 'Require',
                            description:
                                'Servers without a valid certificate can’t reach the dashboard.',
                            media: (
                                <ShieldCheck
                                    aria-hidden="true"
                                    className="size-5 text-muted-foreground"
                                />
                            ),
                        },
                    ]}
                />
                <InputError message={error} />
            </section>

            <SettingsCard
                title="Readiness"
                description={
                    mode === 'off'
                        ? 'Turn on Check to see which servers present their certificate.'
                        : `${overview.readyCount} of ${overview.servers.length} ready. Certificates renew on their own every ${Math.round((overview.lifetimeDays * 2) / 3)} days or so.`
                }
                footer={
                    overview.caFingerprint ? (
                        <span>
                            Issued by this launchroom’s own certificate
                            authority, fingerprint{' '}
                            <span className="font-mono">
                                {overview.caFingerprint.slice(0, 16)}
                            </span>
                        </span>
                    ) : (
                        'No certificate issued yet.'
                    )
                }
            >
                {overview.servers.length === 0 && (
                    <SettingsRow
                        label="No servers yet"
                        description="Servers you add show up here."
                    />
                )}
                {overview.servers.map((server) => (
                    <ServerRow key={server.id} server={server} />
                ))}
            </SettingsCard>

            <Dialog
                open={confirm !== null}
                onOpenChange={(open) => !open && setConfirm(null)}
            >
                <DialogContent>
                    {confirm === 'require' ? (
                        <>
                            <DialogTitle>
                                {blocking.length > 0
                                    ? `Require anyway? ${blocking.length} ${blocking.length === 1 ? 'server' : 'servers'} will be refused`
                                    : 'Require certificates without checking first?'}
                            </DialogTitle>
                            <DialogDescription>
                                {blocking.length > 0
                                    ? 'These servers can’t reach the dashboard until they present a valid certificate. Their apps keep running.'
                                    : 'Check shows you first which servers present their certificate.'}
                            </DialogDescription>
                            {blocking.length > 0 && (
                                <ul className="flex flex-col gap-1.5 type-body">
                                    {blocking.map((server) => (
                                        <li
                                            key={server.id}
                                            className="flex items-center justify-between gap-3"
                                        >
                                            <span className="truncate">
                                                {server.name}
                                            </span>
                                            <StatusPill
                                                state={
                                                    states[server.state]
                                                        ?.state ?? 'idle'
                                                }
                                                label={
                                                    states[server.state]
                                                        ?.label ?? server.state
                                                }
                                            />
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </>
                    ) : (
                        <>
                            <DialogTitle>
                                Stop requiring certificates?
                            </DialogTitle>
                            <DialogDescription>
                                Servers can connect with signed requests alone.
                                You can require certificates again at any time.
                            </DialogDescription>
                        </>
                    )}
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="outline">Cancel</Button>
                        </DialogClose>
                        <Button
                            variant={
                                confirm === 'require' && blocking.length > 0
                                    ? 'destructive'
                                    : 'default'
                            }
                            disabled={processing}
                            onClick={() =>
                                confirm && save(confirm, confirm === 'require')
                            }
                        >
                            {processing && <Spinner />}
                            {confirm === 'require'
                                ? 'Require certificates'
                                : 'Turn off'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function ServerRow({
    server,
}: {
    server: App.Data.Security.ServerCertificateReadinessData;
}) {
    const [processing, setProcessing] = useState(false);
    const meta = states[server.state] ?? {
        state: 'idle' as StatusState,
        label: server.state,
        hint: '',
    };

    return (
        <SettingsRow
            label={server.name}
            description={
                <span className="flex flex-col gap-0.5">
                    <span>{meta.hint}</span>
                    {server.expiresAt && (
                        <span>
                            Certificate valid until{' '}
                            <time
                                dateTime={server.expiresAt}
                                className="tabular-nums"
                            >
                                {new Date(
                                    server.expiresAt,
                                ).toLocaleDateString()}
                            </time>
                            {server.lastPresentedAt && (
                                <>
                                    , last presented{' '}
                                    <TimeAgo value={server.lastPresentedAt} />
                                </>
                            )}
                        </span>
                    )}
                    {server.reissueUntil && (
                        <span>
                            May get a new certificate until{' '}
                            <time
                                dateTime={server.reissueUntil}
                                className="tabular-nums"
                            >
                                {new Date(
                                    server.reissueUntil,
                                ).toLocaleTimeString()}
                            </time>
                        </span>
                    )}
                </span>
            }
        >
            <StatusPill state={meta.state} label={meta.label} />
            {server.canAllowReissue && !server.reissueUntil && (
                <Button
                    variant="outline"
                    size="sm"
                    disabled={processing}
                    onClick={() =>
                        router.post(
                            reissue.url(server.id),
                            {},
                            {
                                preserveScroll: true,
                                onStart: () => setProcessing(true),
                                onFinish: () => setProcessing(false),
                            },
                        )
                    }
                >
                    {processing && <Spinner />}
                    Allow a new certificate
                </Button>
            )}
        </SettingsRow>
    );
}
