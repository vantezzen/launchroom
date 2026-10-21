import { Form, Head, Link, router } from '@inertiajs/react';
import { Ban, CircleCheck, Info, TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useState } from 'react';
import { ConfirmAction } from '@/components/confirm-action';
import { CopyValue } from '@/components/copy-value';
import InputError from '@/components/input-error';
import type { StatusState } from '@/components/status';
import { Status } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { DnsRecords } from '@/features/moves/dns-records';
import { MovePlanView } from '@/features/moves/move-plan';
import { MoveProgress } from '@/features/moves/move-progress';
import { TypedConfirm } from '@/features/moves/typed-confirm';
import { StepCard } from '@/features/onboarding/step-card';
import type { GuidedStepStatus } from '@/features/onboarding/step-card';
import { settings as backupSettings } from '@/routes/backups';
import {
    abort,
    approve,
    cancel,
    edit,
    finish,
    handover,
    rebuild,
    retry,
    start,
    workloads,
} from '@/routes/move';
import { edit as teamSettings } from '@/routes/team';

type Move = App.Data.Moves.ControlPlaneMoveData;
type MoveStatus = App.Enums.Moves.ControlPlaneMoveStatus;

type Checklist = {
    startedAt: string | null;
    previousUrl: string | null;
    url: string;
    previous: { name: string; addresses: string[] } | null;
    current: { name: string; addresses: string[] } | null;
    expectedAddresses: string[];
    dns: {
        label: string;
        host: string;
        check: App.Data.Domains.DnsCheckData | null;
    }[];
    servers: {
        id: string;
        name: string;
        status: App.Enums.ServerStatus;
        reconnected: boolean;
    }[];
    images: {
        checked: number;
        missing: { service: string; deployment: string; commit: string }[];
    } | null;
    needsRecoveryKit: boolean;
};

type Props = {
    move: Move | null;
    server: App.Data.Moves.MoveServerData | null;
    command: string | null;
    preflight: App.Data.Moves.MovePreflightCheckData[];
    plan: App.Data.Moves.MovePlanData | null;
    /** Deferred: DNS records with live checks. */
    dns?: App.Data.Moves.MoveDnsRecordData[];
    serverMove: App.Data.Moves.ServerMoveData | null;
    checklist: Checklist | null;
};

const ORDER: MoveStatus[] = [
    'waiting_for_server',
    'claimed',
    'joining',
    'joined',
    'moving',
    'ready_to_hand_over',
    'handing_over',
    'handover_ready',
    'handed_over',
];

const CANCELLABLE: MoveStatus[] = [
    'waiting_for_server',
    'claimed',
    'joining',
    'joined',
    'moving',
    'ready_to_hand_over',
];

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

const dnsStates: Record<string, StatusState> = {
    ok: 'active',
    missing: 'warning',
    mismatch: 'warning',
    unknown: 'warning',
    error: 'warning',
};

function reached(status: MoveStatus | null, step: MoveStatus): boolean {
    return status !== null && ORDER.indexOf(status) >= ORDER.indexOf(step);
}

/**
 * Settings → Move launchroom (docs/14 §13.4): prepare, connect the new
 * server, move the apps and data, hand over the dashboard. On the new
 * dashboard after a move, the checklist (DNS, images, servers, the old
 * machine).
 */
