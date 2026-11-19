import { router } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useRef, useState } from 'react';
import { Logo } from '@/components/logo';
import { ChoiceTiles } from '@/components/patterns/choice-tiles';
import { LiveWait } from '@/components/patterns/live-wait';
import { SidePanel, SheetSection } from '@/components/patterns/sheet';
import { Status } from '@/components/status';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { plural } from '@/lib/format';
import { cn } from '@/lib/utils';
import { ActionReason } from './action-reason';
import { DestinationForm, providers } from './destination-form';
import type { Provider } from './destination-form';
import { failures } from './destination-status';
import { storageProvider } from './format';
import type {
    BackupsPage,
    Destination,
    RemovedDestination,
} from './page-types';
import { KitFlow } from './recovery-kit';

export type SetupStart =
    | { step: 'where' }
    | { step: 'launchroom' }
    | { step: 'reconnect'; removed: RemovedDestination };

type Step = 'where' | 'connect' | 'testing' | 'fix' | 'launchroom' | 'done';

const tiles: Provider[] = ['r2', 'hetzner', 's3', 'b2'];

function StepHeader({ steps, current }: { steps: string[]; current: number }) {
    return (
        <ol className="flex flex-wrap gap-x-5 gap-y-2" aria-label="Steps">
            {steps.map((name, index) => (
                <li
                    key={name}
                    aria-current={index === current ? 'step' : undefined}
                    className={cn(
                        'flex items-center gap-2 border-b-2 pb-1.5 type-label',
                        index === current
                            ? 'border-foreground text-foreground'
                            : index < current
                              ? 'border-transparent text-foreground'
                              : 'border-transparent text-muted-foreground',
                    )}
                >
                    <span
                        aria-hidden="true"
                        className={cn(
                            'flex size-5 items-center justify-center rounded-full type-small tabular-nums',
                            index < current
                                ? 'bg-status-ok-soft text-status-ok'
                                : 'bg-hover',
                        )}
                    >
                        {index < current ? (
                            <Check className="size-3" />
                        ) : (
                            index + 1
                        )}
                    </span>
                    {name}
                </li>
            ))}
        </ol>
    );
}

