import { router, useForm } from '@inertiajs/react';
import {
    Check,
    FileKey2,
    KeyRound,
    MoreHorizontal,
    Printer,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Section } from '@/components/patterns/section';
import { StatusPill } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { RecoveryKit } from './page-types';

/** Reload the page props a moment after a download, which records itself server-side. */
function reloadSoon() {
    window.setTimeout(() => router.reload({ only: ['page'] }), 1200);
}

export function PassphraseForm({
    kit,
    onCancel,
    onDone,
}: {
    kit: RecoveryKit;
    onCancel?: () => void;
    onDone?: () => void;
}) {
    const form = useForm({ passphrase: '', passphrase_confirmation: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(kit.passphraseUrl, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onDone?.();
            },
        });
    };

    return (
        <form onSubmit={submit} className="grid gap-4">
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid content-start gap-2">
                    <Label htmlFor="recovery-passphrase">
                        {kit.passphraseSet
                            ? 'New recovery passphrase'
                            : 'Recovery passphrase'}
                    </Label>
                    <Input
                        id="recovery-passphrase"
                        type="password"
                        autoComplete="new-password"
                        minLength={12}
                        value={form.data.passphrase}
                        onChange={(event) =>
                            form.setData('passphrase', event.target.value)
                        }
                        aria-invalid={form.errors.passphrase ? true : undefined}
                    />
                    <p className="type-small text-muted-foreground">
                        At least 12 characters.
                    </p>
                    <InputError message={form.errors.passphrase} />
                </div>
                <div className="grid content-start gap-2">
                    <Label htmlFor="recovery-passphrase-confirmation">
                        Repeat it
                    </Label>
                    <Input
                        id="recovery-passphrase-confirmation"
                        type="password"
                        autoComplete="new-password"
                        value={form.data.passphrase_confirmation}
                        onChange={(event) =>
                            form.setData(
                                'passphrase_confirmation',
                                event.target.value,
                            )
                        }
                    />
                </div>
            </div>
            <p className="max-w-[65ch] type-small text-muted-foreground">
                You need it with the recovery kit to bring launchroom back on a
                new server. launchroom can’t recover it for you.
            </p>
            <div className="flex justify-end gap-2">
                {onCancel && (
                    <Button type="button" variant="ghost" onClick={onCancel}>
                        Cancel
                    </Button>
                )}
                <Button type="submit" disabled={form.processing}>
                    {form.processing && <Spinner />}
                    {kit.passphraseSet ? 'Change passphrase' : 'Set passphrase'}
                </Button>
            </div>
        </form>
    );
}

function DownloadStep({
    kit,
    onDownloaded,
}: {
    kit: RecoveryKit;
    onDownloaded: () => void;
}) {
    return (
        <div className="flex flex-col gap-3">
            <p className="max-w-[65ch] type-body text-muted-foreground">
                {kit.resetReason === 'storage_changed'
                    ? 'launchroom’s backup storage changed, so the kit you saved before no longer works. Download the new one.'
                    : 'The kit holds where launchroom’s backups are and how to open them. The passphrase isn’t in it.'}
            </p>
            <div>
                <Button asChild>
                    <a href={kit.downloadUrl} onClick={onDownloaded}>
                        <FileKey2 aria-hidden="true" />
                        Download recovery kit
                    </a>
                </Button>
            </div>
        </div>
    );
}

/**
 * Right after the download (1Password's "Keep it safe"): where the file
 * is, two suggestions and one button. No checkbox, no gate on a reload:
 * the click on Download is the proof.
 */