export default function MoveLaunchroom(props: Props) {
    return props.checklist !== null ? (
        <MoveChecklist checklist={props.checklist} />
    ) : (
        <MoveSteps {...props} />
    );
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

function MoveSteps({
    move,
    server,
    command,
    preflight,
    plan,
    dns,
    serverMove,
}: Props) {
    const [shownCommand, setShownCommand] = useState(command);
    const status: MoveStatus | null =
        move === null || move.status === 'cancelled' ? null : move.status;
    const open = status !== null && status !== 'handed_over';
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

    useEffect(() => {
        if (!open) {
            return;
        }

        const timer = window.setInterval(
            () =>
                router.reload({
                    only: ['move', 'serverMove', 'plan', 'preflight'],
                }),
            4000,
        );

        return () => window.clearInterval(timer);
    }, [open]);

    const stepStatus = (done: boolean): GuidedStepStatus =>
        done ? 'done' : 'pending';
    const blocked = preflight.some((check) => check.status === 'blocker');
    const target =
        move?.target?.name ?? move?.receiverHostname ?? 'the new server';

    return (
        <>
            <Head title="Move launchroom" />

            <p className="max-w-[65ch] type-body text-muted-foreground">
                Move the dashboard and everything it runs to another machine,
                without losing data. Apps keep serving while they move; each
                database and volume stops for a short copy.
            </p>

            {move?.status === 'cancelled' && (
                <p className="type-small text-muted-foreground">
                    The last move was cancelled
                    {move.error !== null ? `: ${move.error}` : '.'}
                </p>
            )}

            <ol className="flex flex-col gap-3">
                <StepCard
                    id="prepare"
                    number={1}
                    status={stepStatus(status !== null)}
                    title="Prepare"
                    line={
                        status !== null
                            ? 'Move started'
                            : blocked
                              ? 'Something needs attention first'
                              : `Move ${server?.name ?? 'this server'} to a new machine`
                    }
                    open={openId === 'prepare'}
                    onOpen={() => setOpenId('prepare')}
                >
                    <ul className="flex flex-col gap-3">
                        {preflight.map((check) => (
                            <li key={check.key} className="flex gap-3">
                                <span className="mt-0.5 shrink-0">
                                    {checkIcons[check.status] ??
                                        checkIcons.info}
                                </span>
                                <span className="flex min-w-0 flex-col gap-0.5">
                                    <span className="type-body font-medium">
                                        {check.label}
                                        <span className="sr-only">
                                            : {checkWords[check.status]}
                                        </span>
                                    </span>
                                    <span className="type-small text-muted-foreground">
                                        {check.message}
                                    </span>
                                </span>
                            </li>
                        ))}
                    </ul>
                    {status === null && (
                        <Form {...start.form()}>
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    disabled={processing || blocked}
                                >
                                    {processing && <Spinner />}
                                    Create move command
                                </Button>
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
                            ? `${target} joined`
                            : status === 'claimed'
                              ? 'Confirm the new server’s code'
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
                            <p className="type-small text-muted-foreground">
                                Works once
                                {move?.tokenExpiresAt && (
                                    <>
                                        , until{' '}
                                        {new Date(
                                            move.tokenExpiresAt,
                                        ).toLocaleString()}
                                    </>
                                )}
                                . Waiting for the new machine…
                            </p>
                        </>
                    )}
                    {status === 'claimed' && move !== null && (
                        <ClaimApproval move={move} />
                    )}
                    {status === 'joining' && (
                        <p className="flex items-center gap-2 type-body">
                            <Spinner />
                            {target} is connecting.
                        </p>
                    )}
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
                              ? `${serverMove.done} of ${serverMove.items.length} moved`
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
                            retryForm={(item) => retry.form(item)}
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
                              ? 'The new server is restoring it'
                              : reached(status, 'handed_over')
                                ? 'Done'
                                : 'A short pause for changes'
                    }
                    open={openId === 'handover'}
                    onOpen={() => setOpenId('handover')}
                >
                    {!reached(status, 'ready_to_hand_over') && (
                        <p className="type-body text-muted-foreground">
                            Once everything runs on the new server.
                        </p>
                    )}
                    {status === 'ready_to_hand_over' && move !== null && (
                        <HandOver move={move} dns={dns} />
                    )}
                    {(status === 'handing_over' ||
                        status === 'handover_ready') &&
                        move !== null && <HandingOver move={move} />}
                </StepCard>

                <StepCard
                    id="done"
                    number={5}
                    status={stepStatus(status === 'handed_over')}
                    title="Done"
                    line={
                        move?.newUrl ??
                        'The new dashboard takes over at its address'
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
                        <p className="type-body text-muted-foreground">
                            The new dashboard opens with a checklist: DNS,
                            images and the old machine.
                        </p>
                    )}
                </StepCard>
            </ol>

            {status !== null && CANCELLABLE.includes(status) && (
                <div className="flex flex-col gap-3 rounded-xl border bg-card p-5 shadow-card sm:flex-row sm:items-center">
                    <p className="min-w-0 flex-1 type-small text-muted-foreground">
                        Cancelling keeps launchroom here. Services that already
                        run on the new server stay there; it stays a regular
                        server.
                    </p>
                    <ConfirmAction
                        trigger="Cancel move"
                        title="Cancel the move?"
                        description="The move command stops working. A database or volume that is being copied goes back to this server. Services that already moved stay on the new server."
                        confirmLabel="Cancel move"
                        form={cancel.form()}
                        destructive
                    />
                </div>
            )}
        </>
    );
}

