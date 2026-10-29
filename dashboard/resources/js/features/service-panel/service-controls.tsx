import { router } from '@inertiajs/react';
import { Play, RotateCw, Rocket, Square } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { ConfirmPopover } from '@/components/patterns/confirm-popover';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { deployStaged } from '@/features/staged-changes/staged-changes-sheet';
import lifecycle from '@/routes/services/lifecycle';

type Controls = App.Data.Canvas.ServiceControlsData;

/**
 * The service panel header's actions (D68, docs/08 §13): Deploy (or Deploy
 * changes when the service has staged changes, the environment's existing
 * flow), Restart and Stop while it runs, Start while it is stopped. Each
 * changes the service in this environment only. Before anything of it
 * runs (a first deploy in progress or failed), Deploy tries again and
 * Restart and Stop stay in place, disabled, saying why.
 */
export function ServiceControls({
    project,
    environment,
    service,
    name,
    controls,
}: {
    project: string;
    environment: string;
    service: string;
    name: string;
    controls: Controls;
}) {
    const [busy, setBusy] = useState<string | null>(null);
    const [processes, setProcesses] = useState(true);

    if (!controls.canControl) {
        return null;
    }

    const args = { project, environment, service };
    const post = (kind: 'restart' | 'stop', done: string) =>
        new Promise<void>((resolve) => {
            setBusy(kind);
            router.post(
                lifecycle[kind](args).url,
                { processes },
                {
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: () => toast.success(done),
                    onError: (errors) =>
                        toast.error(
                            Object.values(errors)[0] ??
                                `Couldn’t ${kind} ${name}.`,
                        ),
                    onFinish: () => {
                        setBusy(null);
                        resolve();
                    },
                },
            );
        });

    const processChoice =
        controls.processes > 0 ? (
            <ProcessChoice
                count={controls.processes}
                checked={processes}
                onChange={setProcesses}
            />
        ) : null;

    if (controls.stopped) {
        return (
            <div className="flex flex-wrap items-center gap-2">
                <StartButton
                    project={project}
                    environment={environment}
                    service={service}
                    name={name}
                    processes={controls.processes}
                />
            </div>
        );
    }

    if (!controls.running) {
        return (
            <div className="flex flex-wrap items-center gap-2">
                <DeployButton
                    project={project}
                    environment={environment}
                    service={service}
                    name={name}
                    staged={controls.staged}
                    versioned={controls.versioned}
                />
                <NotRunning icon={RotateCw} label="Restart" />
                <NotRunning icon={Square} label="Stop" />
            </div>
        );
    }

    return (
        <div className="flex flex-wrap items-center gap-2">
            {controls.versioned && (
                <DeployButton
                    project={project}
                    environment={environment}
                    service={service}
                    name={name}
                    staged={controls.staged}
                    disabled={busy !== null}
                />
            )}
            <ConfirmPopover
                align="start"
                title={`Restart ${name}?`}
                consequence={
                    controls.deploying ? (
                        'A deployment of it is in progress. Restart once it has finished.'
                    ) : (
                        <>
                            New containers of the running version replace the
                            current ones, one replica at a time when there are
                            several.
                            {processChoice}
                        </>
                    )
                }
                confirmLabel="Restart"
                onConfirm={() => post('restart', `Restarting ${name}`)}
            >
                <Button
                    size="sm"
                    variant="outline"
                    disabled={busy !== null || controls.deploying}
                >
                    <RotateCw aria-hidden="true" />
                    Restart
                </Button>
            </ConfirmPopover>
            <ConfirmPopover
                align="start"
                tone="destructive"
                title={`Stop ${name}?`}
                consequence={
                    <>
                        Its containers stop and its domains show a stopped page.
                        Settings, domains, volumes and deployments stay.
                        {controls.dependents > 0 &&
                            ` ${controls.dependents === 1 ? 'One service uses' : `${controls.dependents} services use`} it and lose the connection until it starts again.`}
                        {processChoice}
                    </>
                }
                confirmLabel="Stop"
                onConfirm={() => post('stop', `${name} is stopped`)}
            >
                <Button size="sm" variant="outline" disabled={busy !== null}>
                    <Square aria-hidden="true" />
                    Stop
                </Button>
            </ConfirmPopover>
        </div>
    );
}

