import { Form, Link } from '@inertiajs/react';
import { Ban, CircleCheck, Info, TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { ConfirmAction } from '@/components/confirm-action';
import { CopyValue } from '@/components/copy-value';
import InputError from '@/components/input-error';
import { Callout } from '@/components/patterns/callout';
import { MetaLine } from '@/components/patterns/meta-line';
import { Status } from '@/components/status';
import { StepCard } from '@/components/steps/step-card';
import type { GuidedStepStatus } from '@/components/steps/step-card';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { ConfirmForm } from '@/features/moves/confirm-form';
import { ActionRow, ContinueCommand } from '@/features/moves/continue-command';
import { DnsSwitchList } from '@/features/moves/dns-records';
import { DnsSwitchGuide, NewDashboardCard } from '@/features/moves/dns-switch';
import { itemState } from '@/features/moves/format';
import { MessageDetail } from '@/features/moves/message-detail';
import { MoveBlockers } from '@/features/moves/move-blockers';
import { MovePlanView } from '@/features/moves/move-plan';
import { MoveProgress } from '@/features/moves/move-progress';
import { fromMoveRecords } from '@/features/moves/shape';
import { TransferStrip } from '@/features/moves/transfer-strip';
import type { TransferSide } from '@/features/moves/transfer-strip';
import { TypedConfirm } from '@/features/moves/typed-confirm';
import type { DnsSwitchData, MoveBlockerData } from '@/features/moves/types';
import { deviceStatus } from '@/features/servers/format';
import { TimeAgo } from '@/components/time-ago';
import { formatBytes, plural } from '@/lib/format';
import { vocabulary } from '@/lib/vocabulary';
import {
    abort,
    approve,
    cancel,
    handover,
    retry,
    start,
    workloads,
} from '@/routes/move';

type Move = App.Data.Moves.ControlPlaneMoveData;
type MoveStatus = App.Enums.Moves.ControlPlaneMoveStatus;
type Check = App.Data.Moves.MovePreflightCheckData;
type FormDefinition = {
    action: string;
    method: 'get' | 'post' | 'put' | 'patch' | 'delete';
};

export type ControlPlaneMoveProps = {
    move: Move | null;
    server: App.Data.Moves.MoveServerData | null;
    command: string | null;
    preflight: Check[];
    plan: App.Data.Moves.MovePlanData | null;
    /** Deferred: DNS records with live checks. */
    dns?: App.Data.Moves.MoveDnsRecordData[];
    serverMove: App.Data.Moves.ServerMoveData | null;
    /** The command that continues the move on the new machine (install folder). */
    continueCommand?: string | null;
    /** What would make the hand-over or the takeover refuse right now. */
    handoverBlockers?: MoveBlockerData[];
    /** Deferred, from the hand-over on: the records to switch, live with `check`. */
    dnsSwitch?: DnsSwitchData | null;
    /** Leave a failed service stopped instead of retrying it. */
    stopForm?: (item: string) => FormDefinition;
    /** Runs the DNS checks of `dnsSwitch` again. */
    onCheckDns?: () => void;
    checkingDns?: boolean;
};

const moveStatuses = vocabulary.Moves.ControlPlaneMoveStatus;

const ORDER = Object.keys(moveStatuses) as MoveStatus[];

const checkIcons: Record<string, ReactNode> = {
    ok: <CircleCheck aria-hidden="true" className="size-4 text-status-ok" />,
    info: <Info aria-hidden="true" className="size-4 text-muted-foreground" />,
    warning: (
        <TriangleAlert
            aria-hidden="true"
            className="size-4 text-status-progress"
        />
    ),
    blocker: <Ban aria-hidden="true" className="size-4 text-status-error" />,
};

const checkWords: Record<string, string> = {
    ok: 'Ready',
    info: 'Note',
    warning: 'Check',
    blocker: 'Blocks the move',
};

function reached(status: MoveStatus | null, step: MoveStatus): boolean {
    return status !== null && ORDER.indexOf(status) >= ORDER.indexOf(step);
}

function stepFor(status: MoveStatus | null): string {
    if (status === null) {
        return 'prepare';
    }

    if (['waiting_for_server', 'claimed', 'joining'].includes(status)) {
        return 'connect';
    }

    if (['joined', 'moving'].includes(status)) {
        return 'workloads';
    }

    return status === 'handed_over' ? 'done' : 'handover';
}

/**
 * Settings → Move launchroom while the move runs on the old dashboard
 * (docs/14 §13.4): both machines on top, then the steps: prepare, connect
 * the new server, move the apps and data, hand over the dashboard.
 */
export function ControlPlaneMoveSteps({
    move,
    server,
    command,
    preflight,
    plan,
    dns,
    serverMove,
    continueCommand,
    handoverBlockers = [],
    dnsSwitch,
    stopForm,
    onCheckDns,
    checkingDns,
}: ControlPlaneMoveProps) {
    const [shownCommand, setShownCommand] = useState(command);
    const status: MoveStatus | null =
        move === null || move.status === 'cancelled' ? null : move.status;
    const [openId, setOpenId] = useState<string>(stepFor(status));
    const [followed, setFollowed] = useState(status);

    // Follow the move to its next step.
    if (followed !== status) {
        setFollowed(status);
        setOpenId(stepFor(status));
    }

    if (command !== null && command !== shownCommand) {
        setShownCommand(command);
    }

    const stepStatus = (done: boolean): GuidedStepStatus =>
        done ? 'done' : 'pending';
    const blockers = preflight.filter((check) => check.status === 'blocker');
    const target =
        move?.target?.name ?? move?.receiverHostname ?? 'the new server';

    if (status === 'handed_over' && move !== null) {
        return (
            <HandedOver
                move={move}
                serverMove={serverMove}
                dnsSwitch={dnsSwitch}
                onCheck={onCheckDns ?? (() => {})}
                checking={checkingDns}
            />
        );
    }

    return (
        <>
            <p className="max-w-[65ch] type-body text-muted-foreground">
                Move the dashboard and everything it runs to another machine,
                without losing data. Apps keep serving while they move, and each
                database and volume stops briefly while it is copied.
            </p>

            {move?.status === 'cancelled' && (
                <div className="flex flex-col gap-1 type-small text-muted-foreground">
                    <p>The last move was cancelled.</p>
                    {move.error !== null && (
                        <MessageDetail
                            message={move.error}
                            fallback="It stopped with an error."
                        />
                    )}
                </div>
            )}

            <MachinesStrip
                move={status === null ? null : move}
                server={server}
                serverMove={serverMove}
            />

            <ol className="flex flex-col gap-3">
                <StepCard
                    id="prepare"
                    number={1}
                    status={stepStatus(status !== null)}
                    title="Prepare"
                    line={
                        status !== null
                            ? 'Move started'
                            : blockers.length > 0
                              ? `${plural(blockers.length, 'thing')} to fix first`
                              : `Move ${server?.name ?? 'this server'} to a new machine`
                    }
                    open={openId === 'prepare'}
                    onOpen={() => setOpenId('prepare')}
                >
                    <CheckList checks={preflight} />
                    {status === null && (
                        <Form {...start.form()}>
                            {({ processing }) => (
                                <ActionRow
                                    reason={
                                        blockers.length > 0
                                            ? 'Fix what blocks the move first.'
                                            : 'Creates a one-time command for the new machine.'
                                    }
                                >
                                    <Button
                                        type="submit"
                                        disabled={
                                            processing || blockers.length > 0
                                        }
                                    >
                                        {processing && <Spinner />}
                                        Create move command
                                    </Button>
                                </ActionRow>
                            )}
                        </Form>
                    )}
                </StepCard>

                <StepCard
                    id="connect"
                    number={2}
                    status={stepStatus(reached(status, 'joined'))}
                    title="Connect the new server"
                    line={
                        reached(status, 'joined')
                            ? `${target} is connected`
                            : status === 'claimed'
                              ? 'Confirm the code from the new machine'
                              : status === 'joining'
                                ? `${target} is connecting`
                                : 'Run one command on the new machine'
                    }
                    open={openId === 'connect'}
                    onOpen={() => setOpenId('connect')}
                >
                    {status === null && (
                        <p className="type-body text-muted-foreground">
                            Create the move command first.
                        </p>
                    )}
                    {status === 'waiting_for_server' && (
                        <>
                            <p className="max-w-[65ch] type-body">
                                Install Docker on the new machine, then run this
                                there. It installs launchroom and connects the
                                machine to this dashboard.
                            </p>
                            {shownCommand !== null ? (
                                <CopyValue
                                    value={shownCommand}
                                    label="move command"
                                />
                            ) : (
                                <p className="type-small text-muted-foreground">
                                    The command is shown only once. If you lost
                                    it, cancel this move and start a new one.
                                </p>
                            )}
                            <div className="flex flex-col gap-1">
                                <p className="flex items-center gap-2 type-body">
                                    <Spinner />
                                    Waiting for the new machine
                                </p>
                                <p className="type-small text-muted-foreground">
                                    The command works once
                                    {move?.tokenExpiresAt
                                        ? `, until ${new Date(move.tokenExpiresAt).toLocaleString()}`
                                        : ''}
                                    .
                                </p>
                            </div>
                        </>
                    )}
                    {status === 'claimed' && move !== null && (
                        <ClaimApproval move={move} blockers={blockers} />
                    )}
                    {status === 'joining' &&
                        (move?.error ? (
                            <>
                                <Callout
                                    tone="warning"
                                    title={`${target} hasn’t connected yet`}
                                >
                                    <MessageDetail
                                        message={move.error}
                                        fallback="The new machine reported an error while joining."
                                    />
                                </Callout>
                                <ContinueCommand command={continueCommand} />
                            </>
                        ) : (
                            <p className="flex items-center gap-2 type-body">
                                <Spinner />
                                {target} is connecting. This takes about a
                                minute.
                            </p>
                        ))}
                    {reached(status, 'joined') && (
                        <p className="type-body text-muted-foreground">
                            {target} is connected as a server of this dashboard
                            {move?.target?.platform
                                ? ` (${move.target.platform})`
                                : ''}
                            .
                        </p>
                    )}
                </StepCard>

                <StepCard
                    id="workloads"
                    number={3}
                    status={stepStatus(reached(status, 'ready_to_hand_over'))}
                    title="Move apps and data"
                    line={
                        reached(status, 'ready_to_hand_over')
                            ? `Everything runs on ${target}`
                            : serverMove !== null
                              ? workloadsLine(serverMove)
                              : `To ${target}, one database at a time`
                    }
                    open={openId === 'workloads'}
                    onOpen={() => setOpenId('workloads')}
                >
                    {!reached(status, 'joined') && (
                        <p className="type-body text-muted-foreground">
                            Connect the new server first.
                        </p>
                    )}
                    {status === 'joined' && plan !== null && (
                        <WorkloadPlan plan={plan} />
                    )}
                    {serverMove !== null && reached(status, 'moving') && (
                        <MoveProgress
                            move={serverMove}
                            strip={false}
                            retryForm={(item) => retry.form(item)}
                            stopForm={stopForm}
                        />
                    )}
                </StepCard>

                <StepCard
                    id="handover"
                    number={4}
                    status={stepStatus(reached(status, 'handed_over'))}
                    title="Hand over the dashboard"
                    line={
                        status === 'handing_over'
                            ? 'Taking the final copy'
                            : status === 'handover_ready'
                              ? 'The new server is starting the dashboard'
                              : reached(status, 'handed_over')
                                ? 'Done'
                                : 'A short pause for changes, apps keep serving'
                    }
                    open={openId === 'handover'}
                    onOpen={() => setOpenId('handover')}
                >
                    {!reached(status, 'ready_to_hand_over') && (
                        <p className="type-body text-muted-foreground">
                            Possible once everything runs on the new server.
                        </p>
                    )}
                    {status === 'ready_to_hand_over' && move !== null && (
                        <HandOver
                            move={move}
                            dns={dns}
                            blockers={handoverBlockers}
                            continueCommand={continueCommand}
                        />
                    )}
                    {(status === 'handing_over' ||
                        status === 'handover_ready') &&
                        move !== null && (
                            <HandingOver
                                move={move}
                                continueCommand={continueCommand}
                            />
                        )}
                </StepCard>

                <StepCard
                    id="done"
                    number={5}
                    status={stepStatus(status === 'handed_over')}
                    title="Switch DNS"
                    line={
                        move?.newUrl ??
                        'The new dashboard opens with a checklist'
                    }
                    open={openId === 'done'}
                    onOpen={() => setOpenId('done')}
                >
                    {move?.status === 'handed_over' && move.newUrl !== null ? (
                        <p className="type-body">
                            launchroom runs at{' '}
                            <a
                                href={move.newUrl}
                                className="underline underline-offset-4"
                            >
                                {move.newUrl}
                            </a>
                            . Sign in there to finish the checklist.
                        </p>
                    ) : (
                        <p className="max-w-[65ch] type-body text-muted-foreground">
                            After the hand-over, point the dashboard’s address,
                            your apps domain and custom domains at the new
                            server. Both dashboards show each record with a live
                            check.
                        </p>
                    )}
                </StepCard>
            </ol>

            {status !== null && moveStatuses[status].isCancellable && (
                <div className="flex flex-col gap-3 rounded-xl border bg-card p-5 shadow-card sm:flex-row sm:items-center">
                    <p className="min-w-0 flex-1 type-small text-muted-foreground">
                        If you cancel, launchroom stays on this server. Services
                        that already moved stay on the new server, which then
                        works as a regular server.
                    </p>
                    <ConfirmForm
                        title="Cancel the move?"
                        consequence="The move command stops working, and a database or volume that is being copied goes back to this server."
                        confirmLabel="Cancel move"
                        form={cancel.form()}
                    >
                        <Button variant="destructive">Cancel move</Button>
                    </ConfirmForm>
                </div>
            )}
        </>
    );
}

function workloadsLine(move: App.Data.Moves.ServerMoveData): string {
    const total = move.items.length;
    const failed = move.items.filter((item) => item.status === 'failed');

    if (failed.length > 0) {
        return `${move.done} of ${total} moved, ${failed.length} failed`;
    }

    const current = move.items.find(
        (item) => itemState(item)[0] === 'progress',
    );

    return current
        ? `${move.done} of ${total} moved, now ${current.name}`
        : `${move.done} of ${total} moved`;
}

/**
 * The dashboard's server and the new machine, with what happens between
 * them right now (device art, docs/design-system §7.1).
 */
function MachinesStrip({
    move,
    server,
    serverMove,
}: {
    move: Move | null;
    server: App.Data.Moves.MoveServerData | null;
    serverMove: App.Data.Moves.ServerMoveData | null;
}) {
    const sourceData = move?.source ?? server;
    const status = move?.status ?? null;
    const handedOver = status === 'handed_over';

    if (sourceData === null) {
        return null;
    }

    const source: TransferSide = {
        name: sourceData.name,
        device: sourceData.device,
        status: handedOver ? 'offline' : deviceStatus(sourceData.status),
        gone: handedOver,
        tags: handedOver ? ['Retired'] : ['Runs the dashboard'],
        detail:
            serverMove !== null && !handedOver
                ? `${plural(serverMove.items.length - serverMove.done, 'service')} left`
                : null,
    };
    const targetData = move?.target ?? null;
    const target: TransferSide =
        targetData !== null
            ? {
                  name: targetData.name,
                  device: targetData.device,
                  status: deviceStatus(targetData.status),
                  tags: handedOver ? ['Runs the dashboard'] : undefined,
                  detail:
                      serverMove !== null && !handedOver
                          ? `${plural(serverMove.done, 'service')} here`
                          : null,
              }
            : {
                  name: move?.receiverHostname ?? 'New machine',
                  device: 'unknown',
                  status: 'pending',
                  ghost: true,
                  detail: move?.receiverArch ?? 'Not connected yet',
              };

    return (
        <TransferStrip
            source={source}
            target={target}
            step={stripStep(move, serverMove)}
        />
    );
}

function stripStep(
    move: Move | null,
    serverMove: App.Data.Moves.ServerMoveData | null,
): string | null {
    switch (move?.status ?? null) {
        case 'waiting_for_server':
            return 'Waiting for the new machine';
        case 'claimed':
            return 'Confirm the code';
        case 'joining':
            return 'Connecting';
        case 'joined':
            return 'Connected, ready to move';
        case 'moving': {
            const current = serverMove?.items.find(
                (item) => itemState(item)[0] === 'progress',
            );

            if (!current) {
                return 'Moving apps and data';
            }

            const bytes =
                current.copiedBytes !== null && current.expectedBytes !== null
                    ? `, ${formatBytes(current.copiedBytes)} of ${formatBytes(current.expectedBytes)}`
                    : '';

            return `${itemState(current)[1]} ${current.name}${bytes}`;
        }
        case 'ready_to_hand_over':
            return 'Ready to hand over the dashboard';
        case 'handing_over':
            return 'Taking the final copy';
        case 'handover_ready':
            return 'Starting the new dashboard';
        case 'handed_over':
            return 'Handed over';
        default:
            return null;
    }
}

function CheckList({ checks }: { checks: Check[] }) {
    return (
        <ul className="flex flex-col gap-3">
            {checks.map((check) => (
                <li key={check.key} className="flex gap-3">
                    <span className="mt-0.5 shrink-0">
                        {checkIcons[check.status] ?? checkIcons.info}
                    </span>
                    <span className="flex min-w-0 flex-col gap-0.5">
                        <span className="type-body font-medium">
                            {check.label}
                            <span className="sr-only">
                                : {checkWords[check.status] ?? check.status}
                            </span>
                        </span>
                        <span className="type-small text-muted-foreground">
                            {check.message}
                        </span>
                        {check.href !== null && (
                            <Link
                                href={check.href}
                                className="w-fit type-small underline underline-offset-4"
                            >
                                {check.hrefLabel ?? 'Open'}
                            </Link>
                        )}
                    </span>
                </li>
            ))}
        </ul>
    );
}

/** Blockers as callouts: what refuses, why, and the way to it. */
function BlockerList({ checks }: { checks: Check[] }) {
    return (
        <div className="flex flex-col gap-2">
            {checks.map((check) => (
                <Callout
                    key={check.key}
                    tone="error"
                    title={check.label}
                    icon={<Ban aria-hidden="true" />}
                    actions={
                        check.href !== null ? (
                            <Link
                                href={check.href}
                                className="underline underline-offset-4"
                            >
                                {check.hrefLabel ?? 'Open'}
                            </Link>
                        ) : undefined
                    }
                >
                    {check.message}
                </Callout>
            ))}
        </div>
    );
}

function ClaimApproval({ move, blockers }: { move: Move; blockers: Check[] }) {
    const [code, setCode] = useState('');
    const connected = move.target;

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-col gap-1 rounded-xl bg-recessed px-4 py-3">
                <span className="type-small text-muted-foreground">
                    Claimed by
                </span>
                <MetaLine className="type-body break-words">
                    {move.receiverHostname ?? 'Unknown host'}
                    {move.receiverArch}
                    {move.receiverAddress}
                </MetaLine>
                {connected !== null && (
                    <span className="type-small">
                        Already connected as{' '}
                        <span className="font-medium">{connected.name}</span>.
                        The move continues with it, without joining again.
                    </span>
                )}
            </div>
            {blockers.length > 0 && <BlockerList checks={blockers} />}
            <p className="max-w-[65ch] type-body">
                Type the code from the new machine’s terminal. If it shows an
                error or no code, someone else claimed this move: cancel it.
            </p>
            <Form
                {...approve.form()}
                transform={(data) => ({ ...data, code })}
                className="flex flex-col gap-2"
            >
                {({ processing, errors }) => (
                    <>
                        <Label htmlFor="claim-code">Code</Label>
                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                            <Input
                                id="claim-code"
                                value={code}
                                autoComplete="off"
                                placeholder="XXXX-XXXX"
                                className="type-mono sm:max-w-48"
                                onChange={(event) =>
                                    setCode(event.target.value.toUpperCase())
                                }
                            />
                            <ActionRow
                                reason={
                                    blockers.length > 0
                                        ? 'Fix what blocks the move first.'
                                        : null
                                }
                            >
                                <Button
                                    type="submit"
                                    disabled={
                                        processing ||
                                        code.trim() === '' ||
                                        blockers.length > 0
                                    }
                                >
                                    {processing && <Spinner />}
                                    {connected !== null
                                        ? `Continue with ${connected.name}`
                                        : 'Connect the server'}
                                </Button>
                            </ActionRow>
                        </div>
                        <InputError message={errors.code} />
                    </>
                )}
            </Form>
        </div>
    );
}

