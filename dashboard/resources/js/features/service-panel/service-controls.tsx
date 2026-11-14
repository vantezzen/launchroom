import { router } from '@inertiajs/react';
import { MoreHorizontal } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { toast } from 'sonner';
import { ConfirmPopover } from '@/components/patterns/confirm-popover';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    optimisticQueued,
    optimisticService,
    rollbackToast,
} from '@/features/deployments/optimistic';
import {
    LIFECYCLE_CONSEQUENCE,
    LIFECYCLE_ICON,
} from '@/features/service-panel/lifecycle';
import { openStagedChanges } from '@/features/staged-changes/staged-changes-sheet';
import { cn } from '@/lib/utils';
import { redeploy as redeployService } from '@/routes/deployments/service';
import lifecycle from '@/routes/services/lifecycle';

type Controls = App.Data.Canvas.ServiceControlsData;

const RestartIcon = LIFECYCLE_ICON.restart;
const StopIcon = LIFECYCLE_ICON.stop;
const StartIcon = LIFECYCLE_ICON.start;
const DeployIcon = LIFECYCLE_ICON.deploy;
const RedeployIcon = LIFECYCLE_ICON.redeploy;

type MenuAction = 'redeploy' | 'restart' | 'stop';

/**
 * The service panel header's actions (D68, docs/08 §13, design-system
 * §13.4): the one primary action and a `⋯` menu, always the same shape for
 * a service so nothing moves when its state changes.
 *
 * - Primary: Deploy (apps and images), Review & apply while the service
 *   has staged changes, Start while it is stopped. Databases, workers and
 *   crons have nothing new to deploy, so theirs is an outline Redeploy once
 *   they run.
 * - `⋯`: Redeploy… (apps and images), Restart… and Stop…. They never
 *   disappear; one that can't run now stays in the menu, disabled, with the
 *   reason on its second line.
 *
 * Everything that changes the service asks first in a confirm popover
 * anchored to the cluster; Stop in the destructive tone. Every action is
 * optimistic (docs/10 §3).
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
    const [busy, setBusy] = useState(false);
    const [processes, setProcesses] = useState(true);
    const [confirming, setConfirming] = useState<MenuAction | null>(null);
    // Set on select, read when the menu closes (before the next render).
    const opening = useRef(false);

    if (!controls.canControl) {
        return null;
    }

    const args = { project, environment, service };
    // Lands on the new deployment's page; the node says "Queued" at once.
    const redeploy = () => {
        setBusy(true);
        router.post(
            redeployService(args).url,
            {},
            {
                optimistic: optimisticQueued(service),
                ...rollbackToast(`Couldn’t redeploy ${name}.`),
                onFinish: () => setBusy(false),
            },
        );
    };
    const post = (kind: 'restart' | 'stop', done: string) =>
        router.post(
            lifecycle[kind](args).url,
            { processes },
            {
                preserveScroll: true,
                preserveState: true,
                optimistic:
                    kind === 'stop'
                        ? optimisticService(
                              service,
                              { state: 'idle', label: 'Stopped' },
                              {
                                  stopped: true,
                                  stoppedAt: new Date().toISOString(),
                              },
                          )
                        : optimisticService(
                              service,
                              { state: 'progress', label: 'Restarting' },
                              { deploying: true },
                          ),
                onSuccess: () => toast.success(done),
                ...rollbackToast(`Couldn’t ${kind} ${name}.`),
            },
        );

    const processChoice =
        controls.processes > 0 ? (
            <ProcessChoice
                count={controls.processes}
                checked={processes}
                onChange={setProcesses}
            />
        ) : null;

    const restartBlocked = !controls.running
        ? 'Nothing is running yet'
        : controls.stopped
          ? 'Start it first'
          : controls.deploying
            ? 'Possible once the deployment has finished'
            : null;
    const stopBlocked = !controls.running
        ? 'Nothing is running yet'
        : controls.stopped
          ? 'It is already stopped'
          : null;
    const redeployBlocked = restartBlocked;
    // Databases, workers and crons: Redeploy is their primary once they run.
    const redeployPrimary =
        !controls.versioned &&
        controls.running &&
        !controls.staged &&
        !controls.stopped;

    const ask = (action: MenuAction) => {
        opening.current = true;
        setConfirming(action);
    };

    const primary = controls.stopped ? (
        <StartButton
            project={project}
            environment={environment}
            service={service}
            name={name}
            processes={controls.processes}
            align="end"
        />
    ) : redeployPrimary ? (
        redeployBlocked !== null ? (
            <BlockedButton label="Redeploy" reason={redeployBlocked}>
                <RedeployIcon aria-hidden="true" />
                Redeploy
            </BlockedButton>
        ) : (
            <Button
                size="sm"
                variant="outline"
                disabled={busy}
                onClick={() => setConfirming('redeploy')}
            >
                <RedeployIcon aria-hidden="true" />
                Redeploy
            </Button>
        )
    ) : (
        <DeployButton
            project={project}
            environment={environment}
            service={service}
            name={name}
            staged={controls.staged}
            versioned={controls.versioned}
            disabled={busy}
        />
    );

    const confirm: Record<
        MenuAction,
        {
            title: string;
            consequence: ReactNode;
            label: string;
            run: () => void;
        }
    > = {
        redeploy: {
            title: `Redeploy ${name}?`,
            consequence: LIFECYCLE_CONSEQUENCE.redeploy,
            label: 'Redeploy',
            run: redeploy,
        },
        restart: {
            title: `Restart ${name}?`,
            consequence: (
                <>
                    {LIFECYCLE_CONSEQUENCE.restart}
                    {processChoice}
                </>
            ),
            label: 'Restart',
            run: () => post('restart', `Restarting ${name}`),
        },
        stop: {
            title: `Stop ${name}?`,
            consequence: (
                <>
                    {LIFECYCLE_CONSEQUENCE.stop}
                    {controls.dependents > 0 &&
                        ` ${controls.dependents === 1 ? 'One service uses' : `${controls.dependents} services use`} it and loses the connection until it starts again.`}
                    {processChoice}
                </>
            ),
            label: 'Stop',
            run: () => post('stop', `${name} is stopped`),
        },
    };
    const current = confirm[confirming ?? 'restart'];

    return (
        <ConfirmPopover
            open={confirming !== null}
            onOpenChange={(open) => !open && setConfirming(null)}
            tone={confirming === 'stop' ? 'destructive' : 'default'}
            title={current.title}
            consequence={current.consequence}
            confirmLabel={current.label}
            onConfirm={current.run}
        >
            <div
                role="group"
                aria-label={`${name} actions`}
                className="flex shrink-0 items-center gap-2"
            >
                {primary}
                <DropdownMenu>
                    <Tooltip>
                        <DropdownMenuTrigger asChild>
                            <TooltipTrigger asChild>
                                <Button
                                    size="icon-sm"
                                    variant="outline"
                                    aria-label="More actions"
                                    className="size-8"
                                >
                                    <MoreHorizontal aria-hidden="true" />
                                </Button>
                            </TooltipTrigger>
                        </DropdownMenuTrigger>
                        <TooltipContent>More actions</TooltipContent>
                    </Tooltip>
                    <DropdownMenuContent
                        align="end"
                        className="w-64"
                        // The confirm popover takes focus, not the trigger.
                        onCloseAutoFocus={(event) => {
                            if (opening.current) {
                                opening.current = false;
                                event.preventDefault();
                            }
                        }}
                    >
                        {!redeployPrimary && controls.versioned && (
                            <ActionItem
                                icon={RedeployIcon}
                                label="Redeploy…"
                                hint="The running version again, without new commits"
                                blocked={redeployBlocked}
                                onSelect={() => ask('redeploy')}
                            />
                        )}
                        <ActionItem
                            icon={RestartIcon}
                            label="Restart…"
                            hint="New containers of the running version"
                            blocked={restartBlocked}
                            onSelect={() => ask('restart')}
                        />
                        <ActionItem
                            icon={StopIcon}
                            label="Stop…"
                            hint="Its domains show a stopped page until it starts"
                            blocked={stopBlocked}
                            destructive
                            onSelect={() => ask('stop')}
                        />
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </ConfirmPopover>
    );
}

/**
 * Holds the controls' place while the panel's request runs (docs/08 §2):
 * the same shape, invisible, so the title doesn't move when they arrive.
 */