/**
 * Start of a stopped service: a popover that says what runs and, for an
 * app with workers or crons, whether they start too. In the panel header
 * and on the stopped row of the Deployments tab.
 */
export function StartButton({
    project,
    environment,
    service,
    name,
    processes: processCount,
    size = 'sm',
    onStarted,
}: {
    project: string;
    environment: string;
    service: string;
    name: string;
    processes: number;
    size?: 'sm' | 'default';
    onStarted?: () => void;
}) {
    const [busy, setBusy] = useState(false);
    const [processes, setProcesses] = useState(true);

    const start = () =>
        new Promise<void>((resolve) => {
            setBusy(true);
            router.post(
                lifecycle.start({ project, environment, service }).url,
                { processes },
                {
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: () => {
                        toast.success(`Starting ${name}`);
                        onStarted?.();
                    },
                    onError: (errors) =>
                        toast.error(
                            Object.values(errors)[0] ??
                                `Couldn’t start ${name}.`,
                        ),
                    onFinish: () => {
                        setBusy(false);
                        resolve();
                    },
                },
            );
        });

    return (
        <ConfirmPopover
            align="start"
            title={`Start ${name}?`}
            consequence={
                <>
                    It runs the version that was live when it stopped, with the
                    configuration deployed last.
                    {processCount > 0 && (
                        <ProcessChoice
                            count={processCount}
                            checked={processes}
                            onChange={setProcesses}
                        />
                    )}
                </>
            }
            confirmLabel="Start"
            onConfirm={start}
        >
            <Button size={size} disabled={busy}>
                <Play aria-hidden="true" />
                Start
            </Button>
        </ConfirmPopover>
    );
}

/**
 * Restart or Stop before anything runs: in place so the header doesn't
 * change shape once the first deploy succeeds, disabled with the reason.
 */
function NotRunning({
    icon: Icon,
    label,
}: {
    icon: typeof RotateCw;
    label: string;
}) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span tabIndex={0} className="rounded-md">
                    <Button size="sm" variant="outline" disabled>
                        <Icon aria-hidden="true" />
                        {label}
                    </Button>
                </span>
            </TooltipTrigger>
            <TooltipContent>Nothing is running yet</TooltipContent>
        </Tooltip>
    );
}

function ProcessChoice({
    count,
    checked,
    onChange,
}: {
    count: number;
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    return (
        <label className="mt-1 flex items-center gap-2 type-small text-foreground">
            <Checkbox
                checked={checked}
                onCheckedChange={(value) => onChange(value === true)}
            />
            {count === 1
                ? 'Include its worker or cron'
                : `Include its ${count} workers and crons`}
        </label>
    );
}

/**
 * Deploy: the latest commit of the tracked branch (or the image's tag
 * again; a database, worker or cron that never ran: its last deployed
 * configuration); with staged changes, the environment's Deploy changes.
 * Shown in the panel header and as "Try again" on a failed first deploy
 * in the Deployments tab.
 */
export function DeployButton({
    project,
    environment,
    service,
    name,
    staged,
    versioned = true,
    label,
    disabled = false,
    variant = 'default',
}: {
    project: string;
    environment: string;
    service: string;
    name: string;
    staged: boolean;
    versioned?: boolean;
    /** In place of "Deploy" (not with staged changes): "Try again". */
    label?: string;
    disabled?: boolean;
    variant?: 'default' | 'outline';
}) {
    const [busy, setBusy] = useState(false);

    const deploy = () => {
        setBusy(true);

        if (staged) {
            deployStaged(project, environment, {
                onFinish: () => setBusy(false),
                onError: (message) => toast.error(message),
            });

            return;
        }

        router.post(
            lifecycle.deploy({ project, environment, service }).url,
            {},
            {
                onError: (errors) =>
                    toast.error(
                        Object.values(errors)[0] ?? `Couldn’t deploy ${name}.`,
                    ),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <Button
            size="sm"
            variant={variant}
            onClick={deploy}
            disabled={disabled || busy}
            title={
                staged
                    ? 'Deploys the staged changes of this environment'
                    : versioned
                      ? 'Builds and deploys the latest version'
                      : 'Deploys the last deployed configuration again'
            }
        >
            <Rocket aria-hidden="true" />
            {staged ? 'Deploy changes' : (label ?? 'Deploy')}
        </Button>
    );
}
