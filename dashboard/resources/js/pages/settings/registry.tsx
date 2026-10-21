import { Form, Head } from '@inertiajs/react';
import { Cloud, HardDrive } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { CopyValue } from '@/components/copy-value';
import InputError from '@/components/input-error';
import { OptionCards } from '@/components/patterns/option-cards';
import { StatusPill } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    LearnMore,
    SettingsBody,
    SettingsCard,
    SettingsRow,
    useDocsUrl,
} from '@/features/settings/settings-card';
import { destroy, edit, update } from '@/routes/registry-settings';
import { edit as teamSettings } from '@/routes/team';

type Props = {
    settings: App.Data.Registry.RegistrySettingsData;
};

type Mode = 'disk' | 's3';

function Field({
    id,
    label,
    helper,
    error,
    children,
}: {
    id: string;
    label: string;
    helper?: string;
    error?: string;
    children: ReactNode;
}) {
    return (
        <div className="grid content-start gap-2">
            <Label htmlFor={id}>{label}</Label>
            {children}
            {helper && (
                <p className="type-small text-muted-foreground">{helper}</p>
            )}
            <InputError message={error} />
        </div>
    );
}

/**
 * Settings → Registry (docs/14 §9.11), instance owners only: where the
 * registry keeps app images, as a mode choice (this server's disk or your
 * own S3 bucket) with the details below.
 */