export function ServiceControlsPlaceholder({
    node,
}: {
    node: App.Data.Canvas.CanvasNodeData;
}) {
    const versioned = node.kind === 'app' || node.kind === 'image';

    return (
        <div aria-hidden="true" className="invisible flex shrink-0 gap-2">
            <div className={cn('h-8', versioned ? 'w-[86px]' : 'w-[104px]')} />
            <div className="size-8" />
        </div>
    );
}

/**
 * One action of the `⋯` menu: icon, the verb (ending in "…": it asks
 * first) and one quiet line, what it does or, disabled, why it can't run.
 */
function ActionItem({
    icon: Icon,
    label,
    hint,
    blocked,
    destructive = false,
    onSelect,
}: {
    icon: typeof RestartIcon;
    label: string;
    hint: string;
    blocked: string | null;
    destructive?: boolean;
    onSelect: () => void;
}) {
    const disabled = blocked !== null;

    return (
        <DropdownMenuItem
            disabled={disabled}
            variant={destructive && !disabled ? 'destructive' : 'default'}
            onSelect={onSelect}
            className="items-start data-[disabled]:opacity-100"
        >
            <Icon
                aria-hidden="true"
                className={cn('mt-0.5', disabled && 'opacity-50')}
            />
            <span className="flex flex-col">
                <span className={cn(disabled && 'text-muted-foreground')}>
                    {label}
                </span>
                <span className="type-small text-muted-foreground">
                    {blocked ?? hint}
                </span>
            </span>
        </DropdownMenuItem>
    );
}

