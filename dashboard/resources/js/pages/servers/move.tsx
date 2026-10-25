import { Head, Link, router } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { useEffect, useState } from 'react';
import { DeviceImage } from '@/components/devices/device-image';
import { ChoiceTiles } from '@/components/patterns/choice-tiles';
import { MetaDivider, MetaLine } from '@/components/patterns/meta-line';
import { Page, PageHeader } from '@/components/patterns/page';
import { Section } from '@/components/patterns/section';
import { ConfirmAction } from '@/components/confirm-action';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { DnsRecords } from '@/features/moves/dns-records';
import { formatDuration } from '@/features/moves/format';
import { MovePlanView } from '@/features/moves/move-plan';
import { MoveProgress } from '@/features/moves/move-progress';
import { TypedConfirm } from '@/features/moves/typed-confirm';
import { deviceStatus } from '@/features/servers/format';
import { roleLabels, ServerStatus } from '@/features/servers/server-status';
import { edit as moveLaunchroom } from '@/routes/move';
import { create, index, move as moveRoute, show } from '@/routes/servers';
import { cancel, retire, retry, store } from '@/routes/servers/move';

type Props = {
    server: App.Data.Moves.MoveServerData;
    candidates: App.Data.Moves.MoveServerData[];
    targetId: string | null;
    plan: App.Data.Moves.MovePlanData | null;
    /** Deferred: the DNS records with live checks. */
    dns?: App.Data.Moves.MoveDnsRecordData[];
    move: App.Data.Moves.ServerMoveData | null;
    canEnroll: boolean;
};

/** ["Runtime and Edge", "linux/amd64", "40 GB free"]. */
function describe(server: App.Data.Moves.MoveServerData): string[] {
    return [
        new Intl.ListFormat('en', { type: 'conjunction' }).format(
            server.roles.map((role) => roleLabels[role]),
        ),
        server.platform,
        server.diskFreeMb !== null
            ? `${Math.round(server.diskFreeMb / 1024)} GB free`
            : null,
    ].filter((part): part is string => part !== null && part !== '');
}

/**
 * Servers → (server) → Move to another server (docs/14 §13.2): pick the
 * server that takes over, review what moves and what stops briefly, start,
 * follow each service, switch DNS and retire the old server.
 */