export default function RegistrySettings({ settings }: Props) {
    const docsUrl = useDocsUrl();
    const { storage, applied, writtenAt, applyCommand } = settings;
    const current: Mode = storage === null ? 'disk' : 's3';
    const [mode, setMode] = useState<Mode>(current);
    const [confirmDisk, setConfirmDisk] = useState(false);

    const choose = (next: Mode) => {
        setMode(next);

        if (next === 'disk' && current === 's3') {
            setConfirmDisk(true);
        }
    };

    return (
        <>
            <Head title="Registry" />

            <section className="flex flex-col gap-3.5">
                <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <h2 className="type-heading">Image storage</h2>
                    <p className="type-body text-muted-foreground">
                        Where your app images and build cache live.
                    </p>
                </div>
                <OptionCards
                    label="Image storage"
                    value={mode}
                    onChange={choose}
                    options={[
                        {
                            value: 'disk',
                            title: 'This server',
                            description:
                                'Fast and free. Lost with the server, then rebuilt from git.',
                            media: (
                                <HardDrive
                                    aria-hidden="true"
                                    className="size-5 text-muted-foreground"
                                />
                            ),
                        },
                        {
                            value: 's3',
                            title: 'Your S3 bucket',
                            description:
                                'Survives the server. Slower pulls and more storage used.',
                            media: (
                                <Cloud
                                    aria-hidden="true"
                                    className="size-5 text-muted-foreground"
                                />
                            ),
                        },
                    ]}
                />
            </section>

            {applied === false && (
                <SettingsCard
                    title="One step left"
                    actions={
                        <StatusPill
                            state="warning"
                            label="Saved, not in use yet"
                        />
                    }
                >
                    <SettingsBody className="flex flex-col gap-3">
                        <p className="type-body text-muted-foreground">
                            Run this on the main server. The registry restarts
                            and pushes pause for a few seconds.
                        </p>
                        <CopyValue value={applyCommand} label="apply command" />
                    </SettingsBody>
                </SettingsCard>
            )}

            {mode === 's3' && (
                <SettingsCard
                    title="Bucket"
                    actions={
                        applied === true &&
                        storage !== null && (
                            <StatusPill state="active" label="In use" />
                        )
                    }
                    footer={
                        <>
                            Images already stored aren’t copied over; each
                            service rebuilds on its next deploy.{' '}
                            <LearnMore
                                href={docsUrl('builds/how-builds-work')}
                            />
                        </>
                    }
                >
                    {storage !== null && (
                        <SettingsRow
                            label={
                                <span className="type-mono">
                                    {storage.bucket}/{storage.prefix}
                                </span>
                            }
                            description={
                                writtenAt === null ? (
                                    'Config written by the installer'
                                ) : (
                                    <>
                                        Config written{' '}
                                        <TimeAgo value={writtenAt} />
                                    </>
                                )
                            }
                        />
                    )}
                    <SettingsBody>
                        <StorageForm storage={storage} />
                    </SettingsBody>
                </SettingsCard>
            )}

            <Dialog
                open={confirmDisk}
                onOpenChange={(open) => {
                    setConfirmDisk(open);

                    if (!open) {
                        setMode(current);
                    }
                }}
            >
                <DialogContent>
                    <DialogTitle>
                        Store images on this server again?
                    </DialogTitle>
                    <DialogDescription>
                        The bucket and its images stay as they are. After the
                        registry restarts, each service builds or mirrors its
                        image again on its next deploy.
                    </DialogDescription>
                    <Form
                        {...destroy.form()}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setConfirmDisk(false)}
                    >
                        {({ processing }) => (
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="ghost" type="button">
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    Use this server’s disk
                                </Button>
                            </DialogFooter>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
        </>
    );
}

function StorageForm({
    storage,
}: {
    storage: App.Data.Registry.RegistryStorageData | null;
}) {
    const editing = storage !== null;

    return (
        <Form
            {...update.form()}
            options={{ preserveScroll: true }}
            className="grid gap-4"
            disableWhileProcessing
        >
            {({ errors, processing }) => (
                <>
                    <Field
                        id="registry-endpoint"
                        label="Endpoint"
                        helper="The S3 API URL without the bucket name."
                        error={errors.endpoint}
                    >
                        <Input
                            id="registry-endpoint"
                            name="endpoint"
                            required
                            className="font-mono"
                            placeholder="https://<account>.r2.cloudflarestorage.com"
                            defaultValue={storage?.endpoint ?? ''}
                        />
                    </Field>

                    <div className="grid gap-4 sm:grid-cols-3">
                        <Field
                            id="registry-bucket"
                            label="Bucket"
                            helper="Not your backup bucket."
                            error={errors.bucket}
                        >
                            <Input
                                id="registry-bucket"
                                name="bucket"
                                required
                                className="font-mono"
                                defaultValue={storage?.bucket ?? ''}
                            />
                        </Field>
                        <Field
                            id="registry-region"
                            label="Region"
                            helper="R2: auto"
                            error={errors.region}
                        >
                            <Input
                                id="registry-region"
                                name="region"
                                className="font-mono"
                                defaultValue={storage?.region ?? ''}
                            />
                        </Field>
                        <Field
                            id="registry-prefix"
                            label="Folder"
                            error={errors.prefix}
                        >
                            <Input
                                id="registry-prefix"
                                name="prefix"
                                className="font-mono"
                                defaultValue={storage?.prefix ?? 'registry'}
                            />
                        </Field>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            id="registry-access"
                            label="Access key ID"
                            error={errors.accessKeyId}
                        >
                            <Input
                                id="registry-access"
                                name="accessKeyId"
                                className="font-mono"
                                autoComplete="off"
                                required={!editing}
                                placeholder={editing ? 'Unchanged' : ''}
                            />
                        </Field>
                        <Field
                            id="registry-secret"
                            label="Secret access key"
                            error={errors.secretAccessKey}
                        >
                            <Input
                                id="registry-secret"
                                name="secretAccessKey"
                                type="password"
                                autoComplete="new-password"
                                required={!editing}
                                placeholder={editing ? 'Unchanged' : ''}
                            />
                        </Field>
                    </div>

                    <div className="flex flex-col gap-3">
                        <div className="flex items-start gap-2">
                            <Checkbox
                                id="registry-path-style"
                                name="forcePathStyle"
                                value="1"
                                defaultChecked={storage?.forcePathStyle ?? true}
                            />
                            <div className="grid gap-1">
                                <Label htmlFor="registry-path-style">
                                    Path-style requests
                                </Label>
                                <p className="type-small text-muted-foreground">
                                    Most S3-compatible providers need it; some
                                    AWS buckets don’t.
                                </p>
                            </div>
                        </div>
                        <div className="flex items-start gap-2">
                            <Checkbox
                                id="registry-private"
                                name="allowPrivateNetwork"
                                value="1"
                                defaultChecked={
                                    storage?.allowPrivateNetwork ?? false
                                }
                            />
                            <div className="grid gap-1">
                                <Label htmlFor="registry-private">
                                    Allow a private network or plain http
                                </Label>
                                <p className="type-small text-muted-foreground">
                                    For storage on your LAN. Recorded in the
                                    audit log.
                                </p>
                                <InputError
                                    message={errors.allowPrivateNetwork}
                                />
                            </div>
                        </div>
                    </div>

                    <InputError message={errors.general} />

                    <div>
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner />}
                            {editing ? 'Save bucket' : 'Use this bucket'}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

RegistrySettings.layout = {
    breadcrumbs: [
        { title: 'Settings', href: teamSettings() },
        {
            title: 'Registry',
            href: edit(),
        },
    ],
};
