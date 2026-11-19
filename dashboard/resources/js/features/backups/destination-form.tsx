import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { Destination } from './page-types';

export type Provider = 'r2' | 'hetzner' | 's3' | 'b2' | 'other';

/** Where the S3 endpoint and region come from, per provider (docs/14 §3). */
export const providers: Record<
    Provider,
    {
        label: string;
        logo: string;
        description: string;
        endpoint: string;
        region: string;
        hint: string;
    }
> = {
    r2: {
        label: 'Cloudflare R2',
        logo: 'cloudflare',
        description: 'No fees for downloads',
        endpoint: 'https://<account-id>.r2.cloudflarestorage.com',
        region: 'auto',
        hint: 'R2, Manage API tokens: Object Read & Write on this bucket.',
    },
    hetzner: {
        label: 'Hetzner',
        logo: 'hetzner',
        description: 'Storage in the EU',
        endpoint: 'https://fsn1.your-objectstorage.com',
        region: 'fsn1',
        hint: 'Object Storage, S3 credentials. Regions: fsn1, nbg1, hel1.',
    },
    s3: {
        label: 'Amazon S3',
        logo: 's3',
        description: 'Any AWS region',
        endpoint: 'https://s3.eu-central-1.amazonaws.com',
        region: 'eu-central-1',
        hint: 'An IAM key that can read and write this bucket.',
    },
    b2: {
        label: 'Backblaze B2',
        logo: 'backblaze',
        description: 'Low storage price',
        endpoint: 'https://s3.eu-central-003.backblazeb2.com',
        region: 'eu-central-003',
        hint: 'An application key limited to this bucket.',
    },
    other: {
        label: 'Other S3-compatible storage',
        logo: 's3',
        description: 'MinIO, Wasabi, Scaleway and others',
        endpoint: 'https://s3.example.com',
        region: '',
        hint: 'The S3 API URL without the bucket name.',
    },
};