export default function MoveServer({
    server,
    candidates,
    targetId,
    plan,
    dns,
    move,
    canEnroll,
}: Props) {
    const open =
        move !== null &&
        ['running', 'ready', 'cancelling'].includes(move.status);
    const polling =
        move !== null && ['running', 'cancelling'].includes(move.status);
    const [backupFirst, setBackupFirst] = useState(false);

    useEffect(() => {
        if (!polling) {
            return;
        }

        const timer = window.setInterval(
            () => router.reload({ only: ['move', 'server'] }),
            3000,
        );

        return () => window.clearInterval(timer);
    }, [polling]);

    const pickTarget = (id: string) =>
        router.visit(moveRoute(server.id, { query: { target: id } }), {
            only: ['plan', 'targetId', 'dns'],
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });

    const records = plan?.dns ?? dns ?? [];

    return (
        <>
            <Head title={`Move ${server.name}`} />

            <Page size="narrow" className="gap-10">
                <PageHeader
                    title={`Move ${server.name}`}
                    media={
                        <div className="flex size-20 items-center justify-center rounded-xl border bg-recessed">
                            <DeviceImage
                                device={server.device}
                                status={deviceStatus(server.status)}
                                size={72}
                            />
                        </div>
                    }
                    description={
                        <MetaLine>
                            <ServerStatus status={server.status} />
                            {describe(server)}
                        </MetaLine>
                    }
                />

                {move !== null && (
                    <>
                        <MoveProgress
                            move={move}
                            retryForm={(item) =>
                                retry.form({
                                    server: server.id,
                                    move: move.id,
                                    item,
                                })
                            }
                        />

                        {move.controlPlane ? (
                            <div className="flex flex-col items-start gap-3 rounded-xl border bg-card p-5 shadow-card">
                                <p className="type-body">
                                    This move is part of moving launchroom to a
                                    new server. Follow and finish it there.
                                </p>
                                <Button asChild variant="outline" size="sm">
                                    <Link href={moveLaunchroom()}>
                                        Open Move launchroom
                                    </Link>
                                </Button>
                            </div>
                        ) : (
                            open && (
                                <>
                                    <Section
                                        title="DNS"
                                        description={
                                            records.length > 0
                                                ? `Change these records to the addresses shown before you retire ${server.name}.`
                                                : undefined
                                        }
                                        actions={
                                            records.length > 0 ? (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        router.reload({
                                                            only: ['dns'],
                                                        })
                                                    }
                                                >
                                                    Check again
                                                </Button>
                                            ) : undefined
                                        }
                                    >
                                        <DnsRecords
                                            records={records}
                                            live={dns}
                                        />
                                    </Section>

                                    <div className="flex flex-col gap-4 rounded-xl border bg-card p-5 shadow-card sm:flex-row sm:items-center">
                                        <p className="min-w-0 flex-1 type-small text-muted-foreground">
                                            {move.status === 'ready'
                                                ? `Everything runs on ${move.target.name}. Retiring gives it ${server.name}’s roles and labels and stops ${server.name}. Its disk isn’t touched.`
                                                : move.status === 'cancelling'
                                                  ? 'Cancelling. Services that already moved stay on the new server.'
                                                  : `Cancelling moves the service that is being copied back. Services that already moved stay on ${move.target.name}.`}
                                        </p>
                                        <div className="flex flex-wrap gap-2">
                                            {move.status === 'running' && (
                                                <ConfirmAction
                                                    trigger="Cancel move"
                                                    title="Cancel the move?"
                                                    description={`Services that already run on ${move.target.name} stay there. The one being copied goes back to ${server.name} with its data. ${server.name} takes new work again.`}
                                                    confirmLabel="Cancel move"
                                                    form={cancel.form({
                                                        server: server.id,
                                                        move: move.id,
                                                    })}
                                                />
                                            )}
                                            {move.status === 'ready' && (
                                                <TypedConfirm
                                                    trigger={`Retire ${server.name}`}
                                                    title={`Retire ${server.name}?`}
                                                    description={
                                                        <>
                                                            <span>
                                                                {
                                                                    move.target
                                                                        .name
                                                                }{' '}
                                                                takes over its
                                                                roles and
                                                                labels.{' '}
                                                                {server.name}{' '}
                                                                stops its apps
                                                                and its key is
                                                                revoked.
                                                            </span>
                                                            <span>
                                                                Switch DNS
                                                                first: traffic
                                                                that still
                                                                reaches{' '}
                                                                {server.name}{' '}
                                                                fails
                                                                afterwards.
                                                            </span>
                                                        </>
                                                    }
                                                    expected={server.name}
                                                    confirmLabel="Retire server"
                                                    form={retire.form({
                                                        server: server.id,
                                                        move: move.id,
                                                    })}
                                                    destructive
                                                />
                                            )}
                                        </div>
                                    </div>
                                </>
                            )
                        )}
                    </>
                )}

                {!open && server.isControlPlane && (
                    <div className="flex flex-col items-start gap-3 rounded-xl border bg-card p-5 shadow-card">
                        <p className="max-w-[65ch] type-body">
                            {server.name} runs the dashboard. Moving it moves
                            launchroom itself: the dashboard, every app,
                            database and volume.
                        </p>
                        <Button asChild size="sm">
                            <Link href={moveLaunchroom()}>
                                Move launchroom to a new server
                            </Link>
                        </Button>
                    </div>
                )}

                {!open && !server.isControlPlane && (
                    <>
                        {move !== null && <hr className="border-border" />}

                        <Section
                            title="Which server takes over?"
                            description={`It runs everything that runs on ${server.name} today.`}
                        >
                            {candidates.length === 0 ? (
                                <p className="type-body text-muted-foreground">
                                    No other server is online yet.
                                </p>
                            ) : (
                                <ChoiceTiles
                                    label="Target server"
                                    columns={3}
                                    value={targetId}
                                    onChange={pickTarget}
                                    options={candidates.map((candidate) => ({
                                        value: candidate.id,
                                        label: candidate.name,
                                        description:
                                            describe(candidate).join(', '),
                                        media: (
                                            <DeviceImage
                                                device={candidate.device}
                                                size={110}
                                            />
                                        ),
                                    }))}
                                    other={
                                        canEnroll
                                            ? {
                                                  label: 'Add a new server',
                                                  description:
                                                      'It shows up here once it’s online.',
                                                  onSelect: () =>
                                                      router.visit(create()),
                                              }
                                            : undefined
                                    }
                                />
                            )}
                        </Section>

                        {plan !== null && plan.target === null && (
                            <>
                                {plan.blockers.length > 0 ? (
                                    <MovePlanView plan={plan} />
                                ) : (
                                    <p className="type-body text-muted-foreground">
                                        Pick a server to see what moves.
                                    </p>
                                )}
                            </>
                        )}

                        {plan !== null && plan.target !== null && (
                            <>
                                <MovePlanView plan={plan} />

                                {plan.dns.length > 0 && (
                                    <Section
                                        title="DNS"
                                        description={`Once ${plan.source.name} is retired, these records must point at the addresses shown. Lower their TTL now so the switch is fast.`}
                                    >
                                        <DnsRecords
                                            records={plan.dns}
                                            live={dns}
                                        />
                                    </Section>
                                )}

                                <div className="flex flex-col gap-5 rounded-xl border bg-card p-5 shadow-card">
                                    {plan.canBackUpFirst && (
                                        <div className="flex items-start gap-3">
                                            <Checkbox
                                                id="backup-first"
                                                className="mt-0.5"
                                                checked={backupFirst}
                                                onCheckedChange={(checked) =>
                                                    setBackupFirst(
                                                        checked === true,
                                                    )
                                                }
                                            />
                                            <Label
                                                htmlFor="backup-first"
                                                className="flex flex-col gap-0.5 font-normal"
                                            >
                                                <span className="type-body font-medium">
                                                    Back up each database and
                                                    volume first
                                                </span>
                                                <span className="type-small text-muted-foreground">
                                                    Takes a fresh backup right
                                                    before each one stops.
                                                    Optional, since the move
                                                    copies data directly.
                                                </span>
                                            </Label>
                                        </div>
                                    )}
                                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
                                        <p className="flex min-w-0 flex-1 flex-wrap items-center gap-2 type-small text-muted-foreground">
                                            <span>{server.name}</span>
                                            <ArrowRight
                                                aria-hidden="true"
                                                className="size-3.5"
                                            />
                                            <span>{plan.target.name}</span>
                                            <MetaDivider />
                                            <span>
                                                {plan.downtimeSeconds === 0
                                                    ? 'No downtime'
                                                    : `Downtime ${formatDuration(plan.downtimeSeconds)}`}
                                            </span>
                                        </p>
                                        <TypedConfirm
                                            trigger="Start the move"
                                            title={`Move ${server.name} to ${plan.target.name}?`}
                                            description={
                                                <>
                                                    <span>
                                                        Apps without data move
                                                        first, without downtime.
                                                        Then each database and
                                                        volume stops briefly
                                                        while it is copied.
                                                    </span>
                                                    <span>
                                                        {server.name} keeps
                                                        running until you retire
                                                        it.
                                                    </span>
                                                </>
                                            }
                                            expected={server.name}
                                            confirmLabel="Start the move"
                                            form={store.form(server.id)}
                                            data={{
                                                target: plan.target.id,
                                                backupFirst,
                                            }}
                                            disabled={plan.blockers.length > 0}
                                        />
                                    </div>
                                </div>
                            </>
                        )}

                        <div>
                            <Button asChild variant="ghost" size="sm">
                                <Link href={show(server.id)}>
                                    Back to {server.name}
                                </Link>
                            </Button>
                        </div>
                    </>
                )}
            </Page>
        </>
    );
}

MoveServer.layout = {
    breadcrumbs: [{ title: 'Servers', href: index() }],
};