/**
 * An action that can't run right now: in place, focusable and hoverable
 * (`aria-disabled`, not `disabled`), with a tooltip naming it and why.
 */
function BlockedButton({
    label,
    reason,
    children,
}: {
    label: string;
    reason: string;
    children: ReactNode;
}) {
    const id = useId();

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button
                    size="sm"
                    variant="outline"
                    aria-label={label}
                    aria-disabled="true"
                    aria-describedby={id}
                    onClick={(event) => event.preventDefault()}
                    className="cursor-not-allowed text-muted-foreground/60 hover:bg-background hover:text-muted-foreground/60 dark:hover:bg-transparent"
                >
                    {children}
                    <span id={id} className="sr-only">
                        {reason}
                    </span>
                </Button>
            </TooltipTrigger>
            <TooltipContent>
                <span className="flex flex-col">
                    <span className="font-medium">{label}</span>
                    <span className="opacity-70">{reason}</span>
                </span>
            </TooltipContent>
        </Tooltip>
    );
}

/**
 * Start of a stopped service: a popover that says what runs and, for an
 * app with workers or crons, whether they start too. The header's primary
 * while stopped and on the stopped row of the Deployments tab.
 */
export function StartButton({
    project,
    environment,
    service,
    name,
    processes: processCount,
    size = 'sm',
    align = 'start',
    onStarted,
}: {
    project: string;
    environment: string;
    service: string;
    name: string;
    processes: number;
    size?: 'sm' | 'default';
    align?: 'start' | 'end';
    onStarted?: () => void;
}) {
    const [processes, setProcesses] = useState(true);

    const start = () =>
        router.post(
            lifecycle.start({ project, environment, service }).url,
            { processes },
            {
                preserveScroll: true,
                preserveState: true,
                optimistic: optimisticService(
                    service,
                    { state: 'progress', label: 'Starting' },
                    { stopped: false, stoppedAt: null, stoppedBy: null },
                ),
                onSuccess: () => {
                    toast.success(`Starting ${name}`);
                    onStarted?.();
                },
                ...rollbackToast(`Couldn’t start ${name}.`),
            },
        );

    return (
        <ConfirmPopover
            align={align}
            title={`Start ${name}?`}
            consequence={
                <>
                    {LIFECYCLE_CONSEQUENCE.start}
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
            <Button size={size}>
                <StartIcon aria-hidden="true" />
                Start
            </Button>
        </ConfirmPopover>
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
        <label className="mt-2 flex items-center gap-2 type-small text-foreground">
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
 * configuration); with staged changes, "Review & apply", which opens the
 * environment's staged changes (D75).
 * The header's primary, and the action of the Deployments tab's empty
 * state.
 */
export function DeployButton({
    project,
    environment,
    service,
    name,
    staged,
    versioned = true,
    disabled = false,
    variant = 'default',
    className,
}: {
    project: string;
    environment: string;
    service: string;
    name: string;
    staged: boolean;
    versioned?: boolean;
    disabled?: boolean;
    variant?: 'default' | 'outline';
    className?: string;
}) {
    const [busy, setBusy] = useState(false);

    const deploy = () => {
        if (staged) {
            openStagedChanges();

            return;
        }

        setBusy(true);

        // The node says "Queued" on the click; the answer opens the new
        // deployment's page.
        router.post(
            lifecycle.deploy({ project, environment, service }).url,
            {},
            {
                optimistic: optimisticQueued(service),
                ...rollbackToast(`Couldn’t deploy ${name}.`),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button
                    size="sm"
                    variant={variant}
                    onClick={deploy}
                    disabled={disabled || busy}
                    className={className}
                >
                    <DeployIcon aria-hidden="true" />
                    {staged ? 'Review & apply' : 'Deploy'}
                </Button>
            </TooltipTrigger>
            <TooltipContent>
                {staged
                    ? 'Review the staged changes of this environment and apply them'
                    : versioned
                      ? 'Builds and deploys the latest version'
                      : 'Deploys the last deployed configuration again'}
            </TooltipContent>
        </Tooltip>
    );
}
