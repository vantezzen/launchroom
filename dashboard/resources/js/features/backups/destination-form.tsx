import { Form } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Logo } from '@/components/logo';
import { ChoiceTiles } from '@/components/patterns/choice-tiles';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store, update } from '@/routes/backups/destinations';

type Destination = App.Data.Backups.BackupDestinationData;

type Provider = 'r2' | 'hetzner' | 'b2' | 'other';

/** Where the S3 endpoint and region come from, per provider (docs/14 §3). */
const providers: Record<
    Provider,
    {
        label: string;
        logo: string;
        endpoint: string;
        region: string;
        hint: string;
    }
> = {
    r2: {
        label: 'Cloudflare R2',
        logo: 'cloudflare',
        endpoint: 'https://<account-id>.r2.cloudflarestorage.com',
        region: 'auto',
        hint: 'R2 → Manage API tokens: Object Read & Write on this bucket.',
    },
    hetzner: {
        label: 'Hetzner',
        logo: 'hetzner',
        endpoint: 'https://fsn1.your-objectstorage.com',
        region: 'fsn1',
        hint: 'Object Storage → S3 credentials. Regions: fsn1, nbg1, hel1.',
    },
    b2: {
        label: 'Backblaze B2',
        logo: 'backblaze',
        endpoint: 'https://s3.eu-central-003.backblazeb2.com',
        region: 'eu-central-003',
        hint: 'An application key limited to this bucket.',
    },
    other: {
        label: 'Other S3',
        logo: 's3',
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
 * Add or edit an S3-compatible destination (docs/14 §3). A new one starts
 * with "choose your storage" tiles that fill in the endpoint and region.
 * Credentials are write-only: on edit, leaving them blank keeps the stored
 * ones.
 */
export function DestinationForm({
    destination,
    scope,
    onSuccess,
}: {
    destination?: Destination;
    scope: App.Enums.Backups.DestinationScope;
    onSuccess?: () => void;
}) {
    const editing = destination !== undefined;
    const prefix = destination?.id ?? scope;
    const [provider, setProvider] = useState<Provider>('r2');
    const [endpoint, setEndpoint] = useState(destination?.endpoint ?? '');
    const [region, setRegion] = useState(
        destination?.region ?? providers.r2.region,
    );
    const [name, setName] = useState(destination?.name ?? providers.r2.label);
    const preset = providers[provider];

    const choose = (next: Provider) => {
        setProvider(next);
        setRegion(providers[next].region);

        if (Object.values(providers).some((p) => p.label === name)) {
            setName(next === 'other' ? '' : providers[next].label);
        }
    };

    return (
        <Form
            {...(editing ? update.form(destination.id) : store.form())}
            options={{ preserveScroll: true }}
            onSuccess={() => onSuccess?.()}
            className="grid gap-5"
            disableWhileProcessing
        >
            {({ errors, processing }) => (
                <>
                    {!editing && (
                        <>
                            <input type="hidden" name="scope" value={scope} />
                            <ChoiceTiles
                                label="Storage provider"
                                columns={4}
                                value={provider}
                                onChange={choose}
                                options={(
                                    Object.keys(providers) as Provider[]
                                ).map((key) => ({
                                    value: key,
                                    label: providers[key].label,
                                    media: (
                                        <Logo
                                            kind={providers[key].logo}
                                            size={32}
                                            decorative
                                        />
                                    ),
                                }))}
                            />
                        </>
                    )}

                    <Field
                        id={`${prefix}-name`}
                        label="Name"
                        error={errors.name}
                    >
                        <Input
                            id={`${prefix}-name`}
                            name="name"
                            required
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                            placeholder="Off-site backups"
                        />
                    </Field>

                    <Field
                        id={`${prefix}-endpoint`}
                        label="Endpoint"
                        helper={editing ? undefined : preset.hint}
                        error={errors.endpoint}
                    >
                        <Input
                            id={`${prefix}-endpoint`}
                            name="endpoint"
                            required
                            className="font-mono"
                            value={endpoint}
                            onChange={(event) =>
                                setEndpoint(event.target.value)
                            }
                            placeholder={preset.endpoint}
                        />
                    </Field>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            id={`${prefix}-bucket`}
                            label="Bucket"
                            error={errors.bucket}
                        >
                            <Input
                                id={`${prefix}-bucket`}
                                name="bucket"
                                required
                                className="font-mono"
                                defaultValue={destination?.bucket ?? ''}
                            />
                        </Field>
                        <Field
                            id={`${prefix}-region`}
                            label="Region"
                            error={errors.region}
                        >
                            <Input
                                id={`${prefix}-region`}
                                name="region"
                                className="font-mono"
                                value={region}
                                onChange={(event) =>
                                    setRegion(event.target.value)
                                }
                            />
                        </Field>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            id={`${prefix}-access`}
                            label="Access key ID"
                            error={errors.accessKeyId}
                        >
                            <Input
                                id={`${prefix}-access`}
                                name="accessKeyId"
                                className="font-mono"
                                autoComplete="off"
                                required={!editing}
                                placeholder={editing ? 'Unchanged' : ''}
                            />
                        </Field>
                        <Field
                            id={`${prefix}-secret`}
                            label="Secret access key"
                            error={errors.secretAccessKey}
                        >
                            <Input
                                id={`${prefix}-secret`}
                                name="secretAccessKey"
                                type="password"
                                autoComplete="new-password"
                                required={!editing}
                                placeholder={editing ? 'Unchanged' : ''}
                            />
                        </Field>
                    </div>

                    <Field
                        id={`${prefix}-prefix`}
                        label="Folder (optional)"
                        error={errors.prefix}
                    >
                        <Input
                            id={`${prefix}-prefix`}
                            name="prefix"
                            className="font-mono"
                            defaultValue={destination?.prefix ?? ''}
                        />
                    </Field>

                    <div className="flex flex-col gap-3">
                        {scope === 'team' &&
                            !(destination?.isDefault ?? false) && (
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id={`${prefix}-default`}
                                        name="makeDefault"
                                        value="1"
                                    />
                                    <Label htmlFor={`${prefix}-default`}>
                                        Use for new database backups
                                    </Label>
                                </div>
                            )}
                        <div className="flex items-start gap-2">
                            <Checkbox
                                id={`${prefix}-private`}
                                name="allowPrivateNetwork"
                                value="1"
                                defaultChecked={
                                    destination?.allowPrivateNetwork ?? false
                                }
                            />
                            <div className="grid gap-1">
                                <Label htmlFor={`${prefix}-private`}>
                                    Allow a private network or plain http
                                </Label>
                                <p className="type-small text-muted-foreground">
                                    Only instance owners can turn this on, and
                                    it is recorded in the audit log. Plain http
                                    also needs the host in
                                    LR_BACKUP_INSECURE_HOSTS on the servers.
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
                            {editing ? 'Save destination' : 'Add destination'}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