function WorkloadPlan({ plan }: { plan: App.Data.Moves.MovePlanData }) {
    const [backupFirst, setBackupFirst] = useState(false);

    return (
        <div className="flex flex-col gap-6">
            <MovePlanView plan={plan} />
            <Form
                {...workloads.form()}
                transform={(data) => ({ ...data, backupFirst })}
                className="flex flex-col gap-4"
            >
                {({ processing }) => (
                    <>
                        {plan.canBackUpFirst && (
                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id="cp-backup-first"
                                    className="mt-0.5"
                                    checked={backupFirst}
                                    onCheckedChange={(checked) =>
                                        setBackupFirst(checked === true)
                                    }
                                />
                                <Label
                                    htmlFor="cp-backup-first"
                                    className="flex flex-col gap-0.5 font-normal"
                                >
                                    <span className="type-body font-medium">
                                        Back up each database and volume first
                                    </span>
                                    <span className="type-small text-muted-foreground">
                                        Optional. The move copies data directly.
                                    </span>
                                </Label>
                            </div>
                        )}
                        <ActionRow
                            reason={
                                plan.blockers.length > 0
                                    ? `Fix ${plan.blockers.length === 1 ? 'the blocker' : 'the blockers'} above first.`
                                    : null
                            }
                        >
                            <Button
                                type="submit"
                                disabled={
                                    processing || plan.blockers.length > 0
                                }
                            >
                                {processing && <Spinner />}
                                Move apps and data
                            </Button>
                        </ActionRow>
                    </>
                )}
            </Form>
        </div>
    );
}

