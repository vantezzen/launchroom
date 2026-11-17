import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { TimeRangeSelect } from '@/components/charts/time-range-select';
import { Stepper } from '@/components/patterns/stepper';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { formatArgv, parseArgv } from '@/lib/argv';
import services from '@/routes/projects/services';
import { autoHealthTimeout } from './health-timeout';
import { OverrideRow } from './override-row';
import { SaveRow, SettingsPage } from './section';
import type { FieldLabels } from './section';
import type { SettingsContext } from './settings-context';
import {
    SettingsBody,
    SettingsCard,
    SettingsRow,
} from '@/components/settings/settings-card';

type DeployForm = {
    port: string | null;
    start: string | null;
    release: string;
    healthType: App.Enums.HealthcheckType;
    healthPath: string;
    expectStatus: string;
    startPeriod: number;
    interval: number;
    timeout: number;
    failures: number;
    drain: number;
    rolloutTimeout: number;
    /** null: derived from the health check (autoHealthTimeout). */
    healthTimeout: number | null;
    /** Restart a replica whose check keeps failing (off: never). */
    restart: boolean;
    restartMinutes: number;
    stopTimeout: number;
    overlapOnVolumes: boolean;
};

/** What the unsaved bar calls each field, and the row it lives in. */
const DEPLOY_FIELDS: FieldLabels = {
    start: ['Start command', 'start-command'],
    port: ['Port', 'port'],
    release: ['Release command', 'release-command'],
    healthType: ['Health check', 'health-check'],
    healthPath: ['Health check path', 'health-path'],
    expectStatus: ['Expected status', 'health-path'],
    startPeriod: ['Time to start', 'health-timing'],
    interval: ['Check interval', 'health-timing'],
    timeout: ['Answer within', 'health-timing'],
    failures: ['Unhealthy after', 'health-timing'],
    drain: ['Drain time', 'drain'],
    rolloutTimeout: ['Start timeout', 'rollout-timeout'],
    healthTimeout: ['Health check timeout', 'health-timeout'],
    restart: ['Restart when stuck', 'health-restart'],
    restartMinutes: ['Restart after', 'health-restart'],
    stopTimeout: ['Stop timeout', 'stop-timeout'],
    overlapOnVolumes: ['Two copies during a deploy', 'volume-overlap'],
};

/** The stop timeout without a setting: 10 s for web services, 30 s for workers and crons. */
function defaultStopTimeout(
    limits: App.Data.Services.DeployLimitsData,
    web: boolean,
): number {
    return web ? limits.stopTimeoutWeb : limits.stopTimeoutProcess;
}

function fromSettings(
    deploy: App.Data.Services.DeploySettings,
    limits: App.Data.Services.DeployLimitsData,
    web: boolean,
): DeployForm {
    const restartAfter =
        deploy.healthcheck.restartAfterSeconds ?? limits.restartAfterDefault;

    return {
        port: deploy.port === null ? null : String(deploy.port),
        start:
            deploy.startCommand === null
                ? null
                : formatArgv(deploy.startCommand),
        release: formatArgv(deploy.releaseCommand),
        healthType: deploy.healthcheck.type,
        healthPath: deploy.healthcheck.path,
        expectStatus: deploy.healthcheck.expectStatus,
        startPeriod: deploy.healthcheck.startPeriodSeconds,
        interval: deploy.healthcheck.intervalSeconds,
        timeout: deploy.healthcheck.timeoutSeconds,
        failures: deploy.healthcheck.failureThreshold,
        drain: deploy.drainSeconds,
        rolloutTimeout:
            deploy.rolloutTimeoutSeconds ?? limits.rolloutTimeoutDefault,
        healthTimeout: deploy.healthTimeoutSeconds,
        restart: restartAfter > 0,
        restartMinutes: Math.max(
            1,
            Math.round(
                (restartAfter > 0 ? restartAfter : limits.restartAfterDefault) /
                    60,
            ),
        ),
        stopTimeout:
            deploy.stopTimeoutSeconds ?? defaultStopTimeout(limits, web),
        overlapOnVolumes: deploy.overlapOnVolumes,
    };
}