function KeepItSafe({
    kit,
    onConfirmed,
}: {
    kit: RecoveryKit;
    onConfirmed?: () => void;
}) {
    const [busy, setBusy] = useState(false);

    return (
        <div className="flex flex-col gap-4">
            {kit.resetReason === 'passphrase_changed' ? (
                <p className="max-w-[65ch] type-body text-muted-foreground">
                    The passphrase changed. Backups from now on need the new
                    one, so keep it safe too.
                </p>
            ) : (
                <p className="flex items-center gap-2 type-body">
                    <Check
                        aria-hidden="true"
                        className="size-4 text-status-ok"
                    />
                    Downloaded the recovery kit
                </p>
            )}
            <div className="flex flex-col gap-1">
                <p className="type-heading">Keep it safe</p>
                <p className="max-w-[65ch] type-body text-muted-foreground">
                    It’s in your downloads folder. Together with your passphrase
                    it brings launchroom back on a new server.
                </p>
            </div>
            <ul className="flex flex-col gap-2.5">
                <li className="flex items-start gap-3 type-body">
                    <span className="flex size-7 shrink-0 items-center justify-center rounded-lg bg-recessed text-muted-foreground">
                        <Printer aria-hidden="true" className="size-3.5" />
                    </span>
                    Print it, or save it in your password manager.
                </li>
                <li className="flex items-start gap-3 type-body">
                    <span className="flex size-7 shrink-0 items-center justify-center rounded-lg bg-recessed text-muted-foreground">
                        <KeyRound aria-hidden="true" className="size-3.5" />
                    </span>
                    Keep the passphrase somewhere else. The kit doesn’t contain
                    it.
                </li>
            </ul>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <Button variant="ghost" size="sm" asChild>
                    <a href={kit.downloadUrl}>Download again</a>
                </Button>
                <Button
                    disabled={busy}
                    onClick={() =>
                        router.post(
                            kit.confirm.url ?? '',
                            {},
                            {
                                preserveScroll: true,
                                onStart: () => setBusy(true),
                                onFinish: () => setBusy(false),
                                onSuccess: () => onConfirmed?.(),
                            },
                        )
                    }
                >
                    {busy && <Spinner />}
                    I’ve put both away
                </Button>
            </div>
        </div>
    );
}

/**
 * The kit in its current step: set the passphrase, download, keep it
 * safe. The download marks itself done at once on the client.
 */
export function KitFlow({
    kit,
    onDone,
}: {
    kit: RecoveryKit;
    onDone?: () => void;
}) {
    const [downloaded, setDownloaded] = useState(false);
    const step = downloaded && kit.step === 'download' ? 'keep_safe' : kit.step;
    const reported = useRef(false);

    useEffect(() => {
        if (step === 'done' && !reported.current) {
            reported.current = true;
            onDone?.();
        }
    }, [step, onDone]);

    switch (step) {
        case 'passphrase':
            return <PassphraseForm kit={kit} />;
        case 'download':
            return (
                <DownloadStep
                    kit={kit}
                    onDownloaded={() => {
                        setDownloaded(true);
                        reloadSoon();
                    }}
                />
            );
        case 'keep_safe':
            return <KeepItSafe kit={kit} />;
        case 'done':
            return null;
    }
}

/**
 * The recovery kit card (docs/14 §4), instance owners: the kit flow until
 * it's put away, then one quiet line with Download again and Change
 * passphrase.
 */
export function RecoveryKitCard({ kit }: { kit: RecoveryKit }) {
    const [changing, setChanging] = useState(false);
    const done = kit.step === 'done';

    return (
        <Section
            id="recovery-kit"
            data-setting="recovery-kit"
            title="Recovery kit"
            description="With it and your passphrase, launchroom comes back on a new server."
            className="scroll-mt-24"
        >
            <div className="flex flex-col gap-4 rounded-xl border bg-card p-5 shadow-card">
                {done && !changing ? (
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex min-w-0 flex-col gap-1">
                            <StatusPill
                                state="active"
                                label="Saved"
                                className="self-start"
                            />
                            {kit.kitConfirmedAt !== null && (
                                <span className="type-small text-muted-foreground">
                                    Put away{' '}
                                    <TimeAgo value={kit.kitConfirmedAt} />. Keep
                                    the kit and the passphrase in two different
                                    places.
                                </span>
                            )}
                        </div>
                        <div className="flex items-center gap-1">
                            <Button variant="outline" size="sm" asChild>
                                <a href={kit.downloadUrl}>Download again</a>
                            </Button>
                            <DropdownMenu modal={false}>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        variant="ghost"
                                        size="icon-sm"
                                        aria-label="More for the recovery kit"
                                    >
                                        <MoreHorizontal aria-hidden="true" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        onSelect={() => setChanging(true)}
                                    >
                                        Change passphrase…
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    </div>
                ) : changing ? (
                    <PassphraseForm
                        kit={kit}
                        onCancel={() => setChanging(false)}
                        onDone={() => setChanging(false)}
                    />
                ) : (
                    <>
                        {kit.step === 'passphrase' && (
                            <StatusPill
                                state="warning"
                                label="Needs a passphrase"
                                className="self-start"
                            />
                        )}
                        <KitFlow kit={kit} />
                    </>
                )}
            </div>
        </Section>
    );
}