function HandOver({
    move,
    dns,
    blockers,
    continueCommand,
}: {
    move: Move;
    dns?: App.Data.Moves.MoveDnsRecordData[];
    blockers: MoveBlockerData[];
    continueCommand?: string | null;
}) {
    const reason =
        blockers.length > 0
            ? `Possible once nothing is left on ${blockers[0].server}.`
            : !move.receiverWaiting
              ? 'The new machine isn’t waiting for the hand-over.'
              : null;

    return (
        <div className="flex flex-col gap-5">
            <ol className="flex list-decimal flex-col gap-1.5 pl-5 type-body marker:text-muted-foreground">
                <li>
                    This dashboard pauses changes and takes a final copy of
                    itself, usually in under a minute.
                </li>
                <li>Apps keep serving from the new server.</li>
                <li>
                    The new server restores the copy and starts the dashboard.
                </li>
                <li>You point DNS at the new server.</li>
            </ol>
            {dns !== undefined && dns.length > 0 && (
                <div className="flex flex-col gap-2">
                    <h3 className="type-label">
                        Records to change after the hand-over
                    </h3>
                    <p className="type-small text-muted-foreground">
                        Lower their TTL now, so the switch reaches everyone
                        sooner.
                    </p>
                    <DnsSwitchList
                        records={fromMoveRecords(dns, dns)}
                        preview
                    />
                </div>
            )}
            {blockers.length > 0 && (
                <MoveBlockers blockers={blockers}>
                    The new server can’t take over until nothing is left there.
                    Open each one to move or stop it, then hand over.
                </MoveBlockers>
            )}
            {move.receiverWaiting ? (
                <Status state="active" label="The new machine is waiting" />
            ) : (
                <ContinueCommand
                    command={continueCommand}
                    title="The new machine isn’t waiting"
                >
                    Run this in the install folder on the new machine. It waits
                    there for the hand-over.
                </ContinueCommand>
            )}
            {!move.receiverWaiting && !continueCommand && (
                <Status
                    state="warning"
                    label="The new machine isn’t waiting"
                    detail="Run the move command on it again"
                />
            )}
            {move.error !== null && (
                <MessageDetail
                    message={move.error}
                    fallback="The last hand-over stopped with an error."
                    className="type-small text-muted-foreground"
                />
            )}
            <ActionRow reason={reason}>
                <TypedConfirm
                    trigger="Hand over"
                    title="Hand over the dashboard?"
                    description={
                        <>
                            <span>
                                This dashboard stops taking changes and sends a
                                final copy to the new server. After that, the
                                new dashboard is the one to use.
                            </span>
                            <span>
                                Keep this machine until the new dashboard runs,
                                so you can go back if needed.
                            </span>
                        </>
                    }
                    expected="hand over"
                    confirmLabel="Hand over"
                    form={handover.form()}
                    disabled={!move.receiverWaiting || blockers.length > 0}
                    loose
                />
            </ActionRow>
        </div>
    );
}

