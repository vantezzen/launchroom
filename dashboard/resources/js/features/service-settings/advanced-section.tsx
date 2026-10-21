import { useForm } from '@inertiajs/react';
import { Box, Plus, X } from 'lucide-react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Status } from '@/components/status';
import type { StatusState } from '@/components/status';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import {
    SettingsBody,
    SettingsCard,
    SettingsRow,
} from '@/features/settings/settings-card';
import services from '@/routes/projects/services';
import { CopyButton } from './general-section';
import { IconTile, SaveRow, SettingsPage } from './section';
import type { SettingsContext } from './settings-context';

function Value({
    value,
    copy = true,
}: {
    value: string | null;
    copy?: boolean;
}) {
    if (value === null) {
        return (
            <span className="type-small text-muted-foreground">None yet</span>
        );
    }

    return (
        <span className="flex max-w-[min(24rem,60vw)] min-w-0 items-center">
            <code
                className="min-w-0 truncate type-mono-small text-muted-foreground"
                title={value}
            >
                {value}
            </code>
            {copy && <CopyButton value={value} label={value} />}
        </span>
    );
}

const phaseState: Record<string, StatusState> = {
    running: 'active',
    serving: 'active',
    starting: 'progress',
    pending: 'progress',
    draining: 'idle',
    stopped: 'idle',
    failed: 'failed',
};

type ContainerForm = {
    /** One argument per row; [] = the image's own entrypoint. */
    entrypoint: string[];
    capabilities: string[];
};

/**
 * The entrypoint as an argv list: one argument per row, so nothing is
 * ever split or quoted by a shell (docs/09 §1).
 */
function ArgvList({
    value,
    disabled,
    onChange,
}: {
    value: string[];
    disabled: boolean;
    onChange: (value: string[]) => void;
}) {
    return (
        <div className="flex flex-col gap-2">
            {value.length === 0 ? (
                <p className="type-small text-muted-foreground">
                    The image’s own entrypoint runs. Add arguments to replace
                    it; the start command then follows as its arguments.
                </p>
            ) : (
                <ol className="flex flex-col gap-2">
                    {value.map((argument, index) => (
                        <li key={index} className="flex items-center gap-2">
                            <span className="w-5 shrink-0 text-right type-mono-small text-muted-foreground tabular-nums">
                                {index}
                            </span>
                            <Input
                                value={argument}
                                disabled={disabled}
                                aria-label={`Argument ${index + 1}`}
                                spellCheck={false}
                                autoComplete="off"
                                placeholder={
                                    index === 0
                                        ? '/usr/local/bin/entrypoint'
                                        : 'argument'
                                }
                                className="font-mono text-[13px]"
                                onChange={(event) =>
                                    onChange(
                                        value.map((item, i) =>
                                            i === index
                                                ? event.target.value
                                                : item,
                                        ),
                                    )
                                }
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                disabled={disabled}
                                aria-label={`Remove argument ${index + 1}`}
                                onClick={() =>
                                    onChange(
                                        value.filter((_, i) => i !== index),
                                    )
                                }
                            >
                                <X />
                            </Button>
                        </li>
                    ))}
                </ol>
            )}
            <div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={disabled || value.length >= 256}
                    onClick={() => onChange([...value, ''])}
                >
                    <Plus /> Add argument
                </Button>
            </div>
        </div>
    );
}

/**
 * Entrypoint and capabilities: how the container itself starts. Set by
 * Compose imports (docs/17) and editable here; capabilities only from
 * launchroomd's allow-list (docs/03 §8). A restart, no rebuild.
 */