/** The connection test of the storage just added, live, with the fix on failure. */
function TestingStep({
    destination,
    onFix,
    onContinue,
}: {
    destination: Destination | undefined;
    onFix: () => void;
    onContinue: () => void;
}) {
    if (
        destination === undefined ||
        destination.status === 'checking' ||
        destination.status === 'unverified'
    ) {
        return (
            <LiveWait
                media={
                    destination !== undefined ? (
                        <Logo
                            kind={storageProvider(destination.endpoint).logo}
                            size={44}
                            decorative
                        />
                    ) : undefined
                }
                status="Testing the connection"
                hint="launchroom writes a small file to the bucket and reads it back. Usually a few seconds."
                timeoutSeconds={60}
                fallback={
                    <p className="type-small text-muted-foreground">
                        This takes longer than usual. The test keeps going; you
                        can close this and check the storage card later.
                    </p>
                }
            />
        );
    }

    if (destination.status === 'failing') {
        const failure =
            destination.errorKind !== null
                ? failures[destination.errorKind]
                : failures.other;

        return (
            <div className="flex flex-col gap-4">
                <Status state="failed" label={failure.label} />
                <p className="max-w-[65ch] type-body text-muted-foreground">
                    {failure.fix}
                </p>
                {destination.lastError !== null && (
                    <p className="rounded-lg bg-recessed px-3 py-2 type-mono-small break-words text-muted-foreground">
                        {destination.lastError}
                    </p>
                )}
                <div className="flex justify-end">
                    <Button onClick={onFix}>Change the connection</Button>
                </div>
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-4">
            <Status
                state="active"
                label={`Connected to ${destination.bucket}`}
            />
            <p className="type-body text-muted-foreground">
                launchroom can write to the bucket and read it back.
            </p>
            <div className="flex justify-end">
                <Button onClick={onContinue}>Continue</Button>
            </div>
        </div>
    );
}

/** Protect launchroom itself: copy a team storage (own folder, own password), then the kit. */
function LaunchroomStep({
    page,
    onDone,
}: {
    page: BackupsPage;
    onDone: () => void;
}) {
    const launchroom = page.launchroom;
    const options = page.storage.destinations;
    const [from, setFrom] = useState(options[0]?.id ?? '');
    const [busy, setBusy] = useState(false);

    if (launchroom === null) {
        return null;
    }

    if (launchroom.destination === null) {
        const setup = launchroom.setup;

        return (
            <div className="flex flex-col gap-4">
                <p className="max-w-[65ch] type-body text-muted-foreground">
                    launchroom’s own data (projects, settings and keys) goes to
                    the same bucket, in its own folder{' '}
                    <code className="type-mono-small">launchroom/</code>, with
                    its own password.
                </p>
                {options.length > 1 && (
                    <ChoiceTiles
                        label="Storage for launchroom itself"
                        columns={2}
                        value={from}
                        onChange={setFrom}
                        options={options.map((destination) => ({
                            value: destination.id,
                            label: destination.name,
                            description: destination.bucket,
                            media: (
                                <Logo
                                    kind={
                                        storageProvider(destination.endpoint)
                                            .logo
                                    }
                                    size={32}
                                    decorative
                                />
                            ),
                        }))}
                    />
                )}
                {setup.url !== null ? (
                    <div className="flex justify-end">
                        <Button
                            disabled={busy || from === ''}
                            onClick={() =>
                                router.post(
                                    setup.url ?? '',
                                    { from },
                                    {
                                        preserveScroll: true,
                                        onStart: () => setBusy(true),
                                        onFinish: () => setBusy(false),
                                    },
                                )
                            }
                        >
                            {busy && <Spinner />}
                            Protect launchroom itself
                        </Button>
                    </div>
                ) : (
                    setup.disabled !== null && (
                        <ActionReason reason={setup.disabled} />
                    )
                )}
            </div>
        );
    }

    if (launchroom.recoveryKit === null) {
        return null;
    }

    return (
        <div className="flex flex-col gap-4">
            <p className="flex items-center gap-2 type-body">
                <Check aria-hidden="true" className="size-4 text-status-ok" />
                launchroom itself backs up to {launchroom.destination.bucket}
            </p>
            <KitFlow kit={launchroom.recoveryKit} onDone={onDone} />
        </div>
    );
}

/** "Backup is on": what is protected from now on, and the first run today. */
function DoneStep({
    page,
    onClose,
}: {
    page: BackupsPage;
    onClose: () => void;
}) {
    const items = page.groups.flatMap((group) => group.items);
    const count = (kind: string) =>
        items.filter((item) => item.kind === kind).length;
    const own = items.find((item) => item.kind === 'control_plane');
    const run = page.everything.action.url;
    const rows = [
        own !== undefined && own.schedule !== null
            ? {
                  key: 'launchroom',
                  label: 'launchroom itself',
                  when: own.schedule.cronDescription ?? 'On a schedule',
              }
            : null,
        count('database') > 0
            ? {
                  key: 'database',
                  label: plural(count('database'), 'database'),
                  when: 'Daily',
              }
            : null,
        count('volume') > 0
            ? {
                  key: 'volume',
                  label: plural(count('volume'), 'volume'),
                  when: 'Daily',
              }
            : null,
    ].filter((row) => row !== null);

    return (
        <div className="flex flex-col gap-5">
            <Status
                state="active"
                label="Backup is on"
                className="type-heading"
            />
            {rows.length > 0 && (
                <div className="flex flex-col gap-2">
                    <p className="type-body text-muted-foreground">
                        These are backed up from now on:
                    </p>
                    <ul className="divide-y rounded-lg border">
                        {rows.map((row) => (
                            <li
                                key={row.key}
                                className="flex items-center justify-between gap-3 px-3.5 py-2.5 type-body"
                            >
                                <span>{row.label}</span>
                                <span className="text-muted-foreground">
                                    {row.when}
                                </span>
                            </li>
                        ))}
                    </ul>
                    <p className="type-small text-muted-foreground">
                        Previews aren’t backed up.
                    </p>
                </div>
            )}
            <div className="flex justify-end gap-2">
                <Button variant="ghost" onClick={onClose}>
                    Close
                </Button>
                {run !== null && (
                    <Button
                        onClick={() =>
                            router.post(
                                run,
                                {},
                                { preserveScroll: true, onSuccess: onClose },
                            )
                        }
                    >
                        Back up everything now
                    </Button>
                )}
            </div>
        </div>
    );
}

/**
 * Connect storage once, everything protected (bk-design §3): choose the
 * provider, connect with an inline live test, then protect launchroom
 * itself (same bucket, own folder) with the passphrase and the kit, and
 * end on "Backup is on".
 */
export function SetupSheet({
    open,
    onOpenChange,
    start,
    page,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    start: SetupStart;
    page: BackupsPage;
}) {
    const [step, setStep] = useState<Step>(
        start.step === 'reconnect' ? 'connect' : start.step,
    );
    const [provider, setProvider] = useState<Provider>(
        start.step === 'reconnect' ? 'other' : 'r2',
    );
    const [protect, setProtect] = useState(page.storage.canProtectLaunchroom);
    const known = useRef(new Set(page.storage.destinations.map((d) => d.id)));
    const [createdId, setCreatedId] = useState<string | null>(null);
    const created =
        page.storage.destinations.find((d) => d.id === createdId) ??
        page.storage.destinations.find((d) => !known.current.has(d.id));
    const owner = page.launchroom?.isOwner === true;
    const kitPending =
        owner &&
        (page.launchroom?.destination === null ||
            page.launchroom?.recoveryKit?.step !== 'done');
    const withLaunchroom =
        start.step === 'launchroom' || (protect && kitPending);
    const steps =
        start.step === 'launchroom'
            ? ['launchroom itself']
            : [
                  'Where',
                  'Connect',
                  ...(withLaunchroom ? ['launchroom itself'] : []),
              ];
    const current =
        step === 'where'
            ? 0
            : step === 'connect' || step === 'testing' || step === 'fix'
              ? start.step === 'launchroom'
                  ? 0
                  : 1
              : step === 'launchroom'
                ? steps.length - 1
                : steps.length;
    const afterTest = () => setStep(withLaunchroom ? 'launchroom' : 'done');
    const reconnect = start.step === 'reconnect' ? start.removed : null;

    return (
        <SidePanel
            open={open}
            onOpenChange={onOpenChange}
            size="md"
            title={
                start.step === 'launchroom'
                    ? 'Protect launchroom itself'
                    : 'Keep a copy off your servers'
            }
            description={
                step === 'done'
                    ? undefined
                    : 'Backups are encrypted on your servers before they’re uploaded.'
            }
        >
            {step !== 'done' && steps.length > 1 && (
                <SheetSection>
                    <StepHeader steps={steps} current={current} />
                </SheetSection>
            )}
            <SheetSection>
                {step === 'where' && (
                    <ChoiceTiles
                        label="Storage provider"
                        columns={2}
                        value={null}
                        onChange={(next) => {
                            setProvider(next);
                            setStep('connect');
                        }}
                        options={tiles.map((key) => ({
                            value: key,
                            label: providers[key].label,
                            description: providers[key].description,
                            media: (
                                <Logo
                                    kind={providers[key].logo}
                                    size={32}
                                    decorative
                                />
                            ),
                        }))}
                        other={{
                            label: providers.other.label,
                            description: providers.other.description,
                            onSelect: () => {
                                setProvider('other');
                                setStep('connect');
                            },
                        }}
                    />
                )}

                {step === 'connect' && page.storage.add.url !== null && (
                    <div className="flex flex-col gap-4">
                        {reconnect !== null && (
                            <p className="type-body text-muted-foreground">
                                {plural(reconnect.runs, 'backup')}{' '}
                                {reconnect.runs === 1 ? 'is' : 'are'} still in
                                this folder.{' '}
                                {reconnect.passwordKept
                                    ? 'launchroom kept their repository password, so new access keys are enough to pick them up.'
                                    : 'Enter access keys and the repository password they were made with to pick them up.'}
                            </p>
                        )}
                        <DestinationForm
                            url={page.storage.add.url}
                            method="post"
                            provider={provider}
                            initial={
                                reconnect !== null
                                    ? {
                                          name: reconnect.name,
                                          endpoint: reconnect.endpoint,
                                          bucket: reconnect.bucket,
                                          prefix: reconnect.prefix,
                                      }
                                    : undefined
                            }
                            askRepositoryPassword={
                                reconnect !== null && !reconnect.passwordKept
                            }
                            canProtectLaunchroom={
                                page.storage.canProtectLaunchroom
                            }
                            submitLabel="Connect and test"
                            secondary={
                                reconnect === null && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={() => setStep('where')}
                                    >
                                        Back
                                    </Button>
                                )
                            }
                            onSuccess={(values) => {
                                setProtect(values.protectLaunchroom);
                                setStep('testing');
                            }}
                        />
                    </div>
                )}
                {step === 'connect' &&
                    page.storage.add.url === null &&
                    page.storage.add.disabled !== null && (
                        <ActionReason reason={page.storage.add.disabled} />
                    )}

                {step === 'testing' && (
                    <TestingStep
                        destination={created}
                        onFix={() => {
                            setCreatedId(created?.id ?? null);
                            setStep('fix');
                        }}
                        onContinue={afterTest}
                    />
                )}

                {step === 'fix' && created?.updateUrl != null && (
                    <DestinationForm
                        url={created.updateUrl}
                        method="put"
                        destination={created}
                        askRepositoryPassword={
                            created.errorKind === 'wrong_repository_password'
                        }
                        submitLabel="Save and test again"
                        onSuccess={() => setStep('testing')}
                    />
                )}

                {step === 'launchroom' && (
                    <LaunchroomStep
                        page={page}
                        onDone={() => setStep('done')}
                    />
                )}

                {step === 'done' && (
                    <DoneStep page={page} onClose={() => onOpenChange(false)} />
                )}
            </SheetSection>
        </SidePanel>
    );
}