function HandingOver({
    move,
    continueCommand,
}: {
    move: Move;
    continueCommand?: string | null;
}) {
    return (
        <div className="flex flex-col gap-4">
            <p className="flex items-center gap-2 type-body">
                <Spinner />
                {move.status === 'handing_over'
                    ? 'Taking the final copy. Changes are paused.'
                    : `The new server is fetching the copy${move.dumpSize !== null ? ` (${move.dumpSize})` : ''} and starting the dashboard.`}
            </p>
            {move.status === 'handover_ready' && (
                <ContinueCommand command={continueCommand}>
                    If the install on the new machine stopped, run this in its
                    install folder. It finishes the hand-over from where it
                    stopped.
                </ContinueCommand>
            )}
            <div className="flex flex-col gap-3 border-t pt-4 sm:flex-row sm:items-center">
                <p className="min-w-0 flex-1 type-small text-muted-foreground">
                    Abort only if the new machine’s install stopped with an
                    error and can’t continue. This dashboard then takes changes
                    again.
                </p>
                <ConfirmAction
                    trigger="Abort hand-over"
                    title="Abort the hand-over?"
                    description="Do this only if the new server failed. If its dashboard already runs, aborting leaves two dashboards that both think they are in charge."
                    confirmLabel="Abort hand-over"
                    form={abort.form()}
                    destructive
                />
            </div>
        </div>
    );
}

