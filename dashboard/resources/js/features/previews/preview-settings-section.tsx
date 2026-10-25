import { router } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import { CopyValue } from '@/components/copy-value';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatArgv, parseArgv } from '@/features/projects/argv';
import { OptionCards } from '@/components/patterns/option-cards';
import { Section } from '@/components/patterns/section';
import { UnsavedBar } from '@/components/patterns/unsaved-bar';
import { Switch } from '@/components/ui/switch';
import {
    SettingsBody,
    SettingsCard,
    SettingsRow,
} from '@/features/settings/settings-card';
import { bypass, settings, strategy } from '@/routes/projects/previews';
import { formatBytes } from './status';

type Settings = App.Data.Previews.PreviewSettingsData;

const AUTO = 'auto';

function Field({
    id,
    label,
    help,
    error,
    children,
}: {
    id: string;
    label: string;
    help?: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            {children}
            {help && <p className="type-small text-muted-foreground">{help}</p>}
            <InputError message={error} />
        </div>
    );
}

function ServiceStrategy({
    project,
    service,
    canManage,
    allowProductionClone,
}: {
    project: string;
    service: App.Data.Previews.PreviewServiceData;
    canManage: boolean;
    allowProductionClone: boolean;
}) {
    const [value, setValue] = useState(service.strategy);
    const [source, setSource] = useState(service.cloneSourceId ?? '');
    const [sanitize, setSanitize] = useState(
        formatArgv(service.sanitizeCommand),
    );
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const selected = service.candidates.find(
        (candidate) => candidate.id === source,
    );
    const dirty =
        value !== service.strategy ||
        source !== (service.cloneSourceId ?? '') ||
        sanitize !== formatArgv(service.sanitizeCommand);

    const save = () => {
        const argv = sanitize.trim() === '' ? null : parseArgv(sanitize);

        if (argv === null && sanitize.trim() !== '') {
            setErrors({ sanitizeCommand: 'Close the open quote.' });

            return;
        }

        setProcessing(true);
        router.put(
            strategy([project, service.serviceId]).url,
            {
                strategy: value,
                cloneSourceId: value === 'clone' ? source || null : null,
                sanitizeCommand: value === 'clone' ? argv : null,
            },
            {
                preserveScroll: true,
                onError: (failed) => setErrors(failed),
                onSuccess: () => setErrors({}),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <SettingsCard
            title={service.name}
            description={
                service.engine !== service.name ? service.engine : undefined
            }
            footer={
                canManage ? (
                    <div className="flex items-center justify-between gap-3">
                        <span>Changes apply to new previews.</span>
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={!dirty || processing}
                            onClick={save}
                        >
                            Save
                        </Button>
                    </div>
                ) : undefined
            }
        >
            <SettingsBody className="flex flex-col gap-4">
                <OptionCards
                    label={`${service.name} in previews`}
                    value={value}
                    onChange={(next) => !canManage || setValue(next)}
                    className="sm:grid-cols-3"
                    options={[
                        {
                            value: 'new_empty',
                            title: 'New and empty',
                            description:
                                'New credentials. Migrations and seeds run against it.',
                        },
                        {
                            value: 'clone',
                            title: 'Copy of a database',
                            description: 'Restored from its latest backup.',
                        },
                        {
                            value: 'share',
                            title: 'Share the base',
                            description: 'Reads and writes the base database.',
                        },
                    ]}
                />
                <InputError message={errors.strategy} />

                {value === 'share' && (
                    <p className="flex items-start gap-2 type-small text-status-error">
                        <TriangleAlert
                            aria-hidden="true"
                            className="mt-0.5 size-3.5 shrink-0"
                        />
                        Previews read and write the base environment’s database.
                        Migrations in a pull request run against it. Previews of
                        pull requests from forks get a new empty database
                        instead. Sharing a production database needs “Allow
                        production data in previews”.
                    </p>
                )}

                {value === 'clone' && (
                    <>
                        <Field
                            id={`source-${service.serviceId}`}
                            label="Clone from"
                            help="Restored from the source’s latest backup, so the source takes no load."
                            error={errors.cloneSourceId}
                        >
                            <Select
                                value={source}
                                disabled={!canManage}
                                onValueChange={setSource}
                            >
                                <SelectTrigger
                                    id={`source-${service.serviceId}`}
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Pick a database" />
                                </SelectTrigger>
                                <SelectContent>
                                    {service.candidates.map((candidate) => (
                                        <SelectItem
                                            key={candidate.id}
                                            value={candidate.id}
                                            disabled={!candidate.allowed}
                                        >
                                            {candidate.environment} /{' '}
                                            {candidate.service} (
                                            {candidate.backedUpAt
                                                ? formatBytes(
                                                      candidate.sizeBytes,
                                                  )
                                                : 'no backup'}
                                            )
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <ul className="flex flex-col gap-1 type-small text-muted-foreground">
                            {service.candidates
                                .filter((candidate) => !candidate.allowed)
                                .map((candidate) => (
                                    <li key={candidate.id}>
                                        {candidate.environment} /{' '}
                                        {candidate.service}: {candidate.reason}
                                    </li>
                                ))}
                        </ul>
                        {(selected?.production ||
                            (allowProductionClone &&
                                service.candidates.some(
                                    (candidate) => candidate.production,
                                ))) && (
                            <p className="flex items-start gap-2 type-small text-status-progress">
                                <TriangleAlert
                                    aria-hidden="true"
                                    className="mt-0.5 size-3.5 shrink-0"
                                />
                                Production data may contain personal data. Add a
                                sanitize command, and keep previews protected.
                            </p>
                        )}
                        <Field
                            id={`sanitize-${service.serviceId}`}
                            label="Sanitize command (optional)"
                            help="Runs once in the database image after the restore, before the apps start."
                            error={errors.sanitizeCommand}
                        >
                            <Input
                                id={`sanitize-${service.serviceId}`}
                                className="font-mono"
                                value={sanitize}
                                disabled={!canManage}
                                placeholder="psql -c &quot;UPDATE users SET email = id || '@example.com'&quot;"
                                onChange={(event) =>
                                    setSanitize(event.target.value)
                                }
                            />
                        </Field>
                    </>
                )}
            </SettingsBody>
        </SettingsCard>
    );
}

function BypassSecret({
    project,
    settings: current,
}: {
    project: string;
    settings: Settings;
}) {
    const [secret, setSecret] = useState<string | null>(null);

    useEffect(
        () =>
            router.on('flash', (event) => {
                const value = (event as CustomEvent).detail?.flash
                    ?.previewBypassSecret;

                if (typeof value === 'string') {
                    setSecret(value);
                }
            }),
        [],
    );

    return (
        <SettingsCard title="Automated tests">
            <SettingsRow
                label="Bypass secret"
                description={
                    <>
                        CI skips the login with the{' '}
                        <code className="type-mono-small">
                            {current.bypassHeader}
                        </code>{' '}
                        header.
                    </>
                }
            >
                {current.canManage && (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            router.post(
                                bypass(project).url,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        {current.hasBypassSecret
                            ? 'Rotate secret'
                            : 'Generate secret'}
                    </Button>
                )}
            </SettingsRow>
            {secret && (
                <SettingsBody className="flex flex-col gap-1 py-4">
                    <CopyValue value={secret} label="bypass secret" />
                    <p className="type-small text-muted-foreground">
                        Shown once. Store it as a CI secret.
                    </p>
                </SettingsBody>
            )}
        </SettingsCard>
    );
}

/**
 * Project settings → Previews (docs/06 §5): settings v2 rows (on/off,
 * which pull requests, access) saved through the floating unsaved bar, fork
 * policy and per-database strategy as option cards.
 */
export function PreviewSettingsSection({
    project,
    settings: current,
}: {
    project: string;
    settings: Settings;
}) {
    const initial = {
        enabled: current.enabled,
        baseEnvironmentId: current.baseEnvironmentId,
        targetBranches: current.targetBranches ?? '',
        requiredLabel: current.requiredLabel ?? '',
        maxConcurrent: current.maxConcurrent,
        expiryDays: current.expiryDays,
        forkPolicy: current.forkPolicy,
        protection: current.protection,
        allowProductionClone: current.allowProductionClone,
    };
    const [data, setData] = useState(initial);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const dirty = JSON.stringify(data) !== JSON.stringify(initial);
    const set = <K extends keyof typeof data>(
        key: K,
        value: (typeof data)[K],
    ) => setData((previous) => ({ ...previous, [key]: value }));
    const disabled = !current.canManage;
    const effectiveBase = current.baseOptions.find(
        (option) => option.id === current.effectiveBaseId,
    );

    const save = () => {
        setProcessing(true);
        router.put(
            settings(project).url,
            {
                ...data,
                targetBranches: data.targetBranches || null,
                requiredLabel: data.requiredLabel || null,
            },
            {
                preserveScroll: true,
                onError: (failed) => setErrors(failed),
                onSuccess: () => setErrors({}),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <div className="flex flex-col gap-10">
            <form
                className="flex flex-col gap-10"
                onSubmit={(event) => {
                    event.preventDefault();
                    save();
                }}
            >
                <SettingsCard>
                    <SettingsRow
                        label="Preview environments"
                        description="Every pull request gets its own copy with its own URL, removed when it closes."
                        htmlFor="previews-enabled"
                        inline
                    >
                        <Switch
                            id="previews-enabled"
                            checked={data.enabled}
                            disabled={disabled}
                            onCheckedChange={(checked) =>
                                set('enabled', checked)
                            }
                        />
                    </SettingsRow>
                </SettingsCard>

                <SettingsCard
                    title="Which pull requests"
                    footer={
                        <>
                            Add{' '}
                            <code className="type-mono-small">
                                [skip preview]
                            </code>{' '}
                            to a title to opt one out.
                        </>
                    }
                >
                    <SettingsRow
                        label="Copied from"
                        description={
                            effectiveBase
                                ? `Currently ${effectiveBase.name}.`
                                : 'Deploy it first.'
                        }
                        htmlFor="previews-base"
                    >
                        <div className="flex w-full flex-col gap-1 sm:w-72">
                            <Select
                                value={data.baseEnvironmentId ?? AUTO}
                                disabled={disabled}
                                onValueChange={(value) =>
                                    set(
                                        'baseEnvironmentId',
                                        value === AUTO ? null : value,
                                    )
                                }
                            >
                                <SelectTrigger
                                    id="previews-base"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={AUTO}>
                                        Staging, else production
                                    </SelectItem>
                                    {current.baseOptions.map((option) => (
                                        <SelectItem
                                            key={option.id}
                                            value={option.id}
                                        >
                                            {option.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.baseEnvironmentId} />
                        </div>
                    </SettingsRow>
                    <SettingsRow
                        label="Target branches"
                        description="Comma-separated globs. Leave empty for every branch."
                        htmlFor="previews-branches"
                    >
                        <div className="flex w-full flex-col gap-1 sm:w-72">
                            <Input
                                id="previews-branches"
                                className="font-mono"
                                value={data.targetBranches}
                                placeholder="main, release/*"
                                disabled={disabled}
                                onChange={(event) =>
                                    set('targetBranches', event.target.value)
                                }
                            />
                            <InputError message={errors.targetBranches} />
                        </div>
                    </SettingsRow>
                    <SettingsRow
                        label="Required label"
                        description="Only pull requests with this label get a preview. Leave empty for all."
                        htmlFor="previews-label"
                    >
                        <div className="flex w-full flex-col gap-1 sm:w-72">
                            <Input
                                id="previews-label"
                                className="font-mono"
                                value={data.requiredLabel}
                                placeholder="preview"
                                disabled={disabled}
                                onChange={(event) =>
                                    set('requiredLabel', event.target.value)
                                }
                            />
                            <InputError message={errors.requiredLabel} />
                        </div>
                    </SettingsRow>
                    <SettingsRow
                        label="Running at once"
                        description="More pull requests wait in a queue."
                        htmlFor="previews-max"
                    >
                        <div className="flex flex-col gap-1">
                            <Input
                                id="previews-max"
                                type="number"
                                min={1}
                                max={50}
                                className="w-24 tabular-nums"
                                value={data.maxConcurrent}
                                disabled={disabled}
                                onChange={(event) =>
                                    set(
                                        'maxConcurrent',
                                        Number(event.target.value),
                                    )
                                }
                            />
                            <InputError message={errors.maxConcurrent} />
                        </div>
                    </SettingsRow>
                    <SettingsRow
                        label="Remove after inactivity"
                        description="Days without new commits. With 0, previews stay until the pull request closes."
                        htmlFor="previews-expiry"
                    >
                        <div className="flex flex-col gap-1">
                            <Input
                                id="previews-expiry"
                                type="number"
                                min={0}
                                max={90}
                                className="w-24 tabular-nums"
                                value={data.expiryDays}
                                disabled={disabled}
                                onChange={(event) =>
                                    set(
                                        'expiryDays',
                                        Number(event.target.value),
                                    )
                                }
                            />
                            <InputError message={errors.expiryDays} />
                        </div>
                    </SettingsRow>
                </SettingsCard>

                <Section
                    level={2}
                    title="Pull requests from forks"
                    description="Forks can run any code."
                >
                    <OptionCards
                        label="Pull requests from forks"
                        value={data.forkPolicy}
                        onChange={(value) =>
                            disabled || set('forkPolicy', value)
                        }
                        options={[
                            {
                                value: 'off',
                                title: 'No previews',
                                description:
                                    'Only pull requests from this repository get one.',
                            },
                            {
                                value: 'approval',
                                title: 'After your approval',
                                description:
                                    'A maintainer approves each commit. Secrets stay empty.',
                            },
                        ]}
                    />
                    <InputError message={errors.forkPolicy} />
                </Section>

                <SettingsCard title="Access">
                    <SettingsRow
                        label="Require a login"
                        description="Team members sign in. Anyone else needs a share link."
                        htmlFor="previews-protection"
                        inline
                    >
                        <Switch
                            id="previews-protection"
                            checked={data.protection}
                            disabled={disabled}
                            onCheckedChange={(checked) =>
                                set('protection', checked)
                            }
                        />
                    </SettingsRow>
                    <SettingsRow
                        label="Allow production data"
                        description={
                            current.canAllowProductionClone ? (
                                <>
                                    Previews may copy or share production
                                    databases. Pull requests from forks never
                                    get production data.
                                    <InputError
                                        message={errors.allowProductionClone}
                                    />
                                </>
                            ) : (
                                'Only owners and admins can change this.'
                            )
                        }
                        htmlFor="previews-production-clone"
                        inline
                    >
                        <Switch
                            id="previews-production-clone"
                            checked={data.allowProductionClone}
                            disabled={
                                disabled || !current.canAllowProductionClone
                            }
                            onCheckedChange={(checked) =>
                                set('allowProductionClone', checked)
                            }
                        />
                    </SettingsRow>
                </SettingsCard>

                <UnsavedBar
                    open={dirty && current.canManage}
                    onReset={() => {
                        setData(initial);
                        setErrors({});
                    }}
                    onSave={save}
                    saving={processing}
                />
            </form>

            <Section
                level={2}
                title="Databases in previews"
                description={`Copies need a backup under ${current.cloneLimitMb.toLocaleString()} MB.`}
            >
                {current.services.length === 0 ? (
                    <p className="type-body text-muted-foreground">
                        The base environment has no databases.
                    </p>
                ) : (
                    <div className="flex flex-col gap-6">
                        {current.services.map((service) => (
                            <ServiceStrategy
                                key={service.serviceId}
                                project={project}
                                service={service}
                                canManage={current.canManage}
                                allowProductionClone={
                                    current.allowProductionClone
                                }
                            />
                        ))}
                    </div>
                )}
            </Section>

            <BypassSecret project={project} settings={current} />
        </div>
    );
}
