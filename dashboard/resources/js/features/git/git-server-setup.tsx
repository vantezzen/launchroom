import { router, usePage } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { CopyValue } from '@/components/copy-value';
import InputError from '@/components/input-error';
import { Logo } from '@/components/logo';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Step } from '@/features/git/connect-provider-form';
import { confirm } from '@/routes/git/servers';

/**
 * The guided setup of an SSH git server (docs/19 §4.2): check the server's
 * fingerprint, add the deploy key, then launchroom pins the key and checks
 * access. Nothing is trusted before the user confirms.
 */
export function GitServerSetup({
    connection,
}: {
    connection: App.Data.Git.GitConnectionData;
}) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [processing, setProcessing] = useState(false);
    const keyChanged =
        connection.status === 'active' && !connection.hostKeysConfirmed;

    const submit = () =>
        router.post(
            confirm(connection.id).url,
            { fingerprints: connection.hostKeys.map((key) => key.fingerprint) },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );

    return (
        <section
            aria-labelledby={`setup-${connection.id}`}
            className="overflow-hidden rounded-xl border bg-card shadow-card"
        >
            <div className="flex items-center gap-4 p-5">
                <Logo kind="git" size={44} decorative />
                <div className="flex min-w-0 flex-col gap-1">
                    <h2
                        id={`setup-${connection.id}`}
                        className="truncate type-heading"
                    >
                        {keyChanged
                            ? `The host key of ${connection.host} changed`
                            : `Finish adding ${connection.name}`}
                    </h2>
                    <p className="truncate type-mono-small text-muted-foreground">
                        {connection.url}
                    </p>
                </div>
            </div>
            <ol className="flex flex-col gap-7 border-t px-5 py-6">
                <Step number={1} title="Check the server’s identity">
                    <p className="max-w-prose type-body text-muted-foreground">
                        {keyChanged
                            ? 'Builds stop until you confirm the new key. Make sure the server was really reinstalled or rotated its keys.'
                            : 'launchroom will only ever talk to the server with this key. Compare it with what the server itself shows:'}{' '}
                        <span className="type-mono-small text-foreground">
                            ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub
                        </span>
                    </p>
                    <ul className="flex max-w-xl flex-col divide-y rounded-lg border">
                        {connection.hostKeys.map((key) => (
                            <li
                                key={key.fingerprint}
                                className="flex flex-col gap-0.5 px-3 py-2.5"
                            >
                                <span className="type-small text-muted-foreground">
                                    {key.type}
                                </span>
                                <span className="type-mono-small break-all">
                                    {key.fingerprint}
                                </span>
                            </li>
                        ))}
                    </ul>
                    <InputError message={errors.fingerprints} />
                </Step>
                {!keyChanged && connection.deployKey !== null && (
                    <Step number={2} title="Add the deploy key">
                        <p className="max-w-prose type-body text-muted-foreground">
                            Add it to the repository as a read-only deploy key
                            (or to the git user’s{' '}
                            <span className="type-mono-small">
                                authorized_keys
                            </span>
                            ). It was made for this repository only; the private
                            half never leaves launchroom.
                        </p>
                        <CopyValue
                            value={connection.deployKey}
                            label="deploy key"
                            className="max-w-xl"
                        />
                    </Step>
                )}
                <Step
                    number={keyChanged ? 2 : 3}
                    title={keyChanged ? 'Trust the new key' : 'Check access'}
                >
                    {connection.statusReason !== null &&
                        connection.status === 'pending' && (
                            <p className="max-w-prose type-small text-muted-foreground">
                                Last try: {connection.statusReason}
                            </p>
                        )}
                    <InputError message={errors.access} />
                    <div>
                        <Button
                            type="button"
                            onClick={submit}
                            disabled={processing}
                        >
                            {processing ? (
                                <Spinner />
                            ) : (
                                <ShieldCheck aria-hidden="true" />
                            )}
                            {keyChanged
                                ? 'This is the right key'
                                : 'These match, check access'}
                        </Button>
                    </div>
                </Step>
            </ol>
        </section>
    );
}