/**
 * The old dashboard after the hand-over (its final screen, docs/14 §13.4):
 * where launchroom runs now, the records to switch with a live check each,
 * and how to reach the new dashboard before DNS switched.
 */
function HandedOver({
    move,
    serverMove,
    dnsSwitch,
    onCheck,
    checking,
}: {
    move: Move;
    serverMove: App.Data.Moves.ServerMoveData | null;
    dnsSwitch: DnsSwitchData | null | undefined;
    onCheck: () => void;
    checking?: boolean;
}) {
    const pending =
        dnsSwitch !== null &&
        dnsSwitch !== undefined &&
        dnsSwitch.records.length > 0 &&
        (!dnsSwitch.checked || dnsSwitch.pointed < dnsSwitch.total);

    return (
        <>
            <p className="max-w-[65ch] type-body text-muted-foreground">
                launchroom moved to {move.target?.name ?? 'the new server'}
                {move.handedOverAt !== null && (
                    <>
                        {' '}
                        <TimeAgo value={move.handedOverAt} />
                    </>
                )}
                . This dashboard is retired: it shows the move and takes no
                changes.
            </p>

            <MachinesStrip move={move} server={null} serverMove={serverMove} />

            <NewDashboardCard newUrl={move.newUrl} data={dnsSwitch} />

            <DnsSwitchGuide
                data={dnsSwitch}
                onCheck={onCheck}
                checking={checking}
            />

            {pending && (
                <p className="max-w-[65ch] type-small text-muted-foreground">
                    Until a record switches, its address reaches this machine,
                    which no longer serves anything. This page checks the
                    records again every 15 seconds.
                </p>
            )}

            <p className="max-w-[65ch] type-small text-muted-foreground">
                The new dashboard’s checklist covers the rest: app images, other
                servers and deleting this machine.
            </p>
        </>
    );
}
