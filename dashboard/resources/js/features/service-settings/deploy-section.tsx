import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { TimeRangeSelect } from '@/components/charts/time-range-select';
import { Stepper } from '@/components/patterns/stepper';
import { Input } from '@/components/ui/input';
import { formatArgv, parseArgv } from '@/features/projects/argv';
import {
    SettingsBody,
    SettingsCard,
    SettingsRow,
} from '@/features/settings/settings-card';
import services from '@/routes/projects/services';
import { OverrideRow } from './override-row';
import { SaveRow, SettingsPage } from './section';
import type { SettingsContext } from './settings-context';

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
};

function fromSettings(deploy: App.Data.Services.DeploySettings): DeployForm {
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
    };
}

/**
 * The health check over time: a grace period, then checks every interval;
 * the replica counts as unhealthy after N failed checks in a row.
 */
function HealthTimeline({ form }: { form: DeployForm }) {
    const checks = Math.min(8, Math.max(form.failures + 2, 4));
    const total = form.startPeriod + checks * Math.max(form.interval, 1);
    const grace = total === 0 ? 0 : (form.startPeriod / total) * 100;

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
                <span>
                    {form.startPeriod > 0
                        ? 'Time to start, then a check'
                        : 'Checks start at once'}{' '}
                    every {form.interval}s
                </span>
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
    const form = useForm<DeployForm>(fromSettings(settings.deploy));
    const [commandError, setCommandError] = useState<string | null>(null);
    const built =
        node.kind === 'app' || node.kind === 'worker' || node.kind === 'cron';
    const web = node.kind === 'app' || node.kind === 'image';
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
                },
                drainSeconds: values.drain,
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
                                    min={0}
                                    max={3600}
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
                                    min={1}
                                    max={300}
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
                                    min={1}
                                    max={300}
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
                                    min={1}
                                    max={20}
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
                </SettingsCard>

                <SettingsCard title="Rollout">
                    <SettingsRow
                        setting="drain"
                        label="Drain time"
                        description="How long old replicas keep answering after the new ones are ready."
                    >
                        <Stepper
                            label="Drain time"
                            className="[&>label]:sr-only"
                            unit="s"
                            min={0}
                            max={3600}
                            step={5}
                            value={data.drain}
                            disabled={!canUpdate}
                            onChange={(value) => form.setData('drain', value)}
                            helper={error('drainSeconds')}
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
                        label="Save for next deploy"
                    />
                )}
            </form>
        </SettingsPage>
    );
}
