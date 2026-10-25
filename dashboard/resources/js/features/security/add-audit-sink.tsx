import { useForm } from '@inertiajs/react';
import { Archive, ScrollText, Webhook } from 'lucide-react';
import InputError from '@/components/input-error';
import { ChoiceTiles } from '@/components/patterns/choice-tiles';
import type { ChoiceTile } from '@/components/patterns/choice-tiles';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { store } from '@/routes/audit-sinks';

type Kind = App.Enums.Audit.AuditSinkKind;

const tile = (Icon: typeof Webhook) => (
    <span className="flex size-8 items-center justify-center rounded-md border bg-card text-muted-foreground">
        <Icon aria-hidden="true" className="size-4" />
    </span>
);

export const SINK_KINDS: ChoiceTile<Kind>[] = [
    {
        value: 'syslog',
        label: 'Syslog',
        description: 'Over TLS to your log server',
        media: tile(ScrollText),
    },
    {
        value: 'webhook',
        label: 'Webhook',
        description: 'Signed JSON to any URL',
        media: tile(Webhook),
    },
    {
        value: 's3',
        label: 'S3 bucket',
        description: 'Append-only files, lockable',
        media: tile(Archive),
    },
];

type Form = {
    kind: Kind | null;
    name: string;
    host: string;
    port: string;
    caCertificate: string;
    secretUrl: string;
    endpoint: string;
    bucket: string;
    region: string;
    prefix: string;
    accessKeyId: string;
    secretAccessKey: string;
    retentionDays: string;
    lockMode: 'GOVERNANCE' | 'COMPLIANCE';
    allowPrivateNetwork: boolean;
};

function Field({
    id,
    label,
    hint,
    error,
    children,
}: {
    id: string;
    label: string;
    hint?: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid content-start gap-2">
            <Label htmlFor={id}>{label}</Label>
            {children}
            {hint && <p className="type-small text-muted-foreground">{hint}</p>}
            <InputError message={error} />
        </div>
    );
}

/**
 * "Where should a copy go?" (docs/09 R34): the three kinds as tiles, then
 * only the fields the chosen kind needs. A new destination starts with the
 * first entry and catches up on its own.
 */