function ClaimApproval({ move }: { move: Move }) {
    const [code, setCode] = useState('');

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-col gap-1 rounded-xl border bg-muted px-4 py-3">
                <span className="type-small text-muted-foreground">
                    A new machine claimed this move
                </span>
                <span className="type-body break-words">
                    {move.receiverHostname ?? 'Unknown host'}
                    {move.receiverArch !== null && ` · ${move.receiverArch}`}
                    {move.receiverAddress !== null &&
                        ` · ${move.receiverAddress}`}
                </span>
            </div>
            <p className="max-w-[65ch] type-body">
                Type the code the new server’s terminal shows. It only matches
                if the machine that claimed the move is yours. If the terminal
                shows an error or no code, someone else claimed the move: cancel
                it.
            </p>
            <Form
                {...approve.form()}
                transform={(data) => ({ ...data, code })}
                className="flex flex-col gap-2"
            >
                {({ processing, errors }) => (
                    <>
                        <Label htmlFor="claim-code">Code</Label>
                        <div className="flex flex-col gap-2 sm:flex-row">
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
                            <Button
                                type="submit"
                                disabled={processing || code.trim() === ''}
                            >
                                {processing && <Spinner />}
                                Connect the server
                            </Button>
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
                                        The move doesn’t need it: data is copied
                                        directly.
                                    </span>
                                </Label>
                            </div>
                        )}
                        <div>
                            <Button
                                type="submit"
                                disabled={
                                    processing || plan.blockers.length > 0
                                }
                            >
                                {processing && <Spinner />}
                                Move apps and data
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </div>
    );
}

function HandOver({
    move,
    dns,
}: {
    move: Move;
    dns?: App.Data.Moves.MoveDnsRecordData[];
}) {
    return (
        <div className="flex flex-col gap-5">
            <ul className="flex flex-col gap-1.5 type-body">
                <li>
                    Changes pause on this dashboard while it takes a final copy
                    of itself (usually under a minute).
                </li>
                <li>Apps keep serving: they already run on the new server.</li>
                <li>
                    The new server restores the copy and starts the dashboard.
                    Then switch DNS for the dashboard’s address.
                </li>
            </ul>
            {dns !== undefined && dns.length > 0 && (
                <DnsRecords records={dns} live={dns} />
            )}
            <Status
                state={move.receiverWaiting ? 'active' : 'warning'}
                label={
                    move.receiverWaiting
                        ? 'The new server is waiting'
                        : 'The new server isn’t waiting'
                }
                detail={
                    move.receiverWaiting
                        ? undefined
                        : 'Run the install command on it again'
                }
            />
            {move.error !== null && (
                <p className="type-small text-muted-foreground">{move.error}</p>
            )}
            <div>
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
                                Keep this machine until the new dashboard runs:
                                it is your way back.
                            </span>
                        </>
                    }
                    expected="hand over"
                    confirmLabel="Hand over"
                    form={handover.form()}
                    disabled={!move.receiverWaiting}
                    loose
                />
            </div>
        </div>
    );
}