/**
 * The health check over time: a grace period, then checks every interval;
 * the replica counts as unhealthy after N failed checks in a row.
 */
function HealthTimeline({ form }: { form: DeployForm }) {
    const checks = Math.min(8, Math.max(form.failures + 2, 4));
    const total = form.startPeriod + checks * Math.max(form.interval, 1);
    const grace = (form.startPeriod / total) * 100;

    return (
        <div className="flex flex-col gap-2" aria-hidden="true">
            <div className="relative flex h-8 items-center">
                <div
                    className="absolute inset-y-0.5 left-0 flex items-center overflow-hidden rounded-md bg-hover px-2 type-small whitespace-nowrap text-muted-foreground"
                    style={{ width: `${grace}%` }}
                >
                    {grace > 18 && `Starting (${form.startPeriod}s)`}
                </div>
                <div
                    className="absolute inset-x-0 top-1/2 h-px bg-border"
                    style={{ left: `${grace}%` }}
                />
                {Array.from({ length: checks }, (_, index) => {
                    const left = grace + ((index + 1) / checks) * (100 - grace);
                    const failed = index >= checks - form.failures;

                    return (
                        <span
                            key={index}
                            className={
                                failed
                                    ? 'absolute size-2.5 rounded-full border-2 border-status-progress bg-card'
                                    : 'absolute size-2.5 rounded-full bg-foreground/70'
                            }
                            style={{ left: `calc(${left}% - 6px)` }}
                        />
                    );
                })}
            </div>
            <div className="flex justify-between gap-4 type-small text-muted-foreground">
                <span>Time to start, then a check every {form.interval}s</span>
                <span className="text-status-progress">
                    Unhealthy after {form.failures} misses in a row
                </span>
            </div>
        </div>
    );
}

/**
 * Deploy: how launchroom starts the service (argv, never a shell), how it
 * decides a replica is ready, and how long old replicas drain. A restart,
 * no rebuild.
 */