export function AddAuditSink({
    kind,
    onKindChange,
    onAdded,
}: {
    kind: Kind | null;
    onKindChange: (kind: Kind) => void;
    onAdded?: () => void;
}) {
    const form = useForm<Form>({
        kind,
        name: '',
        host: '',
        port: '6514',
        caCertificate: '',
        secretUrl: '',
        endpoint: '',
        bucket: '',
        region: '',
        prefix: '',
        accessKeyId: '',
        secretAccessKey: '',
        retentionDays: '',
        lockMode: 'GOVERNANCE',
        allowPrivateNetwork: false,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const set =
        <K extends keyof Form>(key: K) =>
        (value: Form[K]) =>
            form.setData((data) => ({ ...data, [key]: value }));

    const choose = (next: Kind) => {
        onKindChange(next);
        form.setData('kind', next);
        form.clearErrors();
    };

    const submit = () => {
        form.transform((data) => {
            const base = {
                kind: data.kind,
                name: data.name || null,
                allowPrivateNetwork: data.allowPrivateNetwork,
            };

            if (data.kind === 'syslog') {
                return {
                    ...base,
                    host: data.host,
                    port: Number(data.port) || null,
                    caCertificate: data.caCertificate || null,
                };
            }

            if (data.kind === 'webhook') {
                return { ...base, secretUrl: data.secretUrl };
            }

            const days = Number(data.retentionDays) || 0;

            return {
                ...base,
                endpoint: data.endpoint,
                bucket: data.bucket,
                region: data.region || null,
                prefix: data.prefix || null,
                accessKeyId: data.accessKeyId,
                secretAccessKey: data.secretAccessKey,
                retentionDays: days > 0 ? days : null,
                lockMode: days > 0 ? data.lockMode : null,
            };
        });
        form.post(store().url, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('secretUrl', 'accessKeyId', 'secretAccessKey');
                onAdded?.();
            },
        });
    };

    return (
        <div className="flex flex-col gap-6">
            <ChoiceTiles<Kind>
                label="Where should a copy go?"
                options={SINK_KINDS}
                value={kind}
                onChange={choose}
                columns={3}
            />

            {kind !== null && (
                <form
                    className="flex animate-enter flex-col gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        submit();
                    }}
                >
                    {kind === 'syslog' && (
                        <>
                            <div className="grid gap-4 sm:grid-cols-[1fr_7rem]">
                                <Field
                                    id="sink-host"
                                    label="Host"
                                    error={errors.host}
                                >
                                    <Input
                                        id="sink-host"
                                        placeholder="logs.example.com"
                                        value={form.data.host}
                                        onChange={(e) =>
                                            set('host')(e.target.value)
                                        }
                                        autoComplete="off"
                                    />
                                </Field>
                                <Field
                                    id="sink-port"
                                    label="Port"
                                    error={errors.port}
                                >
                                    <Input
                                        id="sink-port"
                                        inputMode="numeric"
                                        value={form.data.port}
                                        onChange={(e) =>
                                            set('port')(e.target.value)
                                        }
                                    />
                                </Field>
                            </div>
                            <Field
                                id="sink-ca"
                                label="CA certificate (optional)"
                                hint="Only when your log server's certificate isn't from a public CA. PEM, one certificate."
                                error={errors.caCertificate}
                            >
                                <Textarea
                                    id="sink-ca"
                                    rows={4}
                                    className="type-mono-small"
                                    placeholder="-----BEGIN CERTIFICATE-----"
                                    value={form.data.caCertificate}
                                    onChange={(e) =>
                                        set('caCertificate')(e.target.value)
                                    }
                                />
                            </Field>
                        </>
                    )}

                    {kind === 'webhook' && (
                        <Field
                            id="sink-url"
                            label="URL"
                            hint="Batches are signed. You see the signing secret once, right after adding it."
                            error={errors.secretUrl}
                        >
                            <Input
                                id="sink-url"
                                placeholder="https://siem.example.com/launchroom"
                                value={form.data.secretUrl}
                                onChange={(e) =>
                                    set('secretUrl')(e.target.value)
                                }
                                autoComplete="off"
                            />
                        </Field>
                    )}

                    {kind === 's3' && (
                        <>
                            <Field
                                id="sink-endpoint"
                                label="Endpoint"
                                error={errors.endpoint}
                            >
                                <Input
                                    id="sink-endpoint"
                                    placeholder="https://s3.eu-central-1.amazonaws.com"
                                    value={form.data.endpoint}
                                    onChange={(e) =>
                                        set('endpoint')(e.target.value)
                                    }
                                />
                            </Field>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    id="sink-bucket"
                                    label="Bucket"
                                    error={errors.bucket}
                                >
                                    <Input
                                        id="sink-bucket"
                                        value={form.data.bucket}
                                        onChange={(e) =>
                                            set('bucket')(e.target.value)
                                        }
                                    />
                                </Field>
                                <Field
                                    id="sink-region"
                                    label="Region"
                                    error={errors.region}
                                >
                                    <Input
                                        id="sink-region"
                                        placeholder="us-east-1"
                                        value={form.data.region}
                                        onChange={(e) =>
                                            set('region')(e.target.value)
                                        }
                                    />
                                </Field>
                                <Field
                                    id="sink-access"
                                    label="Access key"
                                    error={errors.accessKeyId}
                                >
                                    <Input
                                        id="sink-access"
                                        value={form.data.accessKeyId}
                                        onChange={(e) =>
                                            set('accessKeyId')(e.target.value)
                                        }
                                        autoComplete="off"
                                    />
                                </Field>
                                <Field
                                    id="sink-secret"
                                    label="Secret key"
                                    error={errors.secretAccessKey}
                                >
                                    <Input
                                        id="sink-secret"
                                        type="password"
                                        value={form.data.secretAccessKey}
                                        onChange={(e) =>
                                            set('secretAccessKey')(
                                                e.target.value,
                                            )
                                        }
                                        autoComplete="off"
                                    />
                                </Field>
                            </div>
                            <Field
                                id="sink-prefix"
                                label="Folder (optional)"
                                error={errors.prefix}
                            >
                                <Input
                                    id="sink-prefix"
                                    placeholder="audit"
                                    value={form.data.prefix}
                                    onChange={(e) =>
                                        set('prefix')(e.target.value)
                                    }
                                />
                            </Field>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    id="sink-retention"
                                    label="Lock each file for (days)"
                                    hint="Needs Object Lock on the bucket. Leave empty to not lock."
                                    error={errors.retentionDays}
                                >
                                    <Input
                                        id="sink-retention"
                                        inputMode="numeric"
                                        placeholder="365"
                                        value={form.data.retentionDays}
                                        onChange={(e) =>
                                            set('retentionDays')(e.target.value)
                                        }
                                    />
                                </Field>
                                {Number(form.data.retentionDays) > 0 && (
                                    <Field
                                        id="sink-lock-mode"
                                        label="Lock mode"
                                        hint="Compliance can't be shortened, not even by the bucket's owner."
                                        error={errors.lockMode}
                                    >
                                        <select
                                            id="sink-lock-mode"
                                            className="h-9 rounded-md border bg-background px-3 type-body"
                                            value={form.data.lockMode}
                                            onChange={(e) =>
                                                set('lockMode')(
                                                    e.target
                                                        .value as Form['lockMode'],
                                                )
                                            }
                                        >
                                            <option value="GOVERNANCE">
                                                Governance
                                            </option>
                                            <option value="COMPLIANCE">
                                                Compliance
                                            </option>
                                        </select>
                                    </Field>
                                )}
                            </div>
                        </>
                    )}

                    <Field
                        id="sink-name"
                        label="Name (optional)"
                        error={errors.name}
                    >
                        <Input
                            id="sink-name"
                            value={form.data.name}
                            onChange={(e) => set('name')(e.target.value)}
                        />
                    </Field>

                    <div className="flex items-start gap-2">
                        <Checkbox
                            id="sink-private"
                            checked={form.data.allowPrivateNetwork}
                            onCheckedChange={(checked) =>
                                set('allowPrivateNetwork')(checked === true)
                            }
                        />
                        <div className="grid gap-1">
                            <Label htmlFor="sink-private">
                                It's on my own network
                            </Label>
                            <p className="type-small text-muted-foreground">
                                Allows private addresses (10.x, 192.168.x, your
                                servers' private network). This choice is
                                recorded in the audit log.
                            </p>
                        </div>
                    </div>

                    <InputError message={errors.general} />

                    <div>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            Add destination
                        </Button>
                    </div>
                </form>
            )}
        </div>
    );
}