function HandingOver({ move }: { move: Move }) {
    return (
        <div className="flex flex-col gap-4">
            <p className="flex items-center gap-2 type-body">
                <Spinner />
                {move.status === 'handing_over'
                    ? 'Taking the final copy. Changes are paused.'
                    : `The new server is fetching the copy${move.dumpSize !== null ? ` (${move.dumpSize})` : ''} and starting the dashboard.`}
            </p>
            <p className="max-w-[65ch] type-small text-muted-foreground">
                Abort only if the new server’s install stopped with an error.
                This dashboard then takes changes again.
            </p>
            <div>
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

function MoveChecklist({ checklist }: { checklist: Checklist }) {
    const dnsOk =
        checklist.dns.length > 0 &&
        checklist.dns.every((record) => record.check?.status === 'ok');
    const checked = checklist.dns.some((record) => record.check !== null);
    const imagesOk =
        checklist.images !== null && checklist.images.missing.length === 0;
    const serversOk = checklist.servers.every((server) => server.reconnected);
    const open = [
        ...(dnsOk ? [] : ['dns']),
        ...(imagesOk ? [] : ['images']),
        ...(serversOk ? [] : ['servers']),
        ...(checklist.needsRecoveryKit ? ['recovery-kit'] : []),
    ];
    const [openId, setOpenId] = useState<string | null>(
        open[0] === 'recovery-kit' ? 'old' : (open[0] ?? 'old'),
    );
    const check = () =>
        router.visit(edit({ query: { check: 1 } }), {
            preserveScroll: true,
            preserveState: true,
        });

    return (
        <>
            <Head title="Move launchroom" />

            <p className="max-w-[65ch] type-body text-muted-foreground">
                launchroom moved to {checklist.current?.name ?? 'this server'}
                {checklist.startedAt !== null && (
                    <>
                        {' '}
                        <TimeAgo value={checklist.startedAt} />
                    </>
                )}
                . Finish these steps, then close the checklist.
            </p>

            <ol className="flex flex-col gap-3">
                <StepCard
                    id="dns"
                    number={1}
                    status={dnsOk ? 'done' : 'pending'}
                    title="Point DNS at this server"
                    line={
                        dnsOk
                            ? 'Every hostname points here'
                            : checklist.expectedAddresses.join(', ') ||
                              undefined
                    }
                    open={openId === 'dns'}
                    onOpen={() => setOpenId('dns')}
                >
                    {checklist.dns.length === 0 ? (
                        <p className="type-body text-muted-foreground">
                            No public hostname to check.
                        </p>
                    ) : (
                        <ul className="flex flex-col gap-3">
                            {checklist.dns.map((record) => (
                                <li
                                    key={record.host}
                                    className="flex flex-col gap-0.5"
                                >
                                    <div className="flex flex-wrap items-center gap-3">
                                        <Status
                                            state={
                                                record.check === null
                                                    ? 'idle'
                                                    : (dnsStates[
                                                          record.check.status
                                                      ] ?? 'warning')
                                            }
                                            label={record.label}
                                        />
                                        <span className="type-mono-small break-all text-muted-foreground">
                                            {record.host}
                                        </span>
                                    </div>
                                    <span className="type-small text-muted-foreground">
                                        {record.check?.message ??
                                            `Point it at ${checklist.expectedAddresses.join(', ')}.`}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                    <div>
                        <Button variant="outline" size="sm" onClick={check}>
                            {checked ? 'Check again' : 'Check DNS'}
                        </Button>
                    </div>
                </StepCard>

                <StepCard
                    id="images"
                    number={2}
                    status={imagesOk ? 'done' : 'pending'}
                    title="Rebuild app images"
                    line={
                        imagesOk
                            ? 'All images are in the registry'
                            : checklist.images === null
                              ? 'Not checked yet'
                              : `${checklist.images.missing.length} not here yet`
                    }
                    open={openId === 'images'}
                    onOpen={() => setOpenId('images')}
                >
                    <p className="max-w-[65ch] type-body text-muted-foreground">
                        Images weren’t copied. Running apps keep theirs; each
                        app builds again from its commit on its next deploy.
                        Rebuild now to have them ready.
                    </p>
                    {checklist.images === null ? (
                        <div>
                            <Button variant="outline" size="sm" onClick={check}>
                                Check the registry
                            </Button>
                        </div>
                    ) : checklist.images.missing.length === 0 ? (
                        <p className="type-small text-muted-foreground">
                            All {checklist.images.checked} running images are in
                            the registry.
                        </p>
                    ) : (
                        <>
                            <ul className="flex flex-col gap-1 type-small">
                                {checklist.images.missing.map((item) => (
                                    <li key={item.deployment}>
                                        {item.service}{' '}
                                        <span className="type-mono-small text-muted-foreground">
                                            {item.commit}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                            <Form {...rebuild.form()}>
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        size="sm"
                                        disabled={processing}
                                    >
                                        {processing && <Spinner />}
                                        Rebuild now
                                    </Button>
                                )}
                            </Form>
                        </>
                    )}
                </StepCard>

                <StepCard
                    id="servers"
                    number={3}
                    status={serversOk ? 'done' : 'pending'}
                    title="Other servers"
                    line={
                        checklist.servers.length === 0
                            ? 'No other servers'
                            : serversOk
                              ? 'All reconnected'
                              : `${checklist.servers.filter((server) => !server.reconnected).length} not seen yet`
                    }
                    open={openId === 'servers'}
                    onOpen={() => setOpenId('servers')}
                >
                    {checklist.servers.length === 0 ? (
                        <p className="type-body text-muted-foreground">
                            There are no other servers.
                        </p>
                    ) : (
                        <ul className="flex flex-col gap-2">
                            {checklist.servers.map((server) => (
                                <li
                                    key={server.id}
                                    className="flex flex-col gap-0.5"
                                >
                                    <Status
                                        state={
                                            server.reconnected
                                                ? 'active'
                                                : 'warning'
                                        }
                                        label={server.name}
                                    />
                                    {!server.reconnected && (
                                        <span className="type-small text-muted-foreground">
                                            Not seen since the move. If the
                                            dashboard’s address changed, set
                                            LR_CONTROL_PLANE_URL in
                                            launchroomd/.env on that server and
                                            run docker compose up -d there.
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </StepCard>

                <StepCard
                    id="old"
                    number={4}
                    status="pending"
                    title="Delete the old machine"
                    line={checklist.previous?.name ?? 'When DNS points here'}
                    open={openId === 'old'}
                    onOpen={() => setOpenId('old')}
                >
                    <p className="max-w-[65ch] type-body">
                        {checklist.previous?.name ?? 'The old server'} is
                        retired: it runs nothing for launchroom any more and its
                        key is revoked. Once DNS points here, delete the
                        machine, or run{' '}
                        <code className="type-mono-small">
                            ./launchroom uninstall
                        </code>{' '}
                        in its install folder.
                    </p>
                    {checklist.previousUrl !== null &&
                        checklist.previousUrl !== checklist.url && (
                            <p className="type-small text-muted-foreground">
                                The dashboard’s address changed from{' '}
                                {checklist.previousUrl} to {checklist.url}.
                            </p>
                        )}
                    {checklist.needsRecoveryKit && (
                        <p className="type-small">
                            Download a new recovery kit:{' '}
                            <Link
                                href={backupSettings()}
                                className="underline underline-offset-4"
                            >
                                Settings → Backups
                            </Link>
                            .
                        </p>
                    )}
                </StepCard>
            </ol>

            <Form {...finish.form()} transform={(data) => ({ ...data, open })}>
                {({ processing }) => (
                    <div className="flex flex-wrap items-center gap-3">
                        <Button
                            type="submit"
                            variant={open.length === 0 ? 'default' : 'outline'}
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            Finish
                        </Button>
                        {open.length > 0 && (
                            <span className="type-small text-muted-foreground">
                                Some steps are still open; finishing records
                                that in the audit log.
                            </span>
                        )}
                    </div>
                )}
            </Form>
        </>
    );
}

MoveLaunchroom.layout = {
    breadcrumbs: [
        { title: 'Settings', href: teamSettings() },
        { title: 'Move launchroom', href: edit() },
    ],
};