function ContainerSettings({ context }: { context: SettingsContext }) {
    const { settings, canUpdate } = context;
    const options = settings.advanced.capabilityOptions;
    const form = useForm<ContainerForm>({
        entrypoint: settings.deploy.entrypoint ?? [],
        capabilities: settings.deploy.capabilities,
    });
    const data = form.data;
    const errors = form.errors as Record<string, string | undefined>;
    const entrypointError = Object.entries(errors).find(([key]) =>
        key.startsWith('deploy.entrypoint'),
    )?.[1];
    const capabilitiesError = Object.entries(errors).find(([key]) =>
        key.startsWith('deploy.capabilities'),
    )?.[1];

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((values) => {
            const entrypoint = values.entrypoint.filter(
                (argument) => argument !== '',
            );

            return {
                deploy: {
                    ...settings.deploy,
                    entrypoint: entrypoint.length > 0 ? entrypoint : null,
                    capabilities: options
                        .map((option) => option.name)
                        .filter((name) => values.capabilities.includes(name)),
                },
            };
        });
        form.patch(services.settings(context.route).url, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => form.setDefaults(),
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-8">
            <SettingsCard
                setting="entrypoint"
                title="Entrypoint"
                description="Replaces the image’s ENTRYPOINT, one argument per row. Runs without a shell."
            >
                <SettingsBody>
                    <ArgvList
                        value={data.entrypoint}
                        disabled={!canUpdate}
                        onChange={(entrypoint) =>
                            form.setData('entrypoint', entrypoint)
                        }
                    />
                    <InputError className="mt-2" message={entrypointError} />
                </SettingsBody>
            </SettingsCard>

            <SettingsCard
                setting="capabilities"
                title="Capabilities"
                description="Containers start with no Linux capabilities. Grant one only when the image needs it to start."
                footer={
                    capabilitiesError ? (
                        <InputError message={capabilitiesError} />
                    ) : undefined
                }
            >
                {options.map((option) => {
                    const id = `capability-${option.name}`;
                    const checked = data.capabilities.includes(option.name);

                    return (
                        <SettingsRow
                            key={option.name}
                            label={
                                <span className="flex flex-wrap items-baseline gap-x-2">
                                    {option.label}
                                    <code className="type-mono-small font-normal text-muted-foreground">
                                        {option.name}
                                    </code>
                                </span>
                            }
                            description={option.description}
                            htmlFor={id}
                            inline
                        >
                            <Switch
                                id={id}
                                checked={checked}
                                disabled={!canUpdate}
                                onCheckedChange={(on) =>
                                    form.setData(
                                        'capabilities',
                                        on
                                            ? [
                                                  ...data.capabilities,
                                                  option.name,
                                              ]
                                            : data.capabilities.filter(
                                                  (name) =>
                                                      name !== option.name,
                                              ),
                                    )
                                }
                            />
                        </SettingsRow>
                    );
                })}
            </SettingsCard>

            {canUpdate && (
                <SaveRow
                    dirty={form.isDirty}
                    processing={form.processing}
                    onReset={() => form.reset()}
                    label="Save for next deploy"
                />
            )}
        </form>
    );
}

/**
 * Advanced (docs/08 §3.7): how the container starts (entrypoint,
 * capabilities), then the raw view for debugging. Docker vocabulary appears
 * only here; ids and containers are read-only and copyable.
 */
export function AdvancedSection({ context }: { context: SettingsContext }) {
    const { advanced, volumes } = context.settings;
    const container = context.node.kind !== 'database';

    return (
        <SettingsPage
            title="Advanced"
            description={
                container
                    ? 'How the container starts, and the ids and containers behind this service for debugging and support.'
                    : 'The ids and containers behind this service, for debugging and support. Read-only.'
            }
        >
            {container && <ContainerSettings context={context} />}

            <SettingsCard setting="identifiers" title="Identifiers">
                <SettingsRow label="Service">
                    <Value value={advanced.serviceId} />
                </SettingsRow>
                <SettingsRow
                    label="Instance"
                    description="This service in this environment."
                >
                    <Value value={advanced.instanceId} />
                </SettingsRow>
                <SettingsRow label="Environment">
                    <Value value={advanced.environmentId} />
                </SettingsRow>
                <SettingsRow label="Active deployment">
                    <Value value={advanced.activeDeploymentId} />
                </SettingsRow>
            </SettingsCard>

            <SettingsCard setting="runtime" title="Runtime">
                <SettingsRow label="Docker network">
                    <Value value={advanced.networkName} />
                </SettingsRow>
                {advanced.imageRepository !== null && (
                    <SettingsRow label="Image repository">
                        <Value value={advanced.imageRepository} />
                    </SettingsRow>
                )}
                <SettingsRow label="Running image">
                    <Value value={advanced.image} />
                </SettingsRow>
                {volumes.map((volume) => (
                    <SettingsRow
                        key={volume.id}
                        label="Docker volume"
                        description={volume.mountPath}
                    >
                        <Value value={volume.dockerName} />
                    </SettingsRow>
                ))}
            </SettingsCard>

            <SettingsCard
                setting="containers"
                title="Containers"
                description={
                    advanced.workloads.length === 0
                        ? 'None running in this environment.'
                        : `${advanced.workloads.length} for the active deployment.`
                }
            >
                {advanced.workloads.length === 0 ? (
                    <SettingsRow
                        label="Nothing running"
                        description="Containers appear here after a deploy."
                    />
                ) : (
                    advanced.workloads.map((workload) => (
                        <SettingsRow
                            key={workload.id}
                            media={
                                <IconTile>
                                    <Box />
                                </IconTile>
                            }
                            label={
                                <code className="type-mono-small">
                                    {workload.id}
                                </code>
                            }
                            description={`${workload.server} · container ${workload.containerId?.slice(0, 12) ?? 'not created'} · ${workload.restartCount} ${workload.restartCount === 1 ? 'restart' : 'restarts'}`}
                            inline
                        >
                            <Status
                                state={phaseState[workload.phase] ?? 'idle'}
                                label={workload.phase}
                            />
                        </SettingsRow>
                    ))
                )}
            </SettingsCard>
        </SettingsPage>
    );
}