function Field({
    id,
    label,
    helper,
    error,
    children,
}: {
    id: string;
    label: string;
    helper?: ReactNode;
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

export type StorageFormValues = {
    name: string;
    endpoint: string;
    bucket: string;
    region: string;
    prefix: string;
    accessKeyId: string;
    secretAccessKey: string;
    repositoryPassword: string;
    allowPrivateNetwork: boolean;
    makeDefault: boolean;
    protectLaunchroom: boolean;
    scope: App.Enums.Backups.DestinationScope;
};

/**
 * Connect (or edit) an S3-compatible bucket (docs/14 §3). Errors land on
 * their field ("This bucket and folder already hold backups of another
 * storage"), never in a toast. Credentials are write-only: on edit, blank
 * keeps the stored ones. The repository password is asked for only when
 * restic refused the stored one or launchroom doesn't have it; otherwise it
 * is an Advanced option for a folder with backups made elsewhere (adding
 * removed storage again reuses the password launchroom kept).
 */
export function DestinationForm({
    url,
    method,
    provider = 'other',
    destination,
    initial,
    canProtectLaunchroom = false,
    askRepositoryPassword = false,
    submitLabel,
    secondary,
    onSuccess,
}: {
    url: string;
    method: 'post' | 'put';
    provider?: Provider;
    /** Editing: its current values, keys left blank. */
    destination?: Destination;
    /** Reconnecting removed storage: endpoint, bucket and folder. */
    initial?: Partial<StorageFormValues>;
    canProtectLaunchroom?: boolean;
    askRepositoryPassword?: boolean;
    submitLabel: string;
    /** A Back button left of the submit. */
    secondary?: ReactNode;
    onSuccess?: (values: StorageFormValues) => void;
}) {
    const editing = destination !== undefined;
    const preset = providers[provider];
    const [reconnecting, setReconnecting] = useState(askRepositoryPassword);
    const form = useForm<StorageFormValues>({
        name: destination?.name ?? (provider === 'other' ? '' : preset.label),
        endpoint: destination?.endpoint ?? initial?.endpoint ?? '',
        bucket: destination?.bucket ?? initial?.bucket ?? '',
        region: destination?.region ?? initial?.region ?? preset.region,
        prefix: destination?.prefix ?? initial?.prefix ?? '',
        accessKeyId: '',
        secretAccessKey: '',
        repositoryPassword: '',
        allowPrivateNetwork: destination?.allowPrivateNetwork ?? false,
        makeDefault: false,
        protectLaunchroom: canProtectLaunchroom,
        scope: destination?.scope ?? 'team',
        ...initial,
    });
    const id = destination?.id ?? 'new';
    const repositoryField = (
        <Field
            id={`${id}-repository`}
            label="Repository password"
            helper={
                askRepositoryPassword
                    ? 'restic opens the backups in this folder only with the password they were made with.'
                    : undefined
            }
            error={form.errors.repositoryPassword}
        >
            <Input
                id={`${id}-repository`}
                type="password"
                autoComplete="off"
                className="font-mono"
                value={form.data.repositoryPassword}
                onChange={(event) =>
                    form.setData('repositoryPassword', event.target.value)
                }
                aria-invalid={form.errors.repositoryPassword ? true : undefined}
            />
        </Field>
    );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            region: data.region === '' ? null : data.region,
            accessKeyId: data.accessKeyId === '' ? null : data.accessKeyId,
            secretAccessKey:
                data.secretAccessKey === '' ? null : data.secretAccessKey,
            repositoryPassword:
                reconnecting && data.repositoryPassword !== ''
                    ? data.repositoryPassword
                    : null,
        }));
        form.submit(method, url, {
            preserveScroll: true,
            onSuccess: () => onSuccess?.(form.data),
        });
    };

    return (
        <form onSubmit={submit} className="grid gap-5" noValidate>
            {!editing && (
                <Field id={`${id}-name`} label="Name" error={form.errors.name}>
                    <Input
                        id={`${id}-name`}
                        required
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        placeholder="Off-site backups"
                    />
                </Field>
            )}

            <Field
                id={`${id}-endpoint`}
                label="Endpoint"
                helper={editing ? undefined : preset.hint}
                error={form.errors.endpoint}
            >
                <Input
                    id={`${id}-endpoint`}
                    required
                    className="font-mono"
                    value={form.data.endpoint}
                    onChange={(event) =>
                        form.setData('endpoint', event.target.value)
                    }
                    placeholder={preset.endpoint}
                    aria-invalid={form.errors.endpoint ? true : undefined}
                />
            </Field>

            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    id={`${id}-bucket`}
                    label="Bucket"
                    error={form.errors.bucket}
                >
                    <Input
                        id={`${id}-bucket`}
                        required
                        className="font-mono"
                        value={form.data.bucket}
                        onChange={(event) =>
                            form.setData('bucket', event.target.value)
                        }
                        aria-invalid={form.errors.bucket ? true : undefined}
                    />
                </Field>
                <Field
                    id={`${id}-prefix`}
                    label="Folder (optional)"
                    error={form.errors.prefix}
                >
                    <Input
                        id={`${id}-prefix`}
                        className="font-mono"
                        value={form.data.prefix}
                        onChange={(event) =>
                            form.setData('prefix', event.target.value)
                        }
                        aria-invalid={form.errors.prefix ? true : undefined}
                    />
                </Field>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    id={`${id}-access`}
                    label="Access key ID"
                    error={form.errors.accessKeyId}
                >
                    <Input
                        id={`${id}-access`}
                        className="font-mono"
                        autoComplete="off"
                        required={!editing}
                        placeholder={editing ? 'Unchanged' : ''}
                        value={form.data.accessKeyId}
                        onChange={(event) =>
                            form.setData('accessKeyId', event.target.value)
                        }
                    />
                </Field>
                <Field
                    id={`${id}-secret`}
                    label="Secret access key"
                    error={form.errors.secretAccessKey}
                >
                    <Input
                        id={`${id}-secret`}
                        type="password"
                        autoComplete="new-password"
                        required={!editing}
                        placeholder={editing ? 'Unchanged' : ''}
                        value={form.data.secretAccessKey}
                        onChange={(event) =>
                            form.setData('secretAccessKey', event.target.value)
                        }
                    />
                </Field>
            </div>

            <Field
                id={`${id}-region`}
                label="Region"
                error={form.errors.region}
            >
                <Input
                    id={`${id}-region`}
                    className="font-mono sm:max-w-60"
                    value={form.data.region}
                    onChange={(event) =>
                        form.setData('region', event.target.value)
                    }
                />
            </Field>

            <div className="flex flex-col gap-3">
                {askRepositoryPassword && repositoryField}

                {canProtectLaunchroom && !editing && (
                    <div className="flex items-start gap-2 rounded-lg border bg-recessed p-3">
                        <Checkbox
                            id={`${id}-launchroom`}
                            checked={form.data.protectLaunchroom}
                            onCheckedChange={(checked) =>
                                form.setData(
                                    'protectLaunchroom',
                                    checked === true,
                                )
                            }
                        />
                        <div className="grid gap-1">
                            <Label htmlFor={`${id}-launchroom`}>
                                Also protect launchroom itself
                            </Label>
                            <p className="type-small text-muted-foreground">
                                Projects, settings and keys go to the same
                                bucket, in their own folder{' '}
                                <code className="type-mono-small">
                                    {form.data.prefix === ''
                                        ? 'launchroom/'
                                        : `${form.data.prefix.replace(/\/$/, '')}/launchroom/`}
                                </code>
                                .
                            </p>
                        </div>
                    </div>
                )}

                <details className="group">
                    <summary className="cursor-pointer type-small text-muted-foreground hover:text-foreground">
                        Advanced
                    </summary>
                    {!askRepositoryPassword && (
                        <div className="mt-3 flex flex-col gap-3">
                            <div className="flex items-start gap-2">
                                <Checkbox
                                    id={`${id}-reconnect`}
                                    checked={reconnecting}
                                    onCheckedChange={(checked) =>
                                        setReconnecting(checked === true)
                                    }
                                />
                                <div className="grid gap-1">
                                    <Label htmlFor={`${id}-reconnect`}>
                                        Use an existing repository password
                                    </Label>
                                    <p className="type-small text-muted-foreground">
                                        For a folder that already holds backups
                                        made with restic or another launchroom.
                                        Otherwise launchroom creates the
                                        password.
                                    </p>
                                </div>
                            </div>
                            {reconnecting && repositoryField}
                        </div>
                    )}
                    <div className="mt-3 flex items-start gap-2">
                        <Checkbox
                            id={`${id}-private`}
                            checked={form.data.allowPrivateNetwork}
                            onCheckedChange={(checked) =>
                                form.setData(
                                    'allowPrivateNetwork',
                                    checked === true,
                                )
                            }
                        />
                        <div className="grid gap-1">
                            <Label htmlFor={`${id}-private`}>
                                Allow a private network or plain http
                            </Label>
                            <p className="type-small text-muted-foreground">
                                Only instance owners can turn this on, and it is
                                recorded in the audit log. Plain http also needs
                                the host in LR_BACKUP_INSECURE_HOSTS on the
                                servers.
                            </p>
                            <InputError
                                message={form.errors.allowPrivateNetwork}
                            />
                        </div>
                    </div>
                </details>
            </div>

            <InputError
                message={
                    (form.errors as Record<string, string | undefined>).general
                }
            />

            <div className="flex items-center justify-end gap-2">
                {secondary}
                <Button type="submit" disabled={form.processing}>
                    {form.processing && <Spinner />}
                    {submitLabel}
                </Button>
            </div>
        </form>
    );
}