export function DeploySection({ context }: { context: SettingsContext }) {
    const { settings, node, canUpdate } = context;
    const detected = settings.detected;
    const limits = settings.deployLimits;
    const built = node.capabilities.built;
    const web = node.capabilities.web;
    const form = useForm<DeployForm>(
        fromSettings(settings.deploy, limits, web),
    );
    const [commandError, setCommandError] = useState<string | null>(null);
    const stopDefault = defaultStopTimeout(limits, web);
    // Databases always stop first; other services only when they keep data in a volume.
    const keepsData = node.kind !== 'database' && settings.volumes.length > 0;
    const error = (key: string) =>
        (form.errors as Record<string, string | undefined>)[`deploy.${key}`];
    const data = form.data;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const start = data.start === null ? null : parseArgv(data.start);
        const release = parseArgv(data.release);

        if ((data.start !== null && start === null) || release === null) {
            setCommandError(
                'A quote is not closed. Commands run without a shell, so quotes only group words.',
            );

            return;
        }

        setCommandError(null);
        form.transform((values) => ({
            deploy: {
                // Settings of other pages (entrypoint, capabilities, schedule) stay.
                ...settings.deploy,
                port:
                    values.port === null || values.port === ''
                        ? null
                        : Number(values.port),
                startCommand: start && start.length > 0 ? start : null,
                releaseCommand: release.length > 0 ? release : null,
                seedCommand: settings.deploy.seedCommand,
                healthcheck: {
                    type: values.healthType,
                    path: values.healthPath,
                    expectStatus: values.expectStatus,
                    startPeriodSeconds: values.startPeriod,
                    intervalSeconds: values.interval,
                    timeoutSeconds: values.timeout,
                    failureThreshold: values.failures,
                    // null follows launchroom's default; 0 never restarts.
                    restartAfterSeconds: !values.restart
                        ? 0
                        : values.restartMinutes * 60 ===
                            limits.restartAfterDefault
                          ? null
                          : values.restartMinutes * 60,
                },
                drainSeconds: values.drain,
                rolloutTimeoutSeconds:
                    values.rolloutTimeout === limits.rolloutTimeoutDefault
                        ? null
                        : values.rolloutTimeout,
                healthTimeoutSeconds: values.healthTimeout,
                stopTimeoutSeconds:
                    values.stopTimeout === stopDefault &&
                    settings.deploy.stopTimeoutSeconds === null
                        ? null
                        : values.stopTimeout,
                overlapOnVolumes: values.overlapOnVolumes,
            },
        }));
        form.patch(services.settings(context.route).url, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => form.setDefaults(),
        });
    };

    const healthSentence =
        data.healthType === 'http'
            ? `Ready when GET ${data.healthPath || '/'} answers ${data.expectStatus || '2xx'} within ${data.timeout}s.`
            : data.healthType === 'tcp'
              ? `Ready when the port accepts a connection within ${data.timeout}s.`
              : 'Traffic switches to a new replica as soon as it is running.';

    return (
        <SettingsPage
            title="Deploy"
            description="How launchroom starts your service and checks that it is ready. Changes restart the service without a rebuild."
        >
            <form onSubmit={submit} className="flex flex-col gap-8">
                <SettingsCard title="Start">
                    <OverrideRow
                        setting="start-command"
                        label={built ? 'Start command' : 'Command'}
                        detected={
                            built ? (detected?.startCommand ?? null) : null
                        }
                        detectedFallback={
                            node.kind === 'app'
                                ? 'Detected on the next build'
                                : 'The image’s own command'
                        }
                        value={data.start}
                        disabled={!canUpdate}
                        onChange={(start) => form.setData('start', start)}
                        error={
                            error('startCommand') ?? commandError ?? undefined
                        }
                        helper="Runs without a shell: write sh -c '…' if you need one."
                    />
                    {web &&
                        (node.kind === 'app' ? (
                            <OverrideRow
                                setting="port"
                                label="Port"
                                type="number"
                                detected={
                                    detected?.port
                                        ? String(detected.port)
                                        : null
                                }
                                detectedFallback="Read from the PORT your app listens on"
                                value={data.port}
                                disabled={!canUpdate}
                                onChange={(port) => form.setData('port', port)}
                                error={error('port')}
                            />
                        ) : (
                            <SettingsRow
                                setting="port"
                                label="Port"
                                description="Where the image listens for requests."
                                htmlFor="deploy-port"
                            >
                                <div className="flex flex-col gap-1">
                                    <Input
                                        id="deploy-port"
                                        inputMode="numeric"
                                        value={data.port ?? ''}
                                        disabled={!canUpdate}
                                        placeholder="80"
                                        className="w-24 tabular-nums"
                                        onChange={(event) =>
                                            form.setData(
                                                'port',
                                                event.target.value === ''
                                                    ? null
                                                    : event.target.value,
                                            )
                                        }
                                    />
                                    <InputError message={error('port')} />
                                </div>
                            </SettingsRow>
                        ))}
                    {built && (
                        <SettingsRow
                            setting="release-command"
                            label="Release command"
                            description="Runs once per deploy before traffic switches, such as migrations."
                            htmlFor="deploy-release"
                            align="start"
                        >
                            <div className="flex w-full flex-col gap-1 sm:w-72">
                                <Input
                                    id="deploy-release"
                                    value={data.release}
                                    disabled={!canUpdate}
                                    placeholder="php artisan migrate --force"
                                    className="font-mono text-[13px]"
                                    onChange={(event) =>
                                        form.setData(
                                            'release',
                                            event.target.value,
                                        )
                                    }
                                />
                                <InputError message={error('releaseCommand')} />
                            </div>
                        </SettingsRow>
                    )}
                </SettingsCard>

                <SettingsCard
                    setting="health-check"
                    title="Health check"
                    description={healthSentence}
                >
                    <SettingsRow
                        label="Check"
                        description="How launchroom asks a new replica if it is ready."
                        inline
                    >
                        <TimeRangeSelect<App.Enums.HealthcheckType>
                            label="Health check type"
                            value={data.healthType}
                            onChange={(value) =>
                                canUpdate && form.setData('healthType', value)
                            }
                            options={[
                                { value: 'http', label: 'HTTP' },
                                { value: 'tcp', label: 'TCP' },
                                { value: 'none', label: 'None' },
                            ]}
                        />
                    </SettingsRow>
                    {data.healthType === 'http' && (
                        <SettingsRow
                            setting="health-path"
                            label="Path"
                            htmlFor="deploy-health-path"
                        >
                            <div className="flex flex-col gap-1">
                                <Input
                                    id="deploy-health-path"
                                    value={data.healthPath}
                                    disabled={!canUpdate}
                                    className="w-full font-mono text-[13px] sm:w-72"
                                    onChange={(event) =>
                                        form.setData(
                                            'healthPath',
                                            event.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={error('healthcheck.path')}
                                />
                            </div>
                        </SettingsRow>
                    )}
                    {data.healthType !== 'none' && (
                        <SettingsBody className="flex flex-col gap-6">
                            <HealthTimeline form={data} />
                            <div
                                data-setting="health-timing"
                                className="grid gap-x-6 gap-y-5 rounded-lg @xl/settings:grid-cols-2"
                            >
                                <Stepper
                                    label="Time to start"
                                    unit="s"
                                    min={limits.startPeriod.min}
                                    max={limits.startPeriod.max}
                                    step={5}
                                    value={data.startPeriod}
                                    disabled={!canUpdate}
                                    onChange={(value) =>
                                        form.setData('startPeriod', value)
                                    }
                                    helper={error(
                                        'healthcheck.startPeriodSeconds',
                                    )}
                                />
                                <Stepper
                                    label="Check every"
                                    unit="s"
                                    min={limits.interval.min}
                                    max={limits.interval.max}
                                    value={data.interval}
                                    disabled={!canUpdate}
                                    onChange={(value) =>
                                        form.setData('interval', value)
                                    }
                                    helper={error(
                                        'healthcheck.intervalSeconds',
                                    )}
                                />
                                <Stepper
                                    label="Answer within"
                                    unit="s"
                                    min={limits.timeout.min}
                                    max={limits.timeout.max}
                                    value={data.timeout}
                                    disabled={!canUpdate}
                                    onChange={(value) =>
                                        form.setData('timeout', value)
                                    }
                                    helper={error('healthcheck.timeoutSeconds')}
                                />
                                <Stepper
                                    label="Unhealthy after"
                                    unit="misses"
                                    min={limits.failures.min}
                                    max={limits.failures.max}
                                    value={data.failures}
                                    disabled={!canUpdate}
                                    onChange={(value) =>
                                        form.setData('failures', value)
                                    }
                                    helper={error(
                                        'healthcheck.failureThreshold',
                                    )}
                                />
                            </div>
                        </SettingsBody>
                    )}
                    {data.healthType !== 'none' && node.kind !== 'database' && (
                        <SettingsRow
                            setting="health-restart"
                            label="Restart when stuck"
                            description={
                                data.restart
                                    ? `A running replica whose check keeps failing for ${data.restartMinutes} ${data.restartMinutes === 1 ? 'minute' : 'minutes'} is restarted, at most 3 times an hour.`
                                    : 'A running replica whose check keeps failing only stops getting traffic.'
                            }
                            htmlFor="deploy-health-restart"
                            align="start"
                        >
                            <div className="flex items-center gap-4">
                                {data.restart && (
                                    <Stepper
                                        label="Restart after"
                                        className="[&>label]:sr-only"
                                        unit="min"
                                        min={1}
                                        max={60}
                                        value={data.restartMinutes}
                                        disabled={!canUpdate}
                                        onChange={(value) =>
                                            form.setData(
                                                'restartMinutes',
                                                value,
                                            )
                                        }
                                        helper={error(
                                            'healthcheck.restartAfterSeconds',
                                        )}
                                    />
                                )}
                                <Switch
                                    id="deploy-health-restart"
                                    checked={data.restart}
                                    disabled={!canUpdate}
                                    onCheckedChange={(on) =>
                                        form.setData('restart', on)
                                    }
                                />
                            </div>
                        </SettingsRow>
                    )}
                </SettingsCard>

                <SettingsCard title="Rollout">
                    {keepsData && (
                        <SettingsRow
                            setting="volume-overlap"
                            label="Two copies during a deploy"
                            description={
                                data.overlapOnVolumes
                                    ? 'The new version starts next to the old one on the same volume. Only for apps that handle two copies on their data.'
                                    : 'This service keeps data in a volume, so the old version stops before the new one starts. Deploys have a short downtime.'
                            }
                            htmlFor="deploy-volume-overlap"
                            align="start"
                        >
                            <Switch
                                id="deploy-volume-overlap"
                                checked={data.overlapOnVolumes}
                                disabled={!canUpdate}
                                onCheckedChange={(on) =>
                                    form.setData('overlapOnVolumes', on)
                                }
                            />
                        </SettingsRow>
                    )}
                    <SettingsRow
                        setting="drain"
                        label="Drain time"
                        description="How long old replicas keep answering after the new ones are ready."
                    >
                        <Stepper
                            label="Drain time"
                            className="[&>label]:sr-only"
                            unit="s"
                            min={limits.drain.min}
                            max={limits.drain.max}
                            step={5}
                            value={data.drain}
                            disabled={!canUpdate}
                            onChange={(value) => form.setData('drain', value)}
                            helper={error('drainSeconds')}
                        />
                    </SettingsRow>
                    {node.kind !== 'database' && (
                        <SettingsRow
                            setting="stop-timeout"
                            label="Stop timeout"
                            description={
                                settings.deploy.stopTimeoutSeconds === null &&
                                data.stopTimeout === stopDefault
                                    ? 'How long a stopping replica may take to finish its work before it is killed. Follows launchroom’s default until you change it.'
                                    : 'How long a stopping replica may take to finish its work before it is killed.'
                            }
                        >
                            <Stepper
                                label="Stop timeout"
                                className="[&>label]:sr-only"
                                unit="s"
                                min={limits.stopTimeout.min}
                                max={limits.stopTimeout.max}
                                step={5}
                                value={data.stopTimeout}
                                disabled={!canUpdate}
                                onChange={(value) =>
                                    form.setData('stopTimeout', value)
                                }
                                helper={error('stopTimeoutSeconds')}
                            />
                        </SettingsRow>
                    )}
                    <SettingsRow
                        setting="rollout-timeout"
                        label="Start timeout"
                        description="How long the new version may take to download and start before the deploy fails."
                    >
                        <Stepper
                            label="Start timeout"
                            className="[&>label]:sr-only"
                            unit="s"
                            min={limits.rolloutTimeout.min}
                            max={limits.rolloutTimeout.max}
                            step={60}
                            value={data.rolloutTimeout}
                            disabled={!canUpdate}
                            onChange={(value) =>
                                form.setData('rolloutTimeout', value)
                            }
                            helper={error('rolloutTimeoutSeconds')}
                        />
                    </SettingsRow>
                    <SettingsRow
                        setting="health-timeout"
                        label="Health check timeout"
                        description={
                            data.healthTimeout === null
                                ? 'How long a new container may take to become healthy. Follows the health check settings until you change it.'
                                : 'How long a new container may take to become healthy before the deploy fails.'
                        }
                    >
                        <Stepper
                            label="Health check timeout"
                            className="[&>label]:sr-only"
                            unit="s"
                            min={limits.healthTimeout.min}
                            max={limits.healthTimeout.max}
                            step={30}
                            value={
                                data.healthTimeout ?? autoHealthTimeout(data)
                            }
                            disabled={!canUpdate}
                            onChange={(value) =>
                                form.setData('healthTimeout', value)
                            }
                            helper={error('healthTimeoutSeconds')}
                        />
                    </SettingsRow>
                </SettingsCard>

                {canUpdate && (
                    <SaveRow
                        dirty={form.isDirty}
                        processing={form.processing}
                        onReset={() => {
                            form.reset();
                            setCommandError(null);
                        }}
                        stages="restart"
                        fields={form.data}
                        labels={DEPLOY_FIELDS}
                    />
                )}
            </form>
        </SettingsPage>
    );
}
